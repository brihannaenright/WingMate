<?php
include __DIR__ . '/../../includes/session.php';
require_once __DIR__ . '/../../config/config.php';

wingmate_start_secure_session();

// Gets current user ID
$current_user_id = $_SESSION['user_id'] ?? null;

// Redirects to login if not logged in
if (!$current_user_id) {
    http_response_code(401);
    echo json_encode(['error' => 'Unauthorized']);
    exit;
}

$action = $_GET['action'] ?? $_POST['action'] ?? null;

// Get chat ID between two users
if ($action === 'get_chat_id') {
    $friend_id = (int) ($_GET['friend_id'] ?? 0);
    
    if (!$friend_id) {
        http_response_code(400);
        echo json_encode(['error' => 'friend_id required']);
        exit;
    }

    // Verify direct chat exists
    $stmt = $conn->prepare("
        SELECT c.chat_id
        FROM Chats c
        WHERE c.chat_type = 'direct'
        AND EXISTS (
            SELECT 1 FROM Chat_Members cm
            WHERE cm.chat_id = c.chat_id AND cm.user_id = ?
        )
        AND EXISTS (
            SELECT 1 FROM Chat_Members cm
            WHERE cm.chat_id = c.chat_id AND cm.user_id = ?
        )
        AND (
            SELECT COUNT(*)
            FROM Chat_Members cm
            WHERE cm.chat_id = c.chat_id
        ) = 2
        LIMIT 1
    ");

    $stmt->bind_param('ii', $current_user_id, $friend_id);
    $stmt->execute();
    $result = $stmt->get_result();
    
    if ($result->num_rows > 0) {
        $chat = $result->fetch_assoc();
        echo json_encode(['chat_id' => $chat['chat_id']]);
    } else {
        http_response_code(404);
        echo json_encode(['error' => 'Chat not found']);
    }
    $stmt->close();
    exit;
}

// Get messages for a chat
if ($action === 'get_messages') {
    $chat_id = (int) ($_GET['chat_id'] ?? 0);
    $limit = (int) ($_GET['limit'] ?? 50);
    
    if (!$chat_id || $limit <= 0) {
        http_response_code(400);
        echo json_encode(['error' => 'Invalid parameters']);
        exit;
    }

    // Verify user is part of this chat (via friendship)
    $stmt = $conn->prepare("
        SELECT m.message_id, m.sender_id, u.user_id, up.first_name, up.last_name,
               m.content, m.sent_at, m.is_removed
        FROM Messages m
        JOIN Users u ON m.sender_id = u.user_id
        JOIN User_Profile up ON u.user_id = up.user_id
        WHERE m.chat_id = ?
        ORDER BY m.sent_at DESC
        LIMIT ?
    ");
    $stmt->bind_param('ii', $chat_id, $limit);
    $stmt->execute();
    $messages = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
    $stmt->close();

    // Reverse to show chronological order
    echo json_encode(['messages' => array_reverse($messages)]);
    exit;
}

// Send a message
if ($action === 'send_message' && $_SERVER['REQUEST_METHOD'] === 'POST') {
    $chat_id = (int) ($_POST['chat_id'] ?? 0);
    $content = trim($_POST['content'] ?? '');
    
    if (!$chat_id || empty($content)) {
        http_response_code(400);
        echo json_encode(['error' => 'chat_id and content required']);
        exit;
    }

    // Prevent phone number patterns (requirement)
    if (preg_match('/\d{3}[-.\s]?\d{3}[-.\s]?\d{4}/', $content)) {
        http_response_code(400);
        echo json_encode(['error' => 'Phone numbers are not allowed']);
        exit;
    }

    $stmt = $conn->prepare("
        INSERT INTO Messages (chat_id, sender_id, content, sent_at)
        VALUES (?, ?, ?, UTC_TIMESTAMP())
    ");
    $stmt->bind_param('iis', $chat_id, $current_user_id, $content);
    
    if ($stmt->execute()) {
        echo json_encode(['success' => true, 'message_id' => $stmt->insert_id]);
    } else {
        http_response_code(500);
        echo json_encode(['error' => 'Failed to send message']);
    }
    $stmt->close();
    exit;
}

// Report a user
if ($action === 'report_user' && $_SERVER['REQUEST_METHOD'] === 'POST') {

    $reporter_id = (int) ($current_user_id ?? 0);
    $reported_id = (int) (clean_input($_POST['reported_id'] ?? 0));
    $reason = clean_input($_POST['reason'] ?? '');
    $details = clean_input($_POST['details'] ?? '');
    $message_id = !empty($_POST['message_id']) ? (int) $_POST['message_id'] : null;

    if (!$reported_id || empty($reason) || empty($details)) {
        http_response_code(400);
        echo json_encode(['error' => 'reported_id, reason, and details are required']);
        exit;
    }

    // Prevent self-reporting
    if ($reporter_id === $reported_id) {
        http_response_code(400);
        echo json_encode(['error' => 'You cannot report yourself']);
        exit;
    }

    // Limit details to 50 words
    $wordCount = str_word_count($details);
    if ($wordCount > 50) {
        http_response_code(400);
        echo json_encode(['error' => 'Details exceed 50 words']);
        exit;
    }

    // Validate message_id if provided
    if ($message_id) {
        $msgStmt = $conn->prepare("
            SELECT m.message_id 
            FROM Messages m
            WHERE m.message_id = ? 
            AND m.sender_id = ?
            LIMIT 1
        ");
        $msgStmt->bind_param("ii", $message_id, $reported_id);
        $msgStmt->execute();
        $msgResult = $msgStmt->get_result();

        if ($msgResult->num_rows === 0) {
            http_response_code(400);
            echo json_encode(['error' => 'Invalid message or message not from reported user']);
            $msgStmt->close();
            exit;
        }
        $msgStmt->close();
    }

    // Prevent duplicate spam reports (optional but recommended)
    $checkStmt = $conn->prepare("
        SELECT report_id 
        FROM User_Reports 
        WHERE reporter_id = ? 
        AND reported_id = ? 
        AND report_status = 'open'
        LIMIT 1
    ");
    $checkStmt->bind_param("ii", $reporter_id, $reported_id);
    $checkStmt->execute();
    $checkResult = $checkStmt->get_result();

    if ($checkResult->num_rows > 0) {
        echo json_encode(['error' => 'You already have an open report for this user']);
        $checkStmt->close();
        exit;
    }
    $checkStmt->close();

    // Insert report
    $stmt = $conn->prepare("
        INSERT INTO User_Reports 
        (reporter_id, reported_id, reason, details, message_id, report_status, created_at)
        VALUES (?, ?, ?, ?, ?, 'open', UTC_TIMESTAMP())
    ");

    $stmt->bind_param("iissi", $reporter_id, $reported_id, $reason, $details, $message_id);

    if ($stmt->execute()) {
        echo json_encode([
            'success' => true,
            'report_id' => $stmt->insert_id
        ]);
    } else {
        http_response_code(500);
        echo json_encode(['error' => 'Failed to submit report']);
    }

    $stmt->close();
    exit;
}

// Remove friend
if ($action === 'remove_friend' && $_SERVER['REQUEST_METHOD'] === 'POST') {
    $user_id = (int) ($current_user_id ?? 0);
    $friend_id = (int) ($_POST['friend_id'] ?? 0);

    if (!$friend_id || !$user_id) {
        http_response_code(400);
        echo json_encode(['error' => 'Missing required parameters']);
        exit;
    }

    if ($user_id === $friend_id) {
        http_response_code(400);
        echo json_encode(['error' => 'Cannot remove yourself']);
        exit;
    }

    // Delete friendship
    $stmt = $conn->prepare("
        DELETE FROM Friendship 
        WHERE (user_id = ? AND friend_id = ?) OR (user_id = ? AND friend_id = ?)
    ");
    $stmt->bind_param("iiii", $user_id, $friend_id, $friend_id, $user_id);

    if ($stmt->execute()) {
        // Send notification to the friend
        $notificationStmt = $conn->prepare("
            INSERT INTO Notifications (recipient_id, sender_id, reference_type, reference_id, message, notification_type, created_at)
            VALUES (?, ?, 'friendship', ?, ?, 'friend_removed', UTC_TIMESTAMP())
        ");
        $message = 'removed you as a friend';
        $notificationStmt->bind_param("iiss", $friend_id, $user_id, $user_id, $message);
        $notificationStmt->execute();
        $notificationStmt->close();

        echo json_encode(['success' => true]);
    } else {
        http_response_code(500);
        echo json_encode(['error' => 'Failed to remove friend']);
    }

    $stmt->close();
    exit;
}

// Block user
if ($action === 'block_user' && $_SERVER['REQUEST_METHOD'] === 'POST') {
    $blocker_id = (int) ($current_user_id ?? 0);
    $blocked_id = (int) ($_POST['blocked_id'] ?? 0);

    if (!$blocked_id || !$blocker_id) {
        http_response_code(400);
        echo json_encode(['error' => 'Missing required parameters']);
        exit;
    }

    if ($blocker_id === $blocked_id) {
        http_response_code(400);
        echo json_encode(['error' => 'Cannot block yourself']);
        exit;
    }

    // Start transaction
    $conn->begin_transaction();

    try {
        // Check if already blocked
        $checkStmt = $conn->prepare("
            SELECT * FROM User_Blocks 
            WHERE blocker_id = ? AND blocked_id = ?
            LIMIT 1
        ");
        $checkStmt->bind_param("ii", $blocker_id, $blocked_id);
        $checkStmt->execute();
        $checkResult = $checkStmt->get_result();

        if ($checkResult->num_rows > 0) {
            http_response_code(400);
            echo json_encode(['error' => 'User already blocked']);
            $checkStmt->close();
            exit;
        }
        $checkStmt->close();

        // Insert block record
        $blockStmt = $conn->prepare("
            INSERT INTO User_Blocks (blocker_id, blocked_id, created_at)
            VALUES (?, ?, UTC_TIMESTAMP())
        ");
        $blockStmt->bind_param("ii", $blocker_id, $blocked_id);
        $blockStmt->execute();
        $blockStmt->close();

        // Update friendship status to 'removed'
        $friendStmt = $conn->prepare("
            UPDATE Friendship 
            SET status = 'removed' 
            WHERE (user_id = ? AND friend_id = ?) OR (user_id = ? AND friend_id = ?)
        ");
        $friendStmt->bind_param("iiii", $blocker_id, $blocked_id, $blocked_id, $blocker_id);
        $friendStmt->execute();
        $friendStmt->close();

        // Send notification to the blocked user
        $notificationStmt = $conn->prepare("
            INSERT INTO Notifications (recipient_id, sender_id, reference_type, reference_id, message, notification_type, created_at)
            VALUES (?, ?, 'block', ?, ?, 'user_blocked', UTC_TIMESTAMP())
        ");
        $message = 'blocked you';
        $notificationStmt->bind_param("iiss", $blocked_id, $blocker_id, $blocker_id, $message);
        $notificationStmt->execute();
        $notificationStmt->close();

        $conn->commit();
        echo json_encode(['success' => true]);
    } catch (Exception $e) {
        $conn->rollback();
        http_response_code(500);
        echo json_encode(['error' => 'Failed to block user']);
    }

    exit;
}

function clean_input($data) {
  $data = trim($data);
  $data = stripslashes($data);
  $data = htmlspecialchars($data, ENT_QUOTES, 'UTF-8');
  return $data;
}

http_response_code(400);
echo json_encode(['error' => 'Invalid action']);
?>

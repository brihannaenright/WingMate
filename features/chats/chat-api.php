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

    // Verify user has access to this chat
    // Check if it's a group chat first
    $chatTypeStmt = $conn->prepare("SELECT chat_type FROM Chats WHERE chat_id = ?");
    $chatTypeStmt->bind_param('i', $chat_id);
    $chatTypeStmt->execute();
    $chatTypeResult = $chatTypeStmt->get_result();
    
    if ($chatTypeResult->num_rows === 0) {
        http_response_code(404);
        echo json_encode(['error' => 'Chat not found']);
        $chatTypeStmt->close();
        exit;
    }
    
    $chatType = $chatTypeResult->fetch_assoc()['chat_type'];
    $chatTypeStmt->close();
    
    // Verify user access based on chat type
    if ($chatType === 'group') {
        // For group chats, verify user is a member
        $accessStmt = $conn->prepare("
            SELECT chat_id FROM Chat_Members 
            WHERE chat_id = ? AND user_id = ? AND left_at IS NULL
        ");
        $accessStmt->bind_param('ii', $chat_id, $current_user_id);
        $accessStmt->execute();
        if ($accessStmt->get_result()->num_rows === 0) {
            http_response_code(403);
            echo json_encode(['error' => 'Access denied']);
            $accessStmt->close();
            exit;
        }
        $accessStmt->close();
    }

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

// Get group chat details
if ($action === 'get_group_chat') {
    $group_id = (int) ($_GET['group_id'] ?? 0);
    
    if (!$group_id) {
        http_response_code(400);
        echo json_encode(['error' => 'group_id required']);
        exit;
    }

    // Verify user is member of this group
    $stmt = $conn->prepare("
        SELECT c.chat_id, c.group_name, c.created_by
        FROM Chats c
        JOIN Chat_Members cm ON c.chat_id = cm.chat_id
        WHERE c.chat_id = ? AND cm.user_id = ? AND c.chat_type = 'group'
    ");
    $stmt->bind_param('ii', $group_id, $current_user_id);
    $stmt->execute();
    $result = $stmt->get_result();
    
    if ($result->num_rows === 0) {
        http_response_code(404);
        echo json_encode(['error' => 'Group not found']);
        $stmt->close();
        exit;
    }

    $group = $result->fetch_assoc();
    $stmt->close();

    echo json_encode([
        'chat_id' => $group['chat_id'],
        'is_creator' => ($group['created_by'] == $current_user_id)
    ]);
    exit;
}

// Get group members
if ($action === 'get_group_members') {
    $group_id = (int) ($_GET['group_id'] ?? 0);
    
    if (!$group_id) {
        http_response_code(400);
        echo json_encode(['error' => 'group_id required']);
        exit;
    }

    // Verify user is member of this group
    $verifyStmt = $conn->prepare("
        SELECT cm.chat_id FROM Chat_Members cm
        JOIN Chats c ON cm.chat_id = c.chat_id
        WHERE c.chat_id = ? AND cm.user_id = ? AND c.chat_type = 'group' AND cm.left_at IS NULL
    ");
    $verifyStmt->bind_param('ii', $group_id, $current_user_id);
    $verifyStmt->execute();
    
    if ($verifyStmt->get_result()->num_rows === 0) {
        http_response_code(403);
        echo json_encode(['error' => 'Access denied']);
        $verifyStmt->close();
        exit;
    }
    $verifyStmt->close();

    // Fetch group members
    $stmt = $conn->prepare("
        SELECT u.user_id, up.first_name, up.last_name
        FROM Chat_Members cm
        JOIN Users u ON cm.user_id = u.user_id
        JOIN User_Profile up ON u.user_id = up.user_id
        WHERE cm.chat_id = ? AND cm.left_at IS NULL
        ORDER BY up.first_name ASC
    ");
    $stmt->bind_param('i', $group_id);
    $stmt->execute();
    $members = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
    $stmt->close();

    echo json_encode(['members' => $members]);
    exit;
}

// Kick user from group (creator only)
if ($action === 'kick_user' && $_SERVER['REQUEST_METHOD'] === 'POST') {
    $group_id = (int) ($_POST['group_id'] ?? 0);
    $member_id = (int) ($_POST['member_id'] ?? 0);

    if (!$group_id || !$member_id) {
        http_response_code(400);
        echo json_encode(['error' => 'group_id and member_id required']);
        exit;
    }

    // Verify current user is creator
    $creatorStmt = $conn->prepare("SELECT created_by FROM Chats WHERE chat_id = ? AND chat_type = 'group'");
    $creatorStmt->bind_param('i', $group_id);
    $creatorStmt->execute();
    $creatorResult = $creatorStmt->get_result();

    if ($creatorResult->num_rows === 0) {
        http_response_code(404);
        echo json_encode(['error' => 'Group not found']);
        $creatorStmt->close();
        exit;
    }

    $group = $creatorResult->fetch_assoc();
    $creatorStmt->close();

    if ($group['created_by'] != $current_user_id) {
        http_response_code(403);
        echo json_encode(['error' => 'Only group creator can kick members']);
        exit;
    }

    // Cannot kick creator
    if ($member_id === $group['created_by']) {
        http_response_code(400);
        echo json_encode(['error' => 'Cannot kick group creator']);
        exit;
    }

    // Mark member as left
    $stmt = $conn->prepare("
        UPDATE Chat_Members SET left_at = UTC_TIMESTAMP()
        WHERE chat_id = ? AND user_id = ?
    ");
    $stmt->bind_param('ii', $group_id, $member_id);

    if ($stmt->execute()) {
        echo json_encode(['success' => true]);
    } else {
        http_response_code(500);
        echo json_encode(['error' => 'Failed to remove member']);
    }
    $stmt->close();
    exit;
}

// Leave a group
if ($action === 'leave_group' && $_SERVER['REQUEST_METHOD'] === 'POST') {
    $group_id = (int) ($_POST['group_id'] ?? 0);

    if (!$group_id) {
        http_response_code(400);
        echo json_encode(['error' => 'group_id required']);
        exit;
    }

    // Verify user is member and not creator
    $stmt = $conn->prepare("
        SELECT c.created_by FROM Chats c
        JOIN Chat_Members cm ON c.chat_id = cm.chat_id
        WHERE c.chat_id = ? AND cm.user_id = ? AND c.chat_type = 'group'
    ");
    $stmt->bind_param('ii', $group_id, $current_user_id);
    $stmt->execute();
    $result = $stmt->get_result();

    if ($result->num_rows === 0) {
        http_response_code(404);
        echo json_encode(['error' => 'Group not found']);
        $stmt->close();
        exit;
    }

    $group = $result->fetch_assoc();
    $stmt->close();

    if ($group['created_by'] == $current_user_id) {
        http_response_code(403);
        echo json_encode(['error' => 'Creator cannot leave - delete the group instead']);
        exit;
    }

    // Mark user as left
    $stmt = $conn->prepare("
        UPDATE Chat_Members SET left_at = UTC_TIMESTAMP()
        WHERE chat_id = ? AND user_id = ?
    ");
    $stmt->bind_param('ii', $group_id, $current_user_id);

    if ($stmt->execute()) {
        echo json_encode(['success' => true]);
    } else {
        http_response_code(500);
        echo json_encode(['error' => 'Failed to leave group']);
    }
    $stmt->close();
    exit;
}

// Delete a group (creator only)
if ($action === 'delete_group' && $_SERVER['REQUEST_METHOD'] === 'POST') {
    $group_id = (int) ($_POST['group_id'] ?? 0);

    if (!$group_id) {
        http_response_code(400);
        echo json_encode(['error' => 'group_id required']);
        exit;
    }

    // Verify user is creator
    $stmt = $conn->prepare("SELECT created_by FROM Chats WHERE chat_id = ? AND chat_type = 'group'");
    if (!$stmt) {
        http_response_code(500);
        echo json_encode(['error' => 'Database error: ' . $conn->error]);
        exit;
    }
    
    $stmt->bind_param('i', $group_id);
    $stmt->execute();
    $result = $stmt->get_result();

    if ($result->num_rows === 0) {
        http_response_code(404);
        echo json_encode(['error' => 'Group not found']);
        $stmt->close();
        exit;
    }

    $group = $result->fetch_assoc();
    $stmt->close();

    if ($group['created_by'] != $current_user_id) {
        http_response_code(403);
        echo json_encode(['error' => 'Only creator can delete group']);
        exit;
    }

    // Delete the group (and all related data)
    try {
        // Disable foreign key checks temporarily
        $conn->query("SET FOREIGN_KEY_CHECKS=0");
        
        $conn->begin_transaction();

        // Delete messages first
        $stmt = $conn->prepare("DELETE FROM Messages WHERE chat_id = ?");
        if (!$stmt) {
            throw new Exception("Prepare failed for Messages delete: " . $conn->error);
        }
        $stmt->bind_param('i', $group_id);
        if (!$stmt->execute()) {
            throw new Exception("Delete Messages failed: " . $stmt->error);
        }
        $stmt->close();

        // Delete chat members
        $stmt = $conn->prepare("DELETE FROM Chat_Members WHERE chat_id = ?");
        if (!$stmt) {
            throw new Exception("Prepare failed for Chat_Members delete: " . $conn->error);
        }
        $stmt->bind_param('i', $group_id);
        if (!$stmt->execute()) {
            throw new Exception("Delete Chat_Members failed: " . $stmt->error);
        }
        $stmt->close();

        // Delete the group
        $stmt = $conn->prepare("DELETE FROM Chats WHERE chat_id = ?");
        if (!$stmt) {
            throw new Exception("Prepare failed for Chats delete: " . $conn->error);
        }
        $stmt->bind_param('i', $group_id);
        if (!$stmt->execute()) {
            throw new Exception("Delete Chats failed: " . $stmt->error);
        }
        $stmt->close();

        $conn->commit();
        
        // Re-enable foreign key checks
        $conn->query("SET FOREIGN_KEY_CHECKS=1");
        
        echo json_encode(['success' => true]);
    } catch (Exception $e) {
        $conn->rollback();
        // Re-enable foreign key checks
        $conn->query("SET FOREIGN_KEY_CHECKS=1");
        
        http_response_code(500);
        echo json_encode(['error' => $e->getMessage()]);
    }
    exit;
}

// Create group chat
if ($action === 'create_group' && $_SERVER['REQUEST_METHOD'] === 'POST') {
    $group_name = clean_input($_POST['group_name'] ?? '');
    $member_ids_json = $_POST['member_ids'] ?? '[]';
    $member_ids = json_decode($member_ids_json, true);

    if (!$group_name || empty($member_ids)) {
        http_response_code(400);
        echo json_encode(['error' => 'Group name and members are required']);
        exit;
    }

    // Validate group name length
    if (strlen($group_name) < 2 || strlen($group_name) > 100) {
        http_response_code(400);
        echo json_encode(['error' => 'Group name must be between 2 and 100 characters']);
        exit;
    }

    // Validate all members are actual friends of current user
    $placeholders = implode(',', array_fill(0, count($member_ids), '?'));
    $types = str_repeat('i', count($member_ids));
    
    $stmt = $conn->prepare("
        SELECT COUNT(*) as count FROM Friendship 
        WHERE (user_id = ? OR friend_id = ?) 
        AND ((user_id IN ($placeholders) OR friend_id IN ($placeholders)))
        AND status = 'accepted'
    ");
    
    $params = array_merge([$current_user_id, $current_user_id], $member_ids, $member_ids);
    $paramTypes = 'ii' . $types . $types;
    $stmt->bind_param($paramTypes, ...$params);
    $stmt->execute();
    $result = $stmt->get_result()->fetch_assoc();
    $stmt->close();

    if ($result['count'] != count($member_ids)) {
        http_response_code(400);
        echo json_encode(['error' => 'Invalid members - make sure all are your friends']);
        exit;
    }

    try {
        $conn->begin_transaction();

        // Create group chat
        $stmt = $conn->prepare("
            INSERT INTO Chats (chat_type, group_name, created_by, created_at)
            VALUES ('group', ?, ?, UTC_TIMESTAMP())
        ");
        $group_type = 'group';
        $stmt->bind_param('si', $group_name, $current_user_id);
        $stmt->execute();
        $chat_id = $stmt->insert_id;
        $stmt->close();

        // Add creator as admin
        $stmt = $conn->prepare("
            INSERT INTO Chat_Members (chat_id, user_id, role, joined_at)
            VALUES (?, ?, 'admin', UTC_TIMESTAMP())
        ");
        $stmt->bind_param('ii', $chat_id, $current_user_id);
        $stmt->execute();
        $stmt->close();

        // Add other members
        $stmt = $conn->prepare("
            INSERT INTO Chat_Members (chat_id, user_id, role, joined_at)
            VALUES (?, ?, 'member', UTC_TIMESTAMP())
        ");
        $stmt->bind_param('ii', $chat_id, $member_id);
        
        foreach ($member_ids as $member_id) {
            $stmt->execute();
        }
        $stmt->close();

        $conn->commit();
        echo json_encode(['success' => true, 'chat_id' => $chat_id]);
    } catch (Exception $e) {
        $conn->rollback();
        http_response_code(500);
        echo json_encode(['error' => 'Failed to create group']);
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

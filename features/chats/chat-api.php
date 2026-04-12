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

    // Verify friendship exists
    $stmt = $conn->prepare("
        SELECT chat_id FROM Chats c
        WHERE c.chat_type = 'friendship'
        AND c.created_by IN (?, ?)
        AND EXISTS (
            SELECT 1 FROM Friendship f
            WHERE (f.user_id = ? AND f.friend_id = ?) 
            OR (f.user_id = ? AND f.friend_id = ?)
        )
        LIMIT 1
    ");
    $stmt->bind_param('iiiiii', $current_user_id, $friend_id, $current_user_id, $friend_id, $friend_id, $current_user_id);
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
        VALUES (?, ?, ?, NOW())
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

http_response_code(400);
echo json_encode(['error' => 'Invalid action']);
?>

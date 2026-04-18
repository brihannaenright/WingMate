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

// Get unread notification count
try {
    $stmt = $conn->prepare("
        SELECT COUNT(*) as unread_count 
        FROM Notifications 
        WHERE recipient_id = ? AND is_read = 0
    ");
    $stmt->bind_param('i', $current_user_id);
    $stmt->execute();
    $result = $stmt->get_result()->fetch_assoc();
    $stmt->close();
    
    echo json_encode(['unread_count' => $result['unread_count']]);
} catch (Exception $e) {
    http_response_code(500);
    echo json_encode(['error' => 'Failed to fetch unread count']);
}
?>


<?php
/**
 * Match API
 * Handles match-specific operations like unmatching
 */

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

$action = $_POST['action'] ?? null;

// Unmatch - remove a match
if ($action === 'unmatch' && $_SERVER['REQUEST_METHOD'] === 'POST') {
    $match_id = (int) ($_POST['match_id'] ?? 0);

    if (!$match_id) {
        http_response_code(400);
        echo json_encode(['error' => 'match_id required']);
        exit;
    }

    // Verify the user is part of this match
    $stmt = $conn->prepare("SELECT user1_id, user2_id FROM Matches WHERE match_id = ?");
    $stmt->bind_param('i', $match_id);
    $stmt->execute();
    $result = $stmt->get_result();
    
    if ($result->num_rows === 0) {
        http_response_code(404);
        echo json_encode(['error' => 'Match not found']);
        $stmt->close();
        exit;
    }

    $match = $result->fetch_assoc();
    $stmt->close();

    // Verify the current user is part of this match
    if ($match['user1_id'] != $current_user_id && $match['user2_id'] != $current_user_id) {
        http_response_code(403);
        echo json_encode(['error' => 'Unauthorized']);
        exit;
    }

    // Get the other user ID for notification
    $other_user_id = ($match['user1_id'] == $current_user_id) ? $match['user2_id'] : $match['user1_id'];

    // Delete the match
    $deleteStmt = $conn->prepare("DELETE FROM Matches WHERE match_id = ?");
    $deleteStmt->bind_param('i', $match_id);

    if ($deleteStmt->execute()) {
        // Send notification to the other user
        $notificationStmt = $conn->prepare("
            INSERT INTO Notifications (recipient_id, sender_id, reference_type, reference_id, message, notification_type, created_at)
            VALUES (?, ?, 'match', ?, ?, 'match_ended', UTC_TIMESTAMP())
        ");
        $message = 'ended the match with you';
        $notificationStmt->bind_param("iiss", $other_user_id, $current_user_id, $current_user_id, $message);
        $notificationStmt->execute();
        $notificationStmt->close();

        echo json_encode(['success' => true]);
    } else {
        http_response_code(500);
        echo json_encode(['error' => 'Failed to unmatch']);
    }

    $deleteStmt->close();
    exit;
}

// If no valid action
http_response_code(400);
echo json_encode(['error' => 'Invalid action']);
exit;

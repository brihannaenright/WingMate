<?php
require_once __DIR__ . '/../../includes/session.php';
require_once __DIR__ . '/../../config/config.php';

wingmate_start_secure_session();

header('Content-Type: application/json');

// Check authentication
$current_user_id = $_SESSION['user_id'] ?? null;
if (!$current_user_id) {
    http_response_code(401);
    echo json_encode(['success' => false, 'error' => 'Not authenticated']);
    exit;
}

$action = $_GET['action'] ?? ($_POST['action'] ?? '');

// Get comments for a profile
if ($action === 'get_comments' && $_SERVER['REQUEST_METHOD'] === 'GET') {
    $profile_owner_id = (int) ($_GET['profile_owner_id'] ?? 0);
    
    if (!$profile_owner_id || $profile_owner_id <= 0) {
        http_response_code(400);
        echo json_encode(['success' => false, 'error' => 'Invalid profile owner ID']);
        exit;
    }
    
    try {
        // Fetch all comments for this profile with commenter info and profile picture
        $stmt = $conn->prepare("
            SELECT 
                fc.comment_id,
                fc.profile_owner_id,
                fc.commenter_id,
                fc.comment_text,
                fc.created_at,
                fc.updated_at,
                up.first_name,
                up.last_name,
                p.photo_url
            FROM Friend_Comments fc
            JOIN Users u ON fc.commenter_id = u.user_id
            JOIN User_Profile up ON u.user_id = up.user_id
            LEFT JOIN User_Pictures p ON u.user_id = p.user_id AND p.is_primary = 1 AND p.is_removed = 0
            WHERE fc.profile_owner_id = ?
            ORDER BY fc.created_at DESC
        ");
        $stmt->bind_param('i', $profile_owner_id);
        $stmt->execute();
        $result = $stmt->get_result();
        $comments = $result->fetch_all(MYSQLI_ASSOC);
        $stmt->close();
        
        // Check if current user has already commented
        $userCommentStmt = $conn->prepare("
            SELECT comment_id FROM Friend_Comments 
            WHERE profile_owner_id = ? AND commenter_id = ?
            LIMIT 1
        ");
        $userCommentStmt->bind_param('ii', $profile_owner_id, $current_user_id);
        $userCommentStmt->execute();
        $userCommentResult = $userCommentStmt->get_result();
        $userHasCommented = $userCommentResult->num_rows > 0;
        $userCommentStmt->close();
        
        echo json_encode([
            'success' => true,
            'comments' => $comments,
            'userHasCommented' => $userHasCommented,
            'currentUserId' => $current_user_id
        ]);
    } catch (Exception $e) {
        http_response_code(500);
        echo json_encode(['success' => false, 'error' => 'Error fetching comments']);
    }
    exit;
}

// Add a new comment
if ($action === 'add_comment' && $_SERVER['REQUEST_METHOD'] === 'POST') {
    $profile_owner_id = (int) ($_POST['profile_owner_id'] ?? 0);
    $comment_text = trim($_POST['comment_text'] ?? '');
    
    if (!$profile_owner_id || $profile_owner_id <= 0) {
        http_response_code(400);
        echo json_encode(['success' => false, 'error' => 'Invalid profile owner ID']);
        exit;
    }
    
    if (empty($comment_text)) {
        http_response_code(400);
        echo json_encode(['success' => false, 'error' => 'Comment cannot be empty']);
        exit;
    }
    
    if (strlen($comment_text) > 500) {
        http_response_code(400);
        echo json_encode(['success' => false, 'error' => 'Comment cannot exceed 500 characters']);
        exit;
    }
    
    if ($current_user_id === $profile_owner_id) {
        http_response_code(400);
        echo json_encode(['success' => false, 'error' => 'You cannot comment on your own profile']);
        exit;
    }
    
    // Check if user is friends with the profile owner
    $friendCheck = $conn->prepare("
        SELECT 1 FROM Friendship 
        WHERE status = 'accepted'
        AND (
            (user_id = ? AND friend_id = ?) OR 
            (user_id = ? AND friend_id = ?)
        )
        LIMIT 1
    ");
    $friendCheck->bind_param('iiii', $current_user_id, $profile_owner_id, $profile_owner_id, $current_user_id);
    $friendCheck->execute();
    $friendResult = $friendCheck->get_result();
    if ($friendResult->num_rows === 0) {
        $friendCheck->close();
        http_response_code(403);
        echo json_encode(['success' => false, 'error' => 'You must be friends to comment']);
        exit;
    }
    $friendCheck->close();
    
    // Check if user already has a comment on this profile
    $existingComment = $conn->prepare("
        SELECT comment_id FROM Friend_Comments 
        WHERE profile_owner_id = ? AND commenter_id = ?
        LIMIT 1
    ");
    $existingComment->bind_param('ii', $profile_owner_id, $current_user_id);
    $existingComment->execute();
    $existingResult = $existingComment->get_result();
    if ($existingResult->num_rows > 0) {
        $existingComment->close();
        http_response_code(400);
        echo json_encode(['success' => false, 'error' => 'You have already commented on this profile']);
        exit;
    }
    $existingComment->close();
    
    try {
        // Sanitize comment text
        $comment_text = htmlspecialchars($comment_text, ENT_QUOTES, 'UTF-8');
        
        $stmt = $conn->prepare("
            INSERT INTO Friend_Comments (profile_owner_id, commenter_id, comment_text, created_at, updated_at)
            VALUES (?, ?, ?, NOW(), NOW())
        ");
        $stmt->bind_param('iis', $profile_owner_id, $current_user_id, $comment_text);
        
        if ($stmt->execute()) {
            $comment_id = $stmt->insert_id;
            $stmt->close();
            
            // Fetch the newly created comment with user info
            $fetchStmt = $conn->prepare("
                SELECT 
                    fc.comment_id,
                    fc.profile_owner_id,
                    fc.commenter_id,
                    fc.comment_text,
                    fc.created_at,
                    fc.updated_at,
                    up.first_name,
                    up.last_name,
                    p.photo_url
                FROM Friend_Comments fc
                JOIN Users u ON fc.commenter_id = u.user_id
                JOIN User_Profile up ON u.user_id = up.user_id
                LEFT JOIN User_Pictures p ON u.user_id = p.user_id AND p.is_primary = 1 AND p.is_removed = 0
                WHERE fc.comment_id = ?
            ");
            $fetchStmt->bind_param('i', $comment_id);
            $fetchStmt->execute();
            $comment = $fetchStmt->get_result()->fetch_assoc();
            $fetchStmt->close();
            
            echo json_encode(['success' => true, 'comment' => $comment]);
        } else {
            throw new Exception('Failed to insert comment');
        }
    } catch (Exception $e) {
        http_response_code(500);
        echo json_encode(['success' => false, 'error' => 'Error adding comment']);
    }
    exit;
}

// Edit a comment
if ($action === 'edit_comment' && $_SERVER['REQUEST_METHOD'] === 'POST') {
    $comment_id = (int) ($_POST['comment_id'] ?? 0);
    $comment_text = trim($_POST['comment_text'] ?? '');
    
    if (!$comment_id || $comment_id <= 0) {
        http_response_code(400);
        echo json_encode(['success' => false, 'error' => 'Invalid comment ID']);
        exit;
    }
    
    if (empty($comment_text)) {
        http_response_code(400);
        echo json_encode(['success' => false, 'error' => 'Comment cannot be empty']);
        exit;
    }
    
    if (strlen($comment_text) > 500) {
        http_response_code(400);
        echo json_encode(['success' => false, 'error' => 'Comment cannot exceed 500 characters']);
        exit;
    }
    
    // Check if comment exists and belongs to current user
    $checkStmt = $conn->prepare("SELECT commenter_id FROM Friend_Comments WHERE comment_id = ?");
    $checkStmt->bind_param('i', $comment_id);
    $checkStmt->execute();
    $checkResult = $checkStmt->get_result();
    $comment = $checkResult->fetch_assoc();
    $checkStmt->close();
    
    if (!$comment) {
        http_response_code(404);
        echo json_encode(['success' => false, 'error' => 'Comment not found']);
        exit;
    }
    
    if ($comment['commenter_id'] !== $current_user_id) {
        http_response_code(403);
        echo json_encode(['success' => false, 'error' => 'You can only edit your own comments']);
        exit;
    }
    
    try {
        // Sanitize comment text
        $comment_text = htmlspecialchars($comment_text, ENT_QUOTES, 'UTF-8');
        
        $stmt = $conn->prepare("
            UPDATE Friend_Comments 
            SET comment_text = ?, updated_at = NOW()
            WHERE comment_id = ?
        ");
        $stmt->bind_param('si', $comment_text, $comment_id);
        
        if ($stmt->execute()) {
            $stmt->close();
            
            // Fetch updated comment
            $fetchStmt = $conn->prepare("
                SELECT 
                    fc.comment_id,
                    fc.profile_owner_id,
                    fc.commenter_id,
                    fc.comment_text,
                    fc.created_at,
                    fc.updated_at,
                    up.first_name,
                    up.last_name,
                    p.photo_url
                FROM Friend_Comments fc
                JOIN Users u ON fc.commenter_id = u.user_id
                JOIN User_Profile up ON u.user_id = up.user_id
                LEFT JOIN User_Pictures p ON u.user_id = p.user_id AND p.is_primary = 1 AND p.is_removed = 0
                WHERE fc.comment_id = ?
            ");
            $fetchStmt->bind_param('i', $comment_id);
            $fetchStmt->execute();
            $updatedComment = $fetchStmt->get_result()->fetch_assoc();
            $fetchStmt->close();
            
            echo json_encode(['success' => true, 'comment' => $updatedComment]);
        } else {
            throw new Exception('Failed to update comment');
        }
    } catch (Exception $e) {
        http_response_code(500);
        echo json_encode(['success' => false, 'error' => 'Error updating comment']);
    }
    exit;
}

// Delete a comment
if ($action === 'delete_comment' && $_SERVER['REQUEST_METHOD'] === 'POST') {
    $comment_id = (int) ($_POST['comment_id'] ?? 0);
    
    if (!$comment_id || $comment_id <= 0) {
        http_response_code(400);
        echo json_encode(['success' => false, 'error' => 'Invalid comment ID']);
        exit;
    }
    
    // Check if comment exists and belongs to current user
    $checkStmt = $conn->prepare("SELECT commenter_id FROM Friend_Comments WHERE comment_id = ?");
    $checkStmt->bind_param('i', $comment_id);
    $checkStmt->execute();
    $checkResult = $checkStmt->get_result();
    $comment = $checkResult->fetch_assoc();
    $checkStmt->close();
    
    if (!$comment) {
        http_response_code(404);
        echo json_encode(['success' => false, 'error' => 'Comment not found']);
        exit;
    }
    
    if ($comment['commenter_id'] !== $current_user_id) {
        http_response_code(403);
        echo json_encode(['success' => false, 'error' => 'You can only delete your own comments']);
        exit;
    }
    
    try {
        $stmt = $conn->prepare("DELETE FROM Friend_Comments WHERE comment_id = ?");
        $stmt->bind_param('i', $comment_id);
        
        if ($stmt->execute()) {
            $stmt->close();
            echo json_encode(['success' => true]);
        } else {
            throw new Exception('Failed to delete comment');
        }
    } catch (Exception $e) {
        http_response_code(500);
        echo json_encode(['success' => false, 'error' => 'Error deleting comment']);
    }
    exit;
}

http_response_code(400);
echo json_encode(['success' => false, 'error' => 'Invalid action']);
?>

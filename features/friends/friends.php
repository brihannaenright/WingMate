<!--Requirements:
1. Complain about harassment
2. Block users
3. Intneral messaging system (must block typing phone numbers) 
4. Secure session handling
5. Input validation and sanitization
6. Protection against SQL injection and XSS
7. No hardcoded credentials
-->

<?php
include __DIR__ . '/../../includes/session.php';
require_once __DIR__ . '/../../config/config.php';

wingmate_start_secure_session();

// Gets current user ID
$current_user_id = $_SESSION['user_id'] ?? null;

// Redirects to login if not loged in
if (!$current_user_id) {
    header('Location: /features/login/login.php');
    exit;
}

include __DIR__ . '/../../includes/nav-header.php';

// Fetch accepted friends
$friends = [];
if ($current_user_id) {
    $stmt = $conn->prepare("
        SELECT u.user_id, up.first_name, up.last_name, p.photo_url
        FROM Friendship f
        JOIN Users u ON (
            (f.user_id = ? AND u.user_id = f.friend_id) OR 
            (f.friend_id = ? AND u.user_id = f.user_id)
        )
        JOIN UserProfile up ON u.user_id = up.user_id
        LEFT JOIN Photos p ON u.user_id = p.user_id AND p.is_primary = 1 AND p.is_removed = 0
        WHERE f.status = 'accepted'
        ORDER BY up.first_name ASC
    ");
    $stmt->bind_param('ii', $current_user_id, $current_user_id);
    $stmt->execute();
    $result = $stmt->get_result();
    $friends = $result->fetch_all(MYSQLI_ASSOC);
    $stmt->close();
}
?>
<link rel="stylesheet" href="./friends.css">
<div class="container-fluid">
    <div class="row">
        <!-- Friends List Sidebar -->
        <div class="col-lg-3 friends-list d-lg-block" id="friendsSidebar">
            <button class="btn btn-light fw-bold w-100 mb-3 d-lg-none" type="button" data-bs-toggle="collapse" 
                    data-bs-target="#sidebarContent" aria-expanded="false" aria-controls="sidebarContent">
                ☰ WingMates
            </button>
            <div class="collapse dont-collapse-lg" id="sidebarContent">
                <div class="friends-container">
                    <div class="d-flex align-items-center justify-content-between gap-2 mb-4">
                        <h2 class="m-0">My WingMates</h2>
                        <button class="add-friend-btn" type="button" data-bs-toggle="modal" data-bs-target="#addFriendModal">+</button>
                    </div>
                    <div class="friends-grid">
                        <?php if (count($friends) > 0): ?>
                            <?php foreach ($friends as $friend): ?>
                                <div class="friend-card">
                                    <div class="friend-image-wrapper">
                                        <?php if ($friend['photo_url']): ?>
                                            <img src="../../assets/images/<?php echo htmlspecialchars($friend['photo_url']); ?>" 
                                                 alt="<?php echo htmlspecialchars($friend['first_name'] . ' ' . $friend['last_name']); ?>" 
                                                 class="friend-image">
                                        <?php else: ?>
                                            <div class="friend-image-placeholder"></div>
                                        <?php endif; ?>
                                    </div>
                                    <p class="friend-name"><?php echo htmlspecialchars($friend['first_name'] . ' ' . $friend['last_name']); ?></p>
                                </div>
                            <?php endforeach; ?>
                        <?php else: ?>
                            <p class="empty-state-message">No friends yet. Add some wingmates!</p>
                        <?php endif; ?>
                    </div>
                </div>    
                <div class="friends-container mt-4">
                    <div class="d-flex align-items-center justify-content-between gap-2 mb-4">
                        <h2 class="m-0">My Groups</h2>
                        <button class="add-friend-btn">+</button>
                    </div>
                    <!-- Groups list items will go here -->
                </div>  
            </div>
        </div>
        
        <div class="col-lg-9">
            <!-- Chat area will go here -->
        </div>
    </div>
</div>

<!-- Add Friend Dialog -->
<div class="modal fade" id="addFriendModal" tabindex="-1">
    <div class="modal-dialog">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title">Add a WingMate</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
            </div>
            <div class="modal-body">
                <input type="text" class="form-control" id="friendSearchInput" placeholder="Search by name...">
                <div id="searchResults" class="mt-3"></div>
            </div>
        </div>
    </div>
</div>

<?php include __DIR__ . '/../../includes/footer.php'; ?>
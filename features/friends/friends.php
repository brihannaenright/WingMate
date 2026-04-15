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
    header('Location: /features/auth/login.php');
    exit;
}

// Handle POST actions
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';

    if ($action === 'send_request' && !empty($_POST['friend_id'])) {
        $friend_id = (int) $_POST['friend_id'];
        $check = $conn->prepare("SELECT user_id FROM Friendship WHERE (user_id = ? AND friend_id = ?) OR (user_id = ? AND friend_id = ?)");
        $check->bind_param('iiii', $current_user_id, $friend_id, $friend_id, $current_user_id);
        $check->execute();
        if ($check->get_result()->num_rows === 0) {
            $stmt = $conn->prepare("INSERT INTO Friendship (user_id, friend_id, status) VALUES (?, ?, 'pending')");
            $stmt->bind_param('ii', $current_user_id, $friend_id);
            $stmt->execute();
            $stmt->close();
        }
        $check->close();
    }

    if ($action === 'accept' && !empty($_POST['sender_id'])) {
        $sender_id = (int) $_POST['sender_id'];
        $stmt = $conn->prepare("UPDATE Friendship SET status = 'accepted' WHERE user_id = ? AND friend_id = ?");
        $stmt->bind_param('ii', $sender_id, $current_user_id);
        $stmt->execute();
        $stmt->close();
    }

    if ($action === 'reject' && !empty($_POST['sender_id'])) {
        $sender_id = (int) $_POST['sender_id'];
        $stmt = $conn->prepare("DELETE FROM Friendship WHERE user_id = ? AND friend_id = ?");
        $stmt->bind_param('ii', $sender_id, $current_user_id);
        $stmt->execute();
        $stmt->close();
    }

    header('Location: /features/friends/friends.php');
    exit;
}

// Handle search (GET so it doesn't trigger the POST redirect)
$searchResults = [];
$searchQuery = '';
if (!empty($_GET['q'])) {
    $searchQuery = trim($_GET['q']);
    $searchParam = '%' . $searchQuery . '%';
    $stmt = $conn->prepare("
        SELECT u.user_id, up.first_name, up.last_name
        FROM Users u
        JOIN User_Profile up ON u.user_id = up.user_id
        WHERE u.user_id != ?
        AND (up.first_name LIKE ? OR up.last_name LIKE ?)
        AND u.user_id NOT IN (
            SELECT CASE WHEN f.user_id = ? THEN f.friend_id ELSE f.user_id END
            FROM Friendship f
            WHERE f.user_id = ? OR f.friend_id = ?
        )
        LIMIT 10
    ");
    $stmt->bind_param('issiii', $current_user_id, $searchParam, $searchParam, $current_user_id, $current_user_id, $current_user_id);
    $stmt->execute();
    $searchResults = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
    $stmt->close();
}

include __DIR__ . '/../../includes/nav-header.php';

// Fetch pending friend requests (sent TO current user)
$pendingRequests = [];
$stmt = $conn->prepare("
    SELECT u.user_id, up.first_name, up.last_name
    FROM Friendship f
    JOIN Users u ON u.user_id = f.user_id
    JOIN User_Profile up ON u.user_id = up.user_id
    WHERE f.friend_id = ? AND f.status = 'pending'
");
$stmt->bind_param('i', $current_user_id);
$stmt->execute();
$pendingRequests = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
$stmt->close();

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
        JOIN User_Profile up ON u.user_id = up.user_id
        LEFT JOIN User_Pictures p ON u.user_id = p.user_id AND p.is_primary = 1 AND p.is_removed = 0
        WHERE f.status = 'accepted'
        ORDER BY up.first_name ASC
    ");
    $stmt->bind_param('ii', $current_user_id, $current_user_id);
    $stmt->execute();
    $result = $stmt->get_result();
    $friends = $result->fetch_all(MYSQLI_ASSOC);
    $stmt->close();
}

$groups = [];
?>
<link rel="stylesheet" href="./chats-sidebar.css">
	<div class="container-fluid">
        <div class="row">
            <div class="col-lg-3 chats-sidebar" id="chatsSidebar">
                <button class="btn btn-light fw-bold w-100 mb-3 d-lg-none" type="button" data-bs-toggle="collapse" 
                    data-bs-target="#sidebarContent">
                    ☰ Chats
                </button>
                <div class="collapse dont-collapse-lg" id="sidebarContent">
                    <!-- Direct Chats -->  
                    <div class="chats-list d-flex flex-column align-items-start justify-content-between mb-4">
                        <div class="list-title d-flex flex-row gap-2 mb-4">
                            <h2 class="title pe-2">My WingMates</h2>
                            <button class="inbox-btn" data-bs-toggle="modal" data-bs-target="#inboxModal">
                                <img src="../../assets/images/inbox-button.svg" alt="Inbox">
                            </button>
                            <button class="add-btn" data-bs-toggle="modal" data-bs-target="#addFriendModal">
                                <img src="../../assets/images/add-button.svg" alt="Add">
                            </button>
                        </div>
                        <div class="chats-grid">
                            <?php if (count($friends) > 0): ?>
                                <?php foreach ($friends as $friend): ?>
                                    <div class="chat-card d-flex flex-row align-items-center gap-3 pb-3" data-user-id="<?php echo (int)$friend['user_id']; ?>" data-friend-name="<?php echo htmlspecialchars($friend['first_name'] . ' ' . $friend['last_name']); ?>">
                                        <div class="profile-image-wrapper">
                                            <?php if ($friend['photo_url']): ?>
                                                <img src="/Uploads/<?php echo htmlspecialchars($friend['photo_url']); ?>"  
                                                    alt="<?php echo htmlspecialchars($friend['first_name'] . ' ' . $friend['last_name']); ?>" 
                                                    class="profile-image">
                                            <?php endif; ?>
                                        </div>
                                        <p class="profile-name"><?php echo htmlspecialchars($friend['first_name'] . ' ' . $friend['last_name']); ?></p>
                                    </div>
                                <?php endforeach; ?>
                            <?php else: ?>
                                <p class="empty-state-message">No friends yet. Add some wingmates!</p>
                            <?php endif; ?>
                        </div>
                    </div>
                    <!-- Groups Chats -->  
                    <div class="chats-list d-flex flex-column align-items-start justify-content-between mb-4">
                        <h2>My Groups</h2>
                        <?php if (count($groups) > 0): ?>
                            <?php foreach ($groups as $group): ?>
                                <div class="chat-card d-flex flex-row align-items-center gap-3" data-group-id="<?php echo (int)$group['group_id']; ?>" data-group-name="<?php echo htmlspecialchars($group['group_name']); ?>">
                                    <!-- Placeholder for group image and name if needed -->
                                </div>
                            <?php endforeach; ?>
                        <?php else: ?>
                            <p class = "empty-state-message">No groups yet. Create or join a group!</p>
                        <?php endif; ?>
                    </div> 
                </div>
            </div>
            <!--Displays the chat -->
            <div class="col-lg-9 d-flex flex-column friends-page-chat">
                <?php include __DIR__ . '/../../features/chats/chat-ui.php'; ?>
            </div> 
        </div>    
	</div>

<!-- Add Friend Dialog -->
<div class="modal fade" id="addFriendModal" tabindex="-1">
    <div class="modal-dialog modal-dialog-centered">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title">Add Friend</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
            </div>
            <div class="modal-body">
                <form method="GET" action="friends.php">
                    <div class="d-flex gap-2">
                        <input type="text" class="form-control" name="q" placeholder="Search by name...">
                        <button type="submit" class="button-secondary">Search</button>
                    </div>
                </form>
                <?php if (!empty($searchResults)): ?>
                    <?php foreach ($searchResults as $result): ?>
                        <div class="d-flex align-items-center justify-content-between mt-2">
                            <span><?php echo htmlspecialchars($result['first_name'] . ' ' . $result['last_name']); ?></span>
                            <form method="POST">
                                <input type="hidden" name="action" value="send_request">
                                <input type="hidden" name="friend_id" value="<?php echo $result['user_id']; ?>">
                                <button type="submit" class="button-primary">Add</button>
                            </form>
                        </div>
                    <?php endforeach; ?>
                <?php elseif (!empty($searchQuery)): ?>
                    <p class="search-result">No users found.</p>
                <?php endif; ?>
            </div>
        </div>
    </div>
</div>

<!-- Inbox Dialog -->
<div class="modal fade" id="inboxModal" tabindex="-1">
    <div class="modal-dialog modal-dialog-centered">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title">Friend Requests</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
            </div>
            <div class="modal-body">
                <?php if (count($pendingRequests) > 0): ?>
                    <?php foreach ($pendingRequests as $request): ?>
                        <div class="d-flex align-items-center justify-content-between mb-2">
                            <span><?php echo htmlspecialchars($request['first_name'] . ' ' . $request['last_name']); ?></span>
                            <div class="d-flex gap-1">
                                <form method="POST">
                                    <input type="hidden" name="action" value="accept">
                                    <input type="hidden" name="sender_id" value="<?php echo $request['user_id']; ?>">
                                    <button type="submit" class="button-secondary">Accept</button>
                                </form>
                                <form method="POST">
                                    <input type="hidden" name="action" value="reject">
                                    <input type="hidden" name="sender_id" value="<?php echo $request['user_id']; ?>">
                                    <button type="submit" class="button-primary">Reject</button>
                                </form>
                            </div>
                        </div>
                    <?php endforeach; ?>
                <?php elseif (count($pendingRequests) === 0): ?>
                    <p class="search-result">No pending friend requests.</p>
                <?php endif; ?>
            </div>
        </div>
    </div>
</div>

<?php include __DIR__ . '/../../includes/footer.php'; ?>

<script>
document.addEventListener('DOMContentLoaded', function() {

    // Initialize ChatManager with current user ID and 'friends' context
    ChatManager.init(<?php echo (int)$current_user_id; ?>, 'friends');

    const chatCards = document.querySelectorAll('.chat-card');

    chatCards.forEach(card => {
        card.addEventListener('click', function() {
            const profileId = this.getAttribute('data-user-id');
            const profileName = this.getAttribute('data-friend-name');
            const profilePicture = this.querySelector('img')?.src || null;

            // Remove active state from all cards
            chatCards.forEach(c => c.classList.remove('active'));
            
            // Add active state to clicked card
            this.classList.add('active');

            // Load chat using ChatManager
            ChatManager.loadChat(profileId, profileName, profilePicture);
        });
    });

    // Reopen modal if there are search results (stops modal from closing on search submit)
    <?php if (!empty($searchQuery)): ?>
        const modal = new bootstrap.Modal(document.getElementById('addFriendModal'));
        modal.show();
    <?php endif; ?>
});
</script>
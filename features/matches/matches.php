<?php
/**
 * Matches Page
 * Displays matched users with chat, report, and block functionality
 * Structure mirrors friends.php for consistency
 */

include __DIR__ . '/../../includes/session.php';
require_once __DIR__ . '/../../config/config.php';

wingmate_start_secure_session();

// Gets current user ID
$current_user_id = $_SESSION['user_id'] ?? null;

// Redirects to login if not logged in
if (!$current_user_id) {
    header('Location: /features/auth/login.php');
    exit;
}

// Handle POST actions
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';

    if ($action === 'unmatch' && !empty($_POST['match_id'])) {
        $match_id = (int) $_POST['match_id'];
        
        // Verify the user is part of this match before deleting
        $stmt = $conn->prepare("SELECT user1_id, user2_id FROM Matches WHERE match_id = ?");
        $stmt->bind_param('i', $match_id);
        $stmt->execute();
        $result = $stmt->get_result();
        
        if ($result->num_rows > 0) {
            $match = $result->fetch_assoc();
            if ($match['user1_id'] == $current_user_id || $match['user2_id'] == $current_user_id) {
                $deleteStmt = $conn->prepare("DELETE FROM Matches WHERE match_id = ?");
                $deleteStmt->bind_param('i', $match_id);
                $deleteStmt->execute();
                $deleteStmt->close();
            }
        }
        $stmt->close();
    }

    header('Location: /features/matches/matches.php');
    exit;
}

include __DIR__ . '/../../includes/nav-header.php';

// Fetch all matches for current user
$matches = [];
if ($current_user_id) {
    $stmt = $conn->prepare("
        SELECT 
            m.match_id,
            CASE 
                WHEN m.user1_id = ? THEN u.user_id 
                ELSE m.user1_id 
            END as user_id,
            up.first_name, 
            up.last_name, 
            p.photo_url,
            m.matched_at
        FROM Matches m
        JOIN Users u ON (
            (m.user1_id = ? AND u.user_id = m.user2_id) OR 
            (m.user2_id = ? AND u.user_id = m.user1_id)
        )
        JOIN User_Profile up ON u.user_id = up.user_id
        LEFT JOIN User_Pictures p ON u.user_id = p.user_id AND p.is_primary = 1 AND p.is_removed = 0
        WHERE m.status = 'active' OR m.status IS NULL
        ORDER BY m.matched_at DESC
    ");
    $stmt->bind_param('iii', $current_user_id, $current_user_id, $current_user_id);
    $stmt->execute();
    $result = $stmt->get_result();
    $matches = $result->fetch_all(MYSQLI_ASSOC);
    $stmt->close();
}

$groups = [];
?>
<link rel="stylesheet" href="./matches.css">
	<div class="container-fluid">
        <div class="row">
            <div class="col-lg-3 chats-sidebar" id="chatsSidebar">
                <button class="btn btn-light fw-bold w-100 mb-3 d-lg-none" type="button" data-bs-toggle="collapse" 
                    data-bs-target="#sidebarContent">
                    ☰ Matches
                </button>
                <div class="collapse dont-collapse-lg" id="sidebarContent">
                    <!-- Matches List -->  
                    <div class="chats-list d-flex flex-column align-items-start justify-content-between mb-4">
                        <div class="list-title d-flex flex-row gap-2 mb-4">
                            <h2 class="title pe-2">My Matches</h2>
                        </div>
                        <div class="chats-grid">
                            <?php if (count($matches) > 0): ?>
                                <?php foreach ($matches as $match): ?>
                                    <div class="chat-card d-flex flex-row align-items-center gap-3 pb-3" 
                                        data-user-id="<?php echo (int)$match['user_id']; ?>" 
                                        data-match-id="<?php echo (int)$match['match_id']; ?>"
                                        data-friend-name="<?php echo htmlspecialchars($match['first_name'] . ' ' . $match['last_name']); ?>">
                                        <div class="profile-image-wrapper">
                                            <?php if ($match['photo_url']): ?>
                                                <img src="/Uploads/<?php echo htmlspecialchars($match['photo_url']); ?>"  
                                                    alt="<?php echo htmlspecialchars($match['first_name'] . ' ' . $match['last_name']); ?>" 
                                                    class="profile-image">
                                            <?php endif; ?>
                                        </div>
                                        <p class="profile-name"><?php echo htmlspecialchars($match['first_name'] . ' ' . $match['last_name']); ?></p>
                                    </div>
                                <?php endforeach; ?>
                            <?php else: ?>
                                <p class="empty-state-message">No matches yet. Keep swiping!</p>
                            <?php endif; ?>
                        </div>
                    </div>
                </div>
            </div>
            <!--Displays the chat -->
            <div class="col-lg-9 d-flex flex-column matches-page-chat">
                <?php include __DIR__ . '/../../features/chats/chat-ui.php'; ?>
            </div> 
        </div>    
	</div>

<?php include __DIR__ . '/../../includes/footer.php'; ?>

<script>
document.addEventListener('DOMContentLoaded', function() {

    // Initialize ChatManager with current user ID and 'matches' context
    ChatManager.init(<?php echo (int)$current_user_id; ?>, 'matches');

    const chatCards = document.querySelectorAll('.chat-card');

    chatCards.forEach(card => {
        card.addEventListener('click', function() {
            const profileId = this.getAttribute('data-user-id');
            const matchId = this.getAttribute('data-match-id');
            const profileName = this.getAttribute('data-friend-name');
            const profilePicture = this.querySelector('img')?.src || null;

            // Remove active state from all cards
            chatCards.forEach(c => c.classList.remove('active'));
            
            // Add active state to clicked card
            this.classList.add('active');

            // Load chat using ChatManager with match context
            ChatManager.loadChat(profileId, profileName, profilePicture, null, matchId);
        });
    });
});
</script>

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

// Check for session idle timeout (30 minutes)
if (!wingmate_check_session_idle_timeout(1800)) {
    header('Location: /features/auth/login.php?reason=session_expired');
    exit;
}

// Gets current user ID
$current_user_id = $_SESSION['user_id'] ?? null;

// Redirects to login if not loged in
if (!$current_user_id) {
    header('Location: /features/auth/login.php');
    exit;
}

// Initialize message variables
$friendsSuccess = '';
$friendsError = '';

// Retrieve any stored messages from session
if (isset($_SESSION['friends_success'])) {
    $friendsSuccess = (string) $_SESSION['friends_success'];
    unset($_SESSION['friends_success']);
}

if (isset($_SESSION['friends_error'])) {
    $friendsError = (string) $_SESSION['friends_error'];
    unset($_SESSION['friends_error']);
}

// Handle POST actions
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    // Validate CSRF token before processing POST requests
    if (!wingmate_validate_csrf_token($_POST['csrf_token'] ?? null)) {
        http_response_code(400);
        $_SESSION['friends_error'] = 'Session validation failed. Please refresh and try again.';
        header('Location: /features/friends/friends.php');
        exit;
    }

    $action = $_POST['action'] ?? '';

    if ($action === 'send_request' && !empty($_POST['friend_id'])) {
        $friend_id = (int) $_POST['friend_id'];
        try {
            $check = $conn->prepare("SELECT user_id FROM Friendship WHERE (user_id = ? AND friend_id = ?) OR (user_id = ? AND friend_id = ?)");
            $check->bind_param('iiii', $current_user_id, $friend_id, $friend_id, $current_user_id);
            $check->execute();
            if ($check->get_result()->num_rows === 0) {
                $stmt = $conn->prepare("INSERT INTO Friendship (user_id, friend_id, status) VALUES (?, ?, 'pending')");
                $stmt->bind_param('ii', $current_user_id, $friend_id);
                $stmt->execute();
                $_SESSION['friends_success'] = 'Friend request sent successfully!';
                $stmt->close();
            } else {
                $_SESSION['friends_error'] = 'You already have a pending request or friendship with this user.';
            }
            $check->close();
        } catch (Exception $e) {
            $_SESSION['friends_error'] = 'An error occurred while sending the friend request. Please try again.';
        }
    }

    if ($action === 'accept' && !empty($_POST['sender_id'])) {
        $sender_id = (int) $_POST['sender_id'];
        try {
            $stmt = $conn->prepare("UPDATE Friendship SET status = 'accepted' WHERE user_id = ? AND friend_id = ?");
            $stmt->bind_param('ii', $sender_id, $current_user_id);
            $stmt->execute();
            $_SESSION['friends_success'] = 'Friend request accepted!';
            $stmt->close();
        } catch (Exception $e) {
            $_SESSION['friends_error'] = 'An error occurred while accepting the friend request. Please try again.';
        }
    }

    if ($action === 'reject' && !empty($_POST['sender_id'])) {
        $sender_id = (int) $_POST['sender_id'];
        try {
            $stmt = $conn->prepare("DELETE FROM Friendship WHERE user_id = ? AND friend_id = ?");
            $stmt->bind_param('ii', $sender_id, $current_user_id);
            $stmt->execute();
            $_SESSION['friends_success'] = 'Friend request rejected.';
            $stmt->close();
        } catch (Exception $e) {
            $_SESSION['friends_error'] = 'An error occurred while rejecting the friend request. Please try again.';
        }
    }

    header('Location: /features/friends/friends.php');
    exit;
}

// Handle search (GET so it doesn't trigger the POST redirect)
$searchResults = [];
$searchQuery = '';
if (!empty($_GET['q'])) {
    $searchQuery = clean_input($_GET['q']);
    // Limit search query to reasonable length to prevent DOS
    if (strlen($searchQuery) > 100) {
        $searchQuery = substr($searchQuery, 0, 100);
    }
    $searchParam = '%' . $searchQuery . '%';
    try {
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
    } catch (Exception $e) {
        $friendsError = 'An error occurred while searching for users. Please try again.';
    }
}

include __DIR__ . '/../../includes/nav-header.php';

// Fetch pending friend requests (sent TO current user)
$pendingRequests = [];
try {
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
} catch (Exception $e) {
    $friendsError = 'An error occurred while loading friend requests.';
    $pendingRequests = [];
}

// Fetch accepted friends
$friends = [];
try {
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
} catch (Exception $e) {
    $friendsError = 'An error occurred while loading friends.';
    $friends = [];
}

// Fetch groups for current user
$groups = [];
try {
    // Fetch all groups
    $stmt = $conn->prepare("
        SELECT c.chat_id, c.group_name, c.created_by
        FROM Chats c
        JOIN Chat_Members cm ON c.chat_id = cm.chat_id
        WHERE c.chat_type = 'group' AND cm.user_id = ? AND cm.left_at IS NULL
        GROUP BY c.chat_id
        ORDER BY c.created_at DESC
    ");
    $stmt->bind_param('i', $current_user_id);
    $stmt->execute();
    $result = $stmt->get_result();
    $groups = $result->fetch_all(MYSQLI_ASSOC);
    $stmt->close();
    
    // Initialize members array for each group
    foreach ($groups as &$group) {
        $group['members'] = [];
    }
    
    // Fetch all members for all groups in a single query (prevents N+1 problem)
    if (!empty($groups)) {
        $groupIds = array_column($groups, 'chat_id');
        $placeholders = implode(',', array_fill(0, count($groupIds), '?'));
        
        $memberStmt = $conn->prepare("
            SELECT cm.chat_id, u.user_id, up.first_name, up.last_name, p.photo_url
            FROM Chat_Members cm
            JOIN Users u ON cm.user_id = u.user_id
            JOIN User_Profile up ON u.user_id = up.user_id
            LEFT JOIN User_Pictures p ON u.user_id = p.user_id AND p.is_primary = 1 AND p.is_removed = 0
            WHERE cm.chat_id IN ($placeholders) AND cm.left_at IS NULL
            ORDER BY cm.chat_id, up.first_name
        ");
        
        $types = str_repeat('i', count($groupIds));
        $memberStmt->bind_param($types, ...$groupIds);
        $memberStmt->execute();
        $allMembers = $memberStmt->get_result()->fetch_all(MYSQLI_ASSOC);
        $memberStmt->close();
        
        // Group members by chat_id and limit to 4 per group
        foreach ($allMembers as $member) {
            $chatId = $member['chat_id'];
            // Find the group and add member (limit to 4)
            foreach ($groups as &$group) {
                if ($group['chat_id'] == $chatId && count($group['members']) < 4) {
                    $group['members'][] = $member;
                    break;
                }
            }
        }
    }
} catch (Exception $e) {
    $friendsError = 'An error occurred while loading groups.';
    $groups = [];
}

function clean_input($data): string
{
    $data = trim($data);
    $data = stripslashes($data);
    $data = htmlspecialchars($data, ENT_QUOTES, 'UTF-8');
    return (string) $data;
}
?>
<link rel="stylesheet" href="./chats-sidebar.css">
	<div class="container-fluid">
        <?php if (!empty($friendsSuccess)): ?>
            <div class="alert alert-success alert-dismissible fade show" role="alert">
                <?php echo htmlspecialchars($friendsSuccess, ENT_QUOTES, 'UTF-8'); ?>
                <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Close"></button>
            </div>
        <?php endif; ?>
        <?php if (!empty($friendsError)): ?>
            <div class="alert alert-danger alert-dismissible fade show" role="alert">
                <?php echo htmlspecialchars($friendsError, ENT_QUOTES, 'UTF-8'); ?>
                <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Close"></button>
            </div>
        <?php endif; ?>
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
                        <div class="list-title d-flex flex-row gap-2 mb-4">
                            <h2 class="title pe-2">My Groups</h2>
                            <button class="add-btn" data-bs-toggle="modal" data-bs-target="#createGroupModal">
                                <img src="../../assets/images/add-button.svg" alt="Create Group">
                            </button>
                        </div>
                        <div class="chats-grid">
                            <?php if (count($groups) > 0): ?>
                                <?php foreach ($groups as $group): ?>
                                    <div class="chat-card d-flex flex-row align-items-center gap-3 pb-3" data-group-id="<?php echo (int)$group['chat_id']; ?>" data-group-name="<?php echo htmlspecialchars($group['group_name']); ?>">
                                        <div class="group-avatar-grid">
                                            <?php $memberCount = count($group['members']); ?>
                                            <?php if ($memberCount > 0): ?>
                                                <?php foreach ($group['members'] as $index => $member): ?>
                                                    <?php if ($index < 4): ?>
                                                        <div class="group-member-avatar" title="<?php echo htmlspecialchars($member['first_name'] . ' ' . $member['last_name']); ?>">
                                                            <?php if ($member['photo_url']): ?>
                                                                <img src="/Uploads/<?php echo htmlspecialchars($member['photo_url']); ?>"  
                                                                    alt="<?php echo htmlspecialchars($member['first_name']); ?>" 
                                                                    class="member-avatar-image">
                                                            <?php else: ?>
                                                                <div class="member-avatar-placeholder">👤</div>
                                                            <?php endif; ?>
                                                        </div>
                                                    <?php endif; ?>
                                                <?php endforeach; ?>
                                                <?php if ($memberCount > 4): ?>
                                                    <div class="group-member-avatar member-count-badge">
                                                        <div class="member-count-text">+<?php echo $memberCount - 4; ?></div>
                                                    </div>
                                                <?php endif; ?>
                                            <?php else: ?>
                                                <div class="group-member-avatar">
                                                    <div class="member-avatar-placeholder">👥</div>
                                                </div>
                                            <?php endif; ?>
                                        </div>
                                        <p class="profile-name"><?php echo htmlspecialchars($group['group_name']); ?></p>
                                    </div>
                                <?php endforeach; ?>
                            <?php else: ?>
                                <p class="empty-state-message">No groups yet. Create or join a group!</p>
                            <?php endif; ?>
                        </div>
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
                                <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars(wingmate_get_csrf_token(), ENT_QUOTES, 'UTF-8'); ?>">
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
                                    <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars(wingmate_get_csrf_token(), ENT_QUOTES, 'UTF-8'); ?>">
                                    <input type="hidden" name="action" value="accept">
                                    <input type="hidden" name="sender_id" value="<?php echo $request['user_id']; ?>">
                                    <button type="submit" class="button-secondary">Accept</button>
                                </form>
                                <form method="POST">
                                    <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars(wingmate_get_csrf_token(), ENT_QUOTES, 'UTF-8'); ?>">
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

<!-- Create Group Dialog -->
<div class="modal fade" id="createGroupModal" tabindex="-1">
    <div class="modal-dialog modal-dialog-centered">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title">Create Group Chat</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
            </div>
            <div class="modal-body">
                <form id="createGroupForm" enctype="multipart/form-data">
                    <input type="hidden" name="csrf_token" id="groupFormCsrfToken" value="">
                    <!-- Group Name -->
                    <div class="mb-3">
                        <label class="form-label">Group Name</label>
                        <input type="text" id="groupName" class="form-control" placeholder="Enter group name" maxlength="100" required>
                        <div id="groupNameError" class="field-error d-none"><span class="error-icon">!</span><span id="groupNameErrorText"></span></div>
                    </div>

                    <!-- Friend Selection -->
                    <div class="mb-3">
                        <label class="form-label">Select Friends</label>
                        <div id="friendsList" class="border rounded p-3" style="max-height: 250px; overflow-y: auto;">
                            <?php if (count($friends) > 0): ?>
                                <?php foreach ($friends as $friend): ?>
                                    <div class="form-check">
                                        <input class="form-check-input friend-checkbox" type="checkbox" value="<?php echo (int)$friend['user_id']; ?>" id="friend<?php echo (int)$friend['user_id']; ?>">
                                        <label class="form-check-label" for="friend<?php echo (int)$friend['user_id']; ?>">
                                            <?php echo htmlspecialchars($friend['first_name'] . ' ' . $friend['last_name']); ?>
                                        </label>
                                    </div>
                                <?php endforeach; ?>
                            <?php else: ?>
                                <p class="text-muted">No friends available. Add friends first!</p>
                            <?php endif; ?>
                        </div>
                        <div id="friendsError" class="field-error d-none"><span class="error-icon">!</span><span id="friendsErrorText"></span></div>
                    </div>

                    <!-- General error message -->
                    <div id="createGroupError" class="alert alert-wingmate d-none" role="alert"></div>
                </form>
            </div>
            <div class="modal-footer">
                <button type="button" class="button-secondary" data-bs-dismiss="modal">Cancel</button>
                <button type="submit" form="createGroupForm" class="button">Create Group</button>
            </div>
        </div>
    </div>
</div>

<?php include __DIR__ . '/../../includes/footer.php'; ?>

<script>
document.addEventListener('DOMContentLoaded', function() {
    // Set CSRF token from DOM for AJAX requests
    const csrfToken = document.querySelector('input[name="csrf_token"]')?.value || '';
    document.getElementById('groupFormCsrfToken').value = csrfToken;

    // Initialize ChatManager with current user ID and 'friends' context
    ChatManager.init(<?php echo (int)$current_user_id; ?>, 'friends');

    const chatCards = document.querySelectorAll('.chat-card');

    chatCards.forEach(card => {
        card.addEventListener('click', function() {
            // Check if it's a friend card or group card
            const profileId = this.getAttribute('data-user-id');
            const groupId = this.getAttribute('data-group-id');
            
            if (profileId) {
                // Friend card
                const profileName = this.getAttribute('data-friend-name');
                const profilePicture = this.querySelector('img')?.src || null;

                // Remove active state from all cards
                chatCards.forEach(c => c.classList.remove('active'));
                
                // Add active state to clicked card
                this.classList.add('active');

                // Load chat using ChatManager
                ChatManager.loadChat(profileId, profileName, profilePicture);
            } else if (groupId) {
                // Group card
                const groupName = this.getAttribute('data-group-name');

                // Remove active state from all cards
                chatCards.forEach(c => c.classList.remove('active'));
                
                // Add active state to clicked card
                this.classList.add('active');

                // Load group chat
                ChatManager.loadGroupChat(groupId, groupName);
            }
        });
    });

    // Create Group Form Handler
    document.getElementById('createGroupForm').addEventListener('submit', function(e) {
        e.preventDefault();

        const groupName = document.getElementById('groupName').value.trim();
        const checkedFriends = Array.from(document.querySelectorAll('.friend-checkbox:checked')).map(cb => parseInt(cb.value));
        const createGroupError = document.getElementById('createGroupError');

        // Validation
        let hasErrors = false;

        // Validate group name
        if (!groupName || groupName.length < 2) {
            document.getElementById('groupNameError').classList.remove('d-none');
            document.getElementById('groupNameErrorText').textContent = 'Group name must be at least 2 characters';
            hasErrors = true;
        } else if (groupName.length > 100) {
            document.getElementById('groupNameError').classList.remove('d-none');
            document.getElementById('groupNameErrorText').textContent = 'Group name must be no more than 100 characters';
            hasErrors = true;
        } else if (!/^[a-zA-Z0-9\s\-_()&.,]+$/.test(groupName)) {
            document.getElementById('groupNameError').classList.remove('d-none');
            document.getElementById('groupNameErrorText').textContent = 'Group name contains invalid characters';
            hasErrors = true;
        } else {
            document.getElementById('groupNameError').classList.add('d-none');
        }

        // Validate at least one friend selected
        if (checkedFriends.length === 0) {
            document.getElementById('friendsError').classList.remove('d-none');
            document.getElementById('friendsErrorText').textContent = 'Please select at least one friend';
            hasErrors = true;
        } else {
            document.getElementById('friendsError').classList.add('d-none');
        }

        if (hasErrors) return;

        // Build FormData for submission
        const formData = new FormData();
        formData.append('csrf_token', csrfToken);
        formData.append('action', 'create_group');
        formData.append('group_name', groupName);
        formData.append('member_ids', JSON.stringify(checkedFriends));

        fetch('/features/chats/chat-api.php', {
            method: 'POST',
            body: formData
        })
        .then(res => res.json())
        .then(data => {
            if (data.success) {
                createGroupError.classList.add('d-none');
                // Close modal and reload
                const modal = bootstrap.Modal.getInstance(document.getElementById('createGroupModal'));
                modal.hide();
                setTimeout(() => {
                    window.location.reload();
                }, 1000);
            } else {
                createGroupError.textContent = data.error || 'Failed to create group';
                createGroupError.classList.remove('d-none');
            }
        })
        .catch(err => {
            console.error(err);
            createGroupError.textContent = 'Error creating group. Please try again.';
            createGroupError.classList.remove('d-none');
        });
    });

    // Reopen modal if there are search results (stops modal from closing on search submit)
    <?php if (!empty($searchQuery)): ?>
        const modal = new bootstrap.Modal(document.getElementById('addFriendModal'));
        modal.show();
    <?php endif; ?>
});
</script>
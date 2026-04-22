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

// Redirects to login if not logged in
if (!$current_user_id) {
    header('Location: /features/auth/login.php');
    exit;
}

// Fetch 50 latest notifications
$notifications = [];
try {
    $stmt = $conn->prepare("
        SELECT n.notification_id, n.sender_id, n.message, n.notification_type, 
               n.is_read, n.created_at, n.reference_type, n.reference_id,
               up.first_name, up.last_name
        FROM Notifications n
        LEFT JOIN Users u ON n.sender_id = u.user_id
        LEFT JOIN User_Profile up ON u.user_id = up.user_id
        WHERE n.recipient_id = ?
        ORDER BY n.created_at DESC
        LIMIT 50
    ");
    $stmt->bind_param('i', $current_user_id);
    $stmt->execute();
    $notifications = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
    $stmt->close();
} catch (Exception $e) {
    $notifications = [];
}

// Count unread notifications
$unread_count = 0;
foreach ($notifications as $notif) {
    if ($notif['is_read'] == 0) {
        $unread_count++;
    }
}

// Mark all unread notifications as read
if (!empty($notifications)) {
    try {
        $stmt = $conn->prepare("
            UPDATE Notifications 
            SET is_read = 1 
            WHERE recipient_id = ? AND is_read = 0
        ");
        $stmt->bind_param('i', $current_user_id);
        $stmt->execute();
        $stmt->close();
    } catch (Exception $e) {
        // Silent fail
    }
}

include __DIR__ . '/../../includes/nav-header.php';
?>

<link rel="stylesheet" href="/features/notifications/notifications.css">

<div class="notifications-container">
    <div class="notifications-header d-flex justify-content-between align-items-center mb-4 pb-4 border-bottom">
        <h1 class="h2 mb-0">Notifications</h1>
        <div>
            <button class="notification-action-btn" id="refreshNotificationsBtn" onclick="location.reload();" style="display: none;">
                New notifications
            </button>
            <?php if ($unread_count > 0): ?>
                <button class="notification-action-btn ms-3" onclick="location.reload();">Mark all as read</button>
            <?php endif; ?>
        </div>
    </div>

    <?php if (empty($notifications)): ?>
        <div class="text-center text-muted py-5">
            <p class="mb-0">No notifications yet</p>
        </div>
    <?php else: ?>
        <div class="d-flex flex-column gap-3">
            <?php foreach ($notifications as $notif): ?>
                <div class="notification-item p-3 border rounded d-flex gap-3 align-items-start <?php echo $notif['is_read'] == 0 ? 'unread' : ''; ?>" 
                     data-notification-id="<?php echo $notif['notification_id']; ?>">
                    
                    <?php if (!empty($notif['first_name']) || !empty($notif['last_name'])): ?>
                        <div class="notification-avatar rounded-circle d-flex align-items-center justify-content-center flex-shrink-0">
                            <?php echo strtoupper(substr($notif['first_name'] ?? '', 0, 1) . substr($notif['last_name'] ?? '', 0, 1)); ?>
                        </div>
                    <?php else: ?>
                        <div class="notification-avatar rounded-circle d-flex align-items-center justify-content-center flex-shrink-0">
                            WM
                        </div>
                    <?php endif; ?>

                    <div class="notification-content flex-grow-1">
                        <div class="notification-message small mb-1">
                            <?php echo htmlspecialchars($notif['message'], ENT_QUOTES, 'UTF-8'); ?>
                        </div>
                        <div class="text-muted small">
                            <?php 
                                $date = new DateTime($notif['created_at']);
                                $now = new DateTime();
                                $interval = $now->diff($date);
                                
                                if ($interval->days == 0) {
                                    if ($interval->h == 0) {
                                        echo $interval->i . ' minutes ago';
                                    } else {
                                        echo $interval->h . ' hours ago';
                                    }
                                } else if ($interval->days == 1) {
                                    echo 'Yesterday';
                                } else if ($interval->days < 7) {
                                    echo $interval->days . ' days ago';
                                } else {
                                    echo $date->format('M d, Y');
                                }
                            ?>
                        </div>
                    </div>

                    <div class="notification-dot flex-shrink-0"></div>
                </div>
            <?php endforeach; ?>
        </div>
    <?php endif; ?>
</div>

<?php include __DIR__ . '/../../includes/footer.php'; ?>

<script>
    function checkForUnreadNotifications() {
        fetch('/features/notifications/notifications-api.php')
            .then(response => response.json())
            .then(data => {
                const refreshButton = document.getElementById('refreshNotificationsBtn');
                if (data.unread_count > 0) {
                    refreshButton.style.display = 'inline-block';
                } else {
                    refreshButton.style.display = 'none';
                }
            })
            .catch(error => console.error('Error fetching unread count:', error));
    }

    // Check for unread notifications every 5 seconds
    document.addEventListener('DOMContentLoaded', function() {
        setInterval(checkForUnreadNotifications, 5000);
    });
</script>

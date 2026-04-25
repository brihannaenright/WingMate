<?php
require_once __DIR__ . '/../../includes/session.php';
require_once __DIR__ . '/../../config/config.php';

wingmate_start_secure_session();

// Block access if user is not logged in or not an admin
wingmate_require_admin();

// Determine which page/section to show (defaults to 'dashboard')
$page = $_GET['page'] ?? 'dashboard';

// Fetch admin user's name for the profile display
$admin_name = 'Admin User';
$stmt = $conn->prepare("SELECT first_name, last_name FROM User_Profile WHERE user_id = ?");
$stmt->bind_param("i", $_SESSION['user_id']);
$stmt->execute();
$admin_result = $stmt->get_result();
if ($admin_result->num_rows > 0) {
    $admin_row = $admin_result->fetch_assoc();
    $admin_name = ($admin_row['first_name'] ?? '') . ' ' . ($admin_row['last_name'] ?? '');
}
$stmt->close();

// Handle POST actions
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!wingmate_validate_csrf_token($_POST['csrf_token'] ?? null)) {
        http_response_code(400);
    } else {
        $action = $_POST['action'] ?? '';
        $target_user_id = (int) ($_POST['user_id'] ?? 0);

        // Prevent admin from modifying their own account
        if ($target_user_id === (int) $_SESSION['user_id']) {
            $_SESSION['admin_error'] = 'You cannot modify your own account.';
            header('Location: /features/admin/admin.php?page=' . urlencode($page));
            exit;
        }

        // Suspend a user for a set number of days
        if ($action === 'suspend' && $target_user_id > 0) {
            $days = (int) ($_POST['suspend_days'] ?? 7);
            if ($days < 1) { $days = 1; }
            if ($days > 365) { $days = 365; }

            $suspended_until = date('Y-m-d H:i:s', strtotime("+$days days"));
            $stmt = $conn->prepare("UPDATE Users SET account_status = 'suspended', suspended_until = ? WHERE user_id = ?");
            $stmt->bind_param("si", $suspended_until, $target_user_id);
            $stmt->execute();
            $stmt->close();
        }

        // Ban a user permanently (account cannot be reused)
        if ($action === 'ban' && $target_user_id > 0) {
            $stmt = $conn->prepare("UPDATE Users SET account_status = 'banned' WHERE user_id = ?");
            $stmt->bind_param("i", $target_user_id);
            $stmt->execute();
            $stmt->close();
        }

        // Reactivate a suspended account
        if ($action === 'unsuspend' && $target_user_id > 0) {
            $stmt = $conn->prepare("UPDATE Users SET account_status = 'active', suspended_until = NULL WHERE user_id = ?");
            $stmt->bind_param("i", $target_user_id);
            $stmt->execute();
            $stmt->close();
        }

        // Resolve a report
        if ($action === 'resolve_report') {
            $report_id = (int) ($_POST['report_id'] ?? 0);
            if ($report_id > 0) {
                $stmt = $conn->prepare("UPDATE User_Reports SET report_status = 'resolved' WHERE report_id = ?");
                $stmt->bind_param("i", $report_id);
                $stmt->execute();
                $stmt->close();
            }
        }

        // Dismiss a report
        if ($action === 'dismiss_report') {
            $report_id = (int) ($_POST['report_id'] ?? 0);
            if ($report_id > 0) {
                $stmt = $conn->prepare("UPDATE User_Reports SET report_status = 'dismissed' WHERE report_id = ?");
                $stmt->bind_param("i", $report_id);
                $stmt->execute();
                $stmt->close();
            }
        }

        // Remove a message (hide it but keep for evidence)
        if ($action === 'remove_message') {
            $message_id = (int) ($_POST['message_id'] ?? 0);
            if ($message_id > 0) {
                $stmt = $conn->prepare("UPDATE Messages SET is_removed = 1 WHERE message_id = ?");
                $stmt->bind_param("i", $message_id);
                $stmt->execute();
                $stmt->close();
            }
        }

        // Edit a user's profile (e.g. change offensive name)
        if ($action === 'edit_profile' && $target_user_id > 0) {
            $new_first_name = trim($_POST['first_name'] ?? '');
            $new_last_name = trim($_POST['last_name'] ?? '');
            if ($new_first_name !== '' && $new_last_name !== '') {
                $stmt = $conn->prepare("UPDATE User_Profile SET first_name = ?, last_name = ? WHERE user_id = ?");
                $stmt->bind_param("ssi", $new_first_name, $new_last_name, $target_user_id);
                $stmt->execute();
                $stmt->close();
            }
        }

        header('Location: /features/admin/admin.php?page=' . urlencode($page));
        exit;
    }
}

// Fetch counts for the inbox sidebar
$report_count = 0;
$stmt = $conn->prepare("SELECT COUNT(*) as cnt FROM User_Reports WHERE report_status = 'open'");
$stmt->execute();
$report_count = $stmt->get_result()->fetch_assoc()['cnt'];
$stmt->close();

$suspended_count = 0;
$stmt = $conn->prepare("SELECT COUNT(*) as cnt FROM Users WHERE account_status = 'suspended' OR account_status = 'banned'");
$stmt->execute();
$suspended_count = $stmt->get_result()->fetch_assoc()['cnt'];
$stmt->close();

// Check for any admin error messages
$adminError = '';
if (isset($_SESSION['admin_error'])) {
    $adminError = (string) $_SESSION['admin_error'];
    unset($_SESSION['admin_error']);
}
?>

<?php include __DIR__ . '/../../includes/auth-header.php'; ?>
<link rel="stylesheet" href="/features/admin/admin.css">

<!-- Admin Navbar -->
<nav class="admin-navbar d-flex justify-content-between align-items-center">
    <div class="admin-navbar-brand">
        <img src="/assets/images/wingmate-navbar.png" alt="WingMate" class="navbar-logo">
    </div>
    <div class="admin-navbar-links d-flex gap-3">
        <a href="/features/admin/admin.php" class="admin-nav-link">Admin Console</a>
        <a href="/features/auth/login.php" class="admin-nav-link">Logout</a>
    </div>
</nav>

<div class="container-fluid">
    <div class="row">
        <!-- Inbox Sidebar -->
        <div class="col-lg-2 admin-sidebar">
            <div class="admin-inbox">
                <p class="inbox-title">Inbox</p>
                <a href="/features/admin/admin.php?page=reports" class="inbox-item"><?php echo (int) $report_count; ?> Open Reports</a>
                <a href="/features/admin/admin.php?page=suspended" class="inbox-item"><?php echo (int) $suspended_count; ?> Suspended/Banned</a>
            </div>
        </div>

        <!-- Main Content -->
        <div class="col-lg-10 admin-content">
            <!-- Error message display -->
            <?php if ($adminError !== ''): ?>
                <p class="admin-error"><?php echo htmlspecialchars($adminError, ENT_QUOTES, 'UTF-8'); ?></p>
            <?php endif; ?>

            <?php if ($page === 'dashboard'): ?>
                <!-- Dashboard Home -->
                <div class="admin-profile d-flex align-items-center gap-3 mb-4">
                    <div class="admin-avatar">
                        <img src="/assets/images/default-avatar.svg" alt="Admin">
                    </div>
                    <div>
                        <p class="admin-profile-name"><?php echo htmlspecialchars($admin_name, ENT_QUOTES, 'UTF-8'); ?></p>
                    </div>
                </div>

                <div class="admin-menu-box">
                    <a href="/features/admin/admin.php?page=reports" class="admin-menu-item">Review Reports</a>
                    <a href="/features/admin/admin.php?page=suspended" class="admin-menu-item">View and Edit Banned or Suspended Users</a>
                    <a href="/features/admin/admin.php?page=users" class="admin-menu-item">Manage All Users</a>
                </div>

            <?php elseif ($page === 'reports'): ?>
                <!-- Review Reports -->
                <p class="admin-page-title">Review Reports</p>

                <?php
                // Fetch open reports with reporter and reported user info
                $reports = [];
                $stmt = $conn->prepare("
                    SELECT r.report_id, r.reason, r.details, r.report_status, r.created_at,
                           reporter_p.first_name AS reporter_first, reporter_p.last_name AS reporter_last,
                           reported_p.first_name AS reported_first, reported_p.last_name AS reported_last,
                           r.reported_id
                    FROM User_Reports r
                    JOIN User_Profile reporter_p ON r.reporter_id = reporter_p.user_id
                    JOIN User_Profile reported_p ON r.reported_id = reported_p.user_id
                    ORDER BY r.report_status ASC, r.created_at DESC
                ");
                $stmt->execute();
                $reports = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
                $stmt->close();
                ?>

                <?php if (count($reports) > 0): ?>
                    <table class="admin-table">
                        <thead>
                            <tr>
                                <th>Reported By</th>
                                <th>Reported User</th>
                                <th>Reason</th>
                                <th>Details</th>
                                <th>Status</th>
                                <th>Date</th>
                                <th>Actions</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($reports as $report): ?>
                                <tr>
                                    <td><?php echo htmlspecialchars($report['reporter_first'] . ' ' . $report['reporter_last'], ENT_QUOTES, 'UTF-8'); ?></td>
                                    <td>
                                        <a href="/features/admin/admin.php?page=user_detail&user_id=<?php echo (int) $report['reported_id']; ?>" class="admin-link">
                                            <?php echo htmlspecialchars($report['reported_first'] . ' ' . $report['reported_last'], ENT_QUOTES, 'UTF-8'); ?>
                                        </a>
                                    </td>
                                    <td><?php echo htmlspecialchars($report['reason'], ENT_QUOTES, 'UTF-8'); ?></td>
                                    <td><?php echo htmlspecialchars($report['details'], ENT_QUOTES, 'UTF-8'); ?></td>
                                    <td>
                                        <span class="status-badge status-<?php echo htmlspecialchars($report['report_status'], ENT_QUOTES, 'UTF-8'); ?>">
                                            <?php echo htmlspecialchars($report['report_status'], ENT_QUOTES, 'UTF-8'); ?>
                                        </span>
                                    </td>
                                    <td><?php echo htmlspecialchars($report['created_at'], ENT_QUOTES, 'UTF-8'); ?></td>
                                    <td>
                                        <?php if ($report['report_status'] === 'open'): ?>
                                            <form method="POST" class="d-inline">
                                                <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars(wingmate_get_csrf_token(), ENT_QUOTES, 'UTF-8'); ?>">
                                                <input type="hidden" name="report_id" value="<?php echo (int) $report['report_id']; ?>">
                                                <button type="submit" name="action" value="resolve_report" class="button-secondary admin-action-btn">Resolve</button>
                                                <button type="submit" name="action" value="dismiss_report" class="admin-action-btn">Dismiss</button>
                                            </form>
                                        <?php endif; ?>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                <?php else: ?>
                    <p class="empty-message">No reports found.</p>
                <?php endif; ?>

            <?php elseif ($page === 'user_detail'): ?>
                <!-- User Detail Page: view profile, messages, and take action -->
                <?php
                $view_user_id = (int) ($_GET['user_id'] ?? 0);
                $view_user = null;
                $user_messages = [];

                if ($view_user_id > 0) {
                    // Fetch user info
                    $stmt = $conn->prepare("
                        SELECT u.user_id, u.email, u.user_type, u.account_status, u.suspended_until, u.created_at,
                               p.first_name, p.last_name
                        FROM Users u
                        LEFT JOIN User_Profile p ON u.user_id = p.user_id
                        WHERE u.user_id = ?
                    ");
                    $stmt->bind_param("i", $view_user_id);
                    $stmt->execute();
                    $view_user = $stmt->get_result()->fetch_assoc();
                    $stmt->close();

                    // Fetch this user's recent messages (including removed ones for admin review)
                    $stmt = $conn->prepare("
                        SELECT m.message_id, m.content, m.sent_at, m.is_removed
                        FROM Messages m
                        WHERE m.sender_id = ?
                        ORDER BY m.sent_at DESC
                        LIMIT 50
                    ");
                    $stmt->bind_param("i", $view_user_id);
                    $stmt->execute();
                    $user_messages = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
                    $stmt->close();
                }
                ?>

                <?php if ($view_user): ?>
                    <p class="admin-page-title">User Detail</p>

                    <!-- User Info -->
                    <div class="user-detail-card mb-4">
                        <p><strong>Name:</strong> <?php echo htmlspecialchars($view_user['first_name'] . ' ' . $view_user['last_name'], ENT_QUOTES, 'UTF-8'); ?></p>
                        <p><strong>Email:</strong> <?php echo htmlspecialchars($view_user['email'], ENT_QUOTES, 'UTF-8'); ?></p>
                        <p><strong>Status:</strong>
                            <span class="status-badge status-<?php echo htmlspecialchars($view_user['account_status'], ENT_QUOTES, 'UTF-8'); ?>">
                                <?php echo htmlspecialchars($view_user['account_status'], ENT_QUOTES, 'UTF-8'); ?>
                            </span>
                        </p>
                        <?php if ($view_user['suspended_until']): ?>
                            <p><strong>Suspended Until:</strong> <?php echo htmlspecialchars($view_user['suspended_until'], ENT_QUOTES, 'UTF-8'); ?></p>
                        <?php endif; ?>
                        <p><strong>Joined:</strong> <?php echo htmlspecialchars($view_user['created_at'], ENT_QUOTES, 'UTF-8'); ?></p>
                    </div>

                    <!-- Edit Profile (e.g. change offensive name) -->
                    <?php if ($view_user['user_type'] !== 'administrator'): ?>
                        <div class="user-detail-card mb-4">
                            <p><strong>Edit Profile</strong></p>
                            <form method="POST" class="d-flex gap-2 align-items-end">
                                <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars(wingmate_get_csrf_token(), ENT_QUOTES, 'UTF-8'); ?>">
                                <input type="hidden" name="action" value="edit_profile">
                                <input type="hidden" name="user_id" value="<?php echo (int) $view_user['user_id']; ?>">
                                <div>
                                    <label class="form-label">First Name</label>
                                    <input type="text" name="first_name" class="form-control" value="<?php echo htmlspecialchars($view_user['first_name'] ?? '', ENT_QUOTES, 'UTF-8'); ?>">
                                </div>
                                <div>
                                    <label class="form-label">Last Name</label>
                                    <input type="text" name="last_name" class="form-control" value="<?php echo htmlspecialchars($view_user['last_name'] ?? '', ENT_QUOTES, 'UTF-8'); ?>">
                                </div>
                                <button type="submit" class="button-secondary admin-action-btn">Save</button>
                            </form>
                        </div>

                        <!-- Account Actions -->
                        <div class="user-detail-card mb-4">
                            <p><strong>Account Actions</strong></p>
                            <form method="POST" class="d-flex gap-2 align-items-end">
                                <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars(wingmate_get_csrf_token(), ENT_QUOTES, 'UTF-8'); ?>">
                                <input type="hidden" name="user_id" value="<?php echo (int) $view_user['user_id']; ?>">

                                <?php if ($view_user['account_status'] === 'active'): ?>
                                    <div>
                                        <label class="form-label">Suspend for (days)</label>
                                        <input type="number" name="suspend_days" class="form-control" value="7" min="1" max="365">
                                    </div>
                                    <button type="submit" name="action" value="suspend" class="button-secondary admin-action-btn">Suspend</button>
                                    <button type="submit" name="action" value="ban" class="admin-action-btn">Ban Permanently</button>

                                <?php elseif ($view_user['account_status'] === 'suspended'): ?>
                                    <button type="submit" name="action" value="unsuspend" class="button-secondary admin-action-btn">Unsuspend</button>
                                    <button type="submit" name="action" value="ban" class="admin-action-btn">Ban Permanently</button>

                                <?php elseif ($view_user['account_status'] === 'banned'): ?>
                                    <p class="admin-error">This account is permanently banned.</p>
                                <?php endif; ?>
                            </form>
                        </div>
                    <?php endif; ?>

                    <!-- User's Messages -->
                    <div class="user-detail-card">
                        <p><strong>Recent Messages</strong></p>
                        <?php if (count($user_messages) > 0): ?>
                            <table class="admin-table">
                                <thead>
                                    <tr>
                                        <th>Message</th>
                                        <th>Sent At</th>
                                        <th>Status</th>
                                        <th>Actions</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php foreach ($user_messages as $msg): ?>
                                        <tr class="<?php echo $msg['is_removed'] ? 'message-removed' : ''; ?>">
                                            <td><?php echo htmlspecialchars($msg['content'], ENT_QUOTES, 'UTF-8'); ?></td>
                                            <td><?php echo htmlspecialchars($msg['sent_at'], ENT_QUOTES, 'UTF-8'); ?></td>
                                            <td>
                                                <?php if ($msg['is_removed']): ?>
                                                    <span class="status-badge status-banned">removed</span>
                                                <?php else: ?>
                                                    <span class="status-badge status-active">visible</span>
                                                <?php endif; ?>
                                            </td>
                                            <td>
                                                <?php if (!$msg['is_removed']): ?>
                                                    <form method="POST" class="d-inline">
                                                        <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars(wingmate_get_csrf_token(), ENT_QUOTES, 'UTF-8'); ?>">
                                                        <input type="hidden" name="action" value="remove_message">
                                                        <input type="hidden" name="message_id" value="<?php echo (int) $msg['message_id']; ?>">
                                                        <button type="submit" class="admin-action-btn">Remove</button>
                                                    </form>
                                                <?php endif; ?>
                                            </td>
                                        </tr>
                                    <?php endforeach; ?>
                                </tbody>
                            </table>
                        <?php else: ?>
                            <p class="empty-message">No messages found.</p>
                        <?php endif; ?>
                    </div>

                <?php else: ?>
                    <p class="admin-error">User not found.</p>
                <?php endif; ?>

            <?php elseif ($page === 'suspended'): ?>
                <!-- View Suspended and Banned Users -->
                <p class="admin-page-title">Suspended & Banned Users</p>

                <?php
                $suspended_users = [];
                $stmt = $conn->prepare("
                    SELECT u.user_id, u.email, u.account_status, u.suspended_until,
                           p.first_name, p.last_name
                    FROM Users u
                    LEFT JOIN User_Profile p ON u.user_id = p.user_id
                    WHERE u.account_status IN ('suspended', 'banned')
                    ORDER BY u.account_status ASC
                ");
                $stmt->execute();
                $suspended_users = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
                $stmt->close();
                ?>

                <?php if (count($suspended_users) > 0): ?>
                    <table class="admin-table">
                        <thead>
                            <tr>
                                <th>Name</th>
                                <th>Email</th>
                                <th>Status</th>
                                <th>Suspended Until</th>
                                <th>Actions</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($suspended_users as $user): ?>
                                <tr>
                                    <td>
                                        <a href="/features/admin/admin.php?page=user_detail&user_id=<?php echo (int) $user['user_id']; ?>" class="admin-link">
                                            <?php echo htmlspecialchars($user['first_name'] . ' ' . $user['last_name'], ENT_QUOTES, 'UTF-8'); ?>
                                        </a>
                                    </td>
                                    <td><?php echo htmlspecialchars($user['email'], ENT_QUOTES, 'UTF-8'); ?></td>
                                    <td>
                                        <span class="status-badge status-<?php echo htmlspecialchars($user['account_status'], ENT_QUOTES, 'UTF-8'); ?>">
                                            <?php echo htmlspecialchars($user['account_status'], ENT_QUOTES, 'UTF-8'); ?>
                                        </span>
                                    </td>
                                    <td><?php echo $user['suspended_until'] ? htmlspecialchars($user['suspended_until'], ENT_QUOTES, 'UTF-8') : 'Permanent'; ?></td>
                                    <td>
                                        <form method="POST" class="d-inline">
                                            <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars(wingmate_get_csrf_token(), ENT_QUOTES, 'UTF-8'); ?>">
                                            <input type="hidden" name="user_id" value="<?php echo (int) $user['user_id']; ?>">
                                            <?php if ($user['account_status'] === 'suspended'): ?>
                                                <button type="submit" name="action" value="unsuspend" class="button-secondary admin-action-btn">Unsuspend</button>
                                            <?php endif; ?>
                                        </form>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                <?php else: ?>
                    <p class="empty-message">No suspended or banned users.</p>
                <?php endif; ?>

            <?php elseif ($page === 'users'): ?>
                <!-- Manage All Users -->
                <p class="admin-page-title">Manage All Users</p>

                <?php
                $users = [];
                $stmt = $conn->prepare("
                    SELECT u.user_id, u.email, u.user_type, u.account_status, u.created_at,
                           p.first_name, p.last_name
                    FROM Users u
                    LEFT JOIN User_Profile p ON u.user_id = p.user_id
                    ORDER BY u.created_at DESC
                ");
                $stmt->execute();
                $users = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
                $stmt->close();
                ?>

                <table class="admin-table">
                    <thead>
                        <tr>
                            <th>Name</th>
                            <th>Email</th>
                            <th>Type</th>
                            <th>Status</th>
                            <th>Joined</th>
                            <th>Actions</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($users as $user): ?>
                            <tr>
                                <td>
                                    <a href="/features/admin/admin.php?page=user_detail&user_id=<?php echo (int) $user['user_id']; ?>" class="admin-link">
                                        <?php echo htmlspecialchars(($user['first_name'] ?? '') . ' ' . ($user['last_name'] ?? ''), ENT_QUOTES, 'UTF-8'); ?>
                                    </a>
                                </td>
                                <td><?php echo htmlspecialchars($user['email'], ENT_QUOTES, 'UTF-8'); ?></td>
                                <td><?php echo htmlspecialchars($user['user_type'], ENT_QUOTES, 'UTF-8'); ?></td>
                                <td>
                                    <span class="status-badge status-<?php echo htmlspecialchars($user['account_status'], ENT_QUOTES, 'UTF-8'); ?>">
                                        <?php echo htmlspecialchars($user['account_status'], ENT_QUOTES, 'UTF-8'); ?>
                                    </span>
                                </td>
                                <td><?php echo htmlspecialchars($user['created_at'], ENT_QUOTES, 'UTF-8'); ?></td>
                                <td>
                                    <?php if ($user['user_type'] !== 'administrator'): ?>
                                        <a href="/features/admin/admin.php?page=user_detail&user_id=<?php echo (int) $user['user_id']; ?>" class="button-secondary admin-action-btn">View</a>
                                    <?php endif; ?>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            <?php endif; ?>
        </div>
    </div>
</div>

<?php include __DIR__ . '/../../includes/footer.php'; ?>

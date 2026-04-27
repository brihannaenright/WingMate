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

        // Edit a user's email (validate format and uniqueness)
        if ($action === 'edit_email' && $target_user_id > 0) {
            $new_email = trim($_POST['email'] ?? '');
            if (!filter_var($new_email, FILTER_VALIDATE_EMAIL)) {
                $_SESSION['admin_error'] = 'Invalid email address.';
            } else {
                $stmt = $conn->prepare("SELECT user_id FROM Users WHERE email = ? AND user_id != ?");
                $stmt->bind_param('si', $new_email, $target_user_id);
                $stmt->execute();
                $taken = $stmt->get_result()->num_rows > 0;
                $stmt->close();

                if ($taken) {
                    $_SESSION['admin_error'] = 'That email is already in use.';
                } else {
                    $stmt = $conn->prepare("UPDATE Users SET email = ? WHERE user_id = ?");
                    $stmt->bind_param('si', $new_email, $target_user_id);
                    $stmt->execute();
                    $stmt->close();
                }
            }
        }

        // Delete a profile comment (Friend_Comments has no soft-delete flag, so hard DELETE)
        if ($action === 'delete_comment') {
            $comment_id = (int) ($_POST['comment_id'] ?? 0);
            if ($comment_id > 0) {
                $stmt = $conn->prepare("DELETE FROM Friend_Comments WHERE comment_id = ?");
                $stmt->bind_param('i', $comment_id);
                $stmt->execute();
                $stmt->close();
            }
        }

        // Delete a user's photo (admin override; same logic as settings.php delete_photo)
        if ($action === 'delete_photo' && $target_user_id > 0) {
            $photo_id = (int) ($_POST['photo_id'] ?? 0);
            if ($photo_id > 0) {
                $stmt = $conn->prepare("SELECT photo_url FROM User_Pictures WHERE photo_id = ? AND user_id = ?");
                $stmt->bind_param('ii', $photo_id, $target_user_id);
                $stmt->execute();
                $photo = $stmt->get_result()->fetch_assoc();
                $stmt->close();

                if ($photo) {
                    $filePath = __DIR__ . '/../../Uploads/' . $photo['photo_url'];
                    if (file_exists($filePath)) {
                        unlink($filePath);
                    }
                    $stmt = $conn->prepare("DELETE FROM User_Pictures WHERE photo_id = ? AND user_id = ?");
                    $stmt->bind_param('ii', $photo_id, $target_user_id);
                    $stmt->execute();
                    $stmt->close();
                }
            }
        }

        // Edit a user's profile (e.g. change offensive name, bio, gender, location)
        if ($action === 'edit_profile' && $target_user_id > 0) {
            $new_first_name = trim($_POST['first_name'] ?? '');
            $new_last_name = trim($_POST['last_name'] ?? '');
            $new_bio = trim($_POST['user_bio'] ?? '');
            $new_location = trim($_POST['general_location'] ?? '');

            $gender_raw = $_POST['gender'] ?? '';
            $allowed_genders = ['male', 'female', 'non-binary'];
            $new_gender = in_array($gender_raw, $allowed_genders, true) ? $gender_raw : null;

            if ($new_first_name !== '' && $new_last_name !== '' && strlen($new_bio) <= 500) {
                $stmt = $conn->prepare("UPDATE User_Profile SET first_name = ?, last_name = ?, gender = ?, user_bio = ?, general_location = ? WHERE user_id = ?");
                $stmt->bind_param("sssssi", $new_first_name, $new_last_name, $new_gender, $new_bio, $new_location, $target_user_id);
                $stmt->execute();
                $stmt->close();
            }
        }

        // Preserve user_id when staying on the user_detail page
        $redirect = '/features/admin/admin.php?page=' . urlencode($page);
        if ($page === 'user_detail' && $target_user_id > 0) {
            $redirect .= '&user_id=' . $target_user_id;
        }
        header('Location: ' . $redirect);
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
<nav class="d-flex justify-content-between align-items-center bg-white shadow-sm py-3 px-4">
    <div>
        <img src="/assets/images/wingmate-navbar.png" alt="WingMate" style="height: 50px; width: auto;">
    </div>
    <div class="d-flex gap-3">
        <a href="/features/admin/admin.php" class="admin-nav-link text-decoration-none">Admin Console</a>
        <a href="/features/auth/login.php" class="admin-nav-link text-decoration-none">Logout</a>
    </div>
</nav>

<div class="container-fluid">
    <div class="row">
        <!-- Inbox Sidebar -->
        <div class="col-lg-2 admin-sidebar p-3 min-vh-100">
            <div class="bg-white rounded-4 p-4">
                <p class="fs-5 fw-bold">Inbox</p>
                <a href="/features/admin/admin.php?page=reports" class="d-block text-decoration-none mb-2 inbox-item"><?php echo (int) $report_count; ?> Open Reports</a>
                <a href="/features/admin/admin.php?page=suspended" class="d-block text-decoration-none mb-2 inbox-item"><?php echo (int) $suspended_count; ?> Suspended/Banned</a>
            </div>
        </div>

        <!-- Main Content -->
        <div class="col-lg-10 p-4">
            <!-- Error message display -->
            <?php if ($adminError !== ''): ?>
                <div class="alert alert-danger"><?php echo htmlspecialchars($adminError, ENT_QUOTES, 'UTF-8'); ?></div>
            <?php endif; ?>

            <?php if ($page === 'dashboard'): ?>
                <!-- Dashboard Home -->
                <div class="d-flex align-items-center gap-3 mt-3 mb-4">
                    <div class="rounded-circle d-flex align-items-center justify-content-center overflow-hidden bg-secondary-subtle" style="width:80px;height:80px;">
                        <img src="/assets/images/default-avatar.svg" alt="Admin" class="w-100 h-100" style="object-fit:cover;">
                    </div>
                    <div>
                        <p class="fs-5 fw-semibold mb-0"><?php echo htmlspecialchars($admin_name, ENT_QUOTES, 'UTF-8'); ?></p>
                    </div>
                </div>

                <div class="list-group">
                    <a href="/features/admin/admin.php?page=reports" class="list-group-item list-group-item-action">Review Reports</a>
                    <a href="/features/admin/admin.php?page=suspended" class="list-group-item list-group-item-action">View and Edit Banned or Suspended Users</a>
                    <a href="/features/admin/admin.php?page=users" class="list-group-item list-group-item-action">Manage All Users</a>
                </div>

            <?php elseif ($page === 'reports'): ?>
                <!-- Review Reports -->
                <h2 class="fs-3 fw-bold mb-3">Review Reports</h2>

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
                    <div class="table-responsive">
                    <table class="table table-hover">
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
                                        <a href="/features/admin/admin.php?page=user_detail&user_id=<?php echo (int) $report['reported_id']; ?>" class="admin-link text-decoration-none">
                                            <?php echo htmlspecialchars($report['reported_first'] . ' ' . $report['reported_last'], ENT_QUOTES, 'UTF-8'); ?>
                                        </a>
                                    </td>
                                    <td><?php echo htmlspecialchars($report['reason'], ENT_QUOTES, 'UTF-8'); ?></td>
                                    <td><?php echo htmlspecialchars($report['details'], ENT_QUOTES, 'UTF-8'); ?></td>
                                    <td>
                                        <?php
                                        $reportBadge = match ($report['report_status']) {
                                            'open' => 'text-bg-warning',
                                            'resolved' => 'text-bg-success',
                                            'dismissed' => 'text-bg-secondary',
                                            default => 'text-bg-secondary',
                                        };
                                        ?>
                                        <span class="badge <?php echo $reportBadge; ?>">
                                            <?php echo htmlspecialchars($report['report_status'], ENT_QUOTES, 'UTF-8'); ?>
                                        </span>
                                    </td>
                                    <td><?php echo htmlspecialchars($report['created_at'], ENT_QUOTES, 'UTF-8'); ?></td>
                                    <td>
                                        <?php if ($report['report_status'] === 'open'): ?>
                                            <form method="POST" class="d-inline">
                                                <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars(wingmate_get_csrf_token(), ENT_QUOTES, 'UTF-8'); ?>">
                                                <input type="hidden" name="report_id" value="<?php echo (int) $report['report_id']; ?>">
                                                <button type="submit" name="action" value="resolve_report" class="btn btn-secondary btn-sm">Resolve</button>
                                                <button type="submit" name="action" value="dismiss_report" class="btn btn-danger btn-sm">Dismiss</button>
                                            </form>
                                        <?php endif; ?>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                    </div>
                <?php else: ?>
                    <div class="alert alert-light text-center text-muted">No reports found.</div>
                <?php endif; ?>

            <?php elseif ($page === 'user_detail'): ?>
                <!-- User Detail Page: view profile, messages, and take action -->
                <?php
                $view_user_id = (int) ($_GET['user_id'] ?? 0);
                $view_user = null;
                $user_messages = [];
                $user_photos = [];
                $user_comments = [];

                if ($view_user_id > 0) {
                    // Fetch user info
                    $stmt = $conn->prepare("
                        SELECT u.user_id, u.email, u.user_type, u.account_status, u.suspended_until, u.created_at,
                               p.first_name, p.last_name, p.gender, p.user_bio, p.general_location
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

                    // Fetch this user's photos so admin can remove offensive ones
                    $stmt = $conn->prepare("
                        SELECT photo_id, photo_url, is_primary
                        FROM User_Pictures
                        WHERE user_id = ? AND is_removed = 0
                        ORDER BY is_primary DESC, uploaded_at DESC
                    ");
                    $stmt->bind_param("i", $view_user_id);
                    $stmt->execute();
                    $user_photos = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
                    $stmt->close();

                    // Fetch profile comments this user has WRITTEN (mirrors Recent Messages logic)
                    $stmt = $conn->prepare("
                        SELECT fc.comment_id, fc.comment_text, fc.created_at,
                               op.first_name AS owner_first, op.last_name AS owner_last
                        FROM Friend_Comments fc
                        LEFT JOIN User_Profile op ON op.user_id = fc.profile_owner_id
                        WHERE fc.commenter_id = ?
                        ORDER BY fc.created_at DESC
                        LIMIT 50
                    ");
                    $stmt->bind_param("i", $view_user_id);
                    $stmt->execute();
                    $user_comments = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
                    $stmt->close();
                }
                ?>

                <?php if ($view_user): ?>
                    <h2 class="fs-3 fw-bold mb-3">User Detail</h2>

                    <!-- User Info -->
                    <?php
                    $statusBadge = match ($view_user['account_status']) {
                        'active' => 'text-bg-success',
                        'suspended' => 'text-bg-warning',
                        'banned' => 'text-bg-danger',
                        default => 'text-bg-secondary',
                    };
                    ?>
                    <div class="card card-body mb-4">
                        <dl class="row mb-0">
                            <dt class="col-sm-3">Name</dt>
                            <dd class="col-sm-9"><?php echo htmlspecialchars($view_user['first_name'] . ' ' . $view_user['last_name'], ENT_QUOTES, 'UTF-8'); ?></dd>

                            <dt class="col-sm-3">Email</dt>
                            <dd class="col-sm-9"><?php echo htmlspecialchars($view_user['email'], ENT_QUOTES, 'UTF-8'); ?></dd>

                            <dt class="col-sm-3">Status</dt>
                            <dd class="col-sm-9">
                                <span class="badge <?php echo $statusBadge; ?>">
                                    <?php echo htmlspecialchars($view_user['account_status'], ENT_QUOTES, 'UTF-8'); ?>
                                </span>
                            </dd>

                            <?php if ($view_user['suspended_until']): ?>
                                <dt class="col-sm-3">Suspended Until</dt>
                                <dd class="col-sm-9"><?php echo htmlspecialchars($view_user['suspended_until'], ENT_QUOTES, 'UTF-8'); ?></dd>
                            <?php endif; ?>

                            <dt class="col-sm-3">Joined</dt>
                            <dd class="col-sm-9"><?php echo htmlspecialchars($view_user['created_at'], ENT_QUOTES, 'UTF-8'); ?></dd>
                        </dl>
                    </div>

                    <!-- Edit Profile (admin override for any User_Profile field) -->
                    <?php if ($view_user['user_type'] !== 'administrator'): ?>
                        <div class="card card-body mb-4">
                            <h5 class="card-title">Edit Profile</h5>
                            <form method="POST">
                                <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars(wingmate_get_csrf_token(), ENT_QUOTES, 'UTF-8'); ?>">
                                <input type="hidden" name="action" value="edit_profile">
                                <input type="hidden" name="user_id" value="<?php echo (int) $view_user['user_id']; ?>">
                                <div class="row g-3 align-items-end">
                                    <div class="col-md-6">
                                        <label class="form-label">First Name</label>
                                        <input type="text" name="first_name" class="form-control" required value="<?php echo htmlspecialchars($view_user['first_name'] ?? '', ENT_QUOTES, 'UTF-8'); ?>">
                                    </div>
                                    <div class="col-md-6">
                                        <label class="form-label">Last Name</label>
                                        <input type="text" name="last_name" class="form-control" required value="<?php echo htmlspecialchars($view_user['last_name'] ?? '', ENT_QUOTES, 'UTF-8'); ?>">
                                    </div>
                                    <div class="col-md-6">
                                        <label class="form-label">Gender</label>
                                        <select name="gender" class="form-select">
                                            <?php $g = $view_user['gender'] ?? ''; ?>
                                            <option value="" <?php echo $g === '' ? 'selected' : ''; ?>>Prefer not to say</option>
                                            <option value="male" <?php echo $g === 'male' ? 'selected' : ''; ?>>Male</option>
                                            <option value="female" <?php echo $g === 'female' ? 'selected' : ''; ?>>Female</option>
                                            <option value="non-binary" <?php echo $g === 'non-binary' ? 'selected' : ''; ?>>Non-binary</option>
                                        </select>
                                    </div>
                                    <div class="col-md-6">
                                        <label class="form-label">Location</label>
                                        <input type="text" name="general_location" class="form-control" value="<?php echo htmlspecialchars($view_user['general_location'] ?? '', ENT_QUOTES, 'UTF-8'); ?>">
                                    </div>
                                    <div class="col-12">
                                        <label class="form-label">Bio</label>
                                        <textarea name="user_bio" class="form-control" rows="3" maxlength="500"><?php echo htmlspecialchars($view_user['user_bio'] ?? '', ENT_QUOTES, 'UTF-8'); ?></textarea>
                                    </div>
                                </div>
                                <button type="submit" class="btn btn-secondary btn-sm mt-3">Save</button>
                            </form>
                        </div>

                        <!-- Edit Email (separate form because email lives on the Users table) -->
                        <div class="card card-body mb-4">
                            <h5 class="card-title">Edit Email</h5>
                            <form method="POST" class="d-flex gap-2 align-items-end">
                                <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars(wingmate_get_csrf_token(), ENT_QUOTES, 'UTF-8'); ?>">
                                <input type="hidden" name="action" value="edit_email">
                                <input type="hidden" name="user_id" value="<?php echo (int) $view_user['user_id']; ?>">
                                <div class="flex-grow-1">
                                    <label class="form-label">Email</label>
                                    <input type="email" name="email" class="form-control" required value="<?php echo htmlspecialchars($view_user['email'] ?? '', ENT_QUOTES, 'UTF-8'); ?>">
                                </div>
                                <button type="submit" class="btn btn-secondary btn-sm">Save</button>
                            </form>
                        </div>

                        <!-- Photos: each thumbnail has its own remove form -->
                        <div class="card card-body mb-4">
                            <h5 class="card-title">Photos</h5>
                            <?php if (count($user_photos) > 0): ?>
                                <div class="d-flex flex-wrap gap-2">
                                    <?php foreach ($user_photos as $photo): ?>
                                        <div class="position-relative" style="width:120px;">
                                            <img src="/Uploads/<?php echo htmlspecialchars($photo['photo_url'], ENT_QUOTES, 'UTF-8'); ?>" alt="User photo" class="rounded" style="width:120px;height:120px;object-fit:cover;">
                                            <?php if ((int) $photo['is_primary'] === 1): ?>
                                                <span class="position-absolute top-0 start-0 m-1 badge text-bg-primary">Primary</span>
                                            <?php endif; ?>
                                            <form method="POST" class="mt-2">
                                                <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars(wingmate_get_csrf_token(), ENT_QUOTES, 'UTF-8'); ?>">
                                                <input type="hidden" name="action" value="delete_photo">
                                                <input type="hidden" name="user_id" value="<?php echo (int) $view_user['user_id']; ?>">
                                                <input type="hidden" name="photo_id" value="<?php echo (int) $photo['photo_id']; ?>">
                                                <button type="submit" class="btn btn-danger btn-sm">Remove</button>
                                            </form>
                                        </div>
                                    <?php endforeach; ?>
                                </div>
                            <?php else: ?>
                                <div class="alert alert-light text-center text-muted mb-0">No photos.</div>
                            <?php endif; ?>
                        </div>

                        <!-- Account Actions -->
                        <div class="card card-body mb-4">
                            <h5 class="card-title">Account Actions</h5>
                            <form method="POST" class="d-flex gap-2 align-items-end">
                                <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars(wingmate_get_csrf_token(), ENT_QUOTES, 'UTF-8'); ?>">
                                <input type="hidden" name="user_id" value="<?php echo (int) $view_user['user_id']; ?>">

                                <?php if ($view_user['account_status'] === 'active'): ?>
                                    <div>
                                        <label class="form-label">Suspend for (days)</label>
                                        <input type="number" name="suspend_days" class="form-control" value="7" min="1" max="365">
                                    </div>
                                    <button type="submit" name="action" value="suspend" class="btn btn-secondary btn-sm">Suspend</button>
                                    <button type="submit" name="action" value="ban" class="btn btn-danger btn-sm">Ban Permanently</button>

                                <?php elseif ($view_user['account_status'] === 'suspended'): ?>
                                    <button type="submit" name="action" value="unsuspend" class="btn btn-secondary btn-sm">Unsuspend</button>
                                    <button type="submit" name="action" value="ban" class="btn btn-danger btn-sm">Ban Permanently</button>

                                <?php elseif ($view_user['account_status'] === 'banned'): ?>
                                    <p class="text-danger small mb-0">This account is permanently banned.</p>
                                <?php endif; ?>
                            </form>
                        </div>
                    <?php endif; ?>

                    <!-- User's Messages -->
                    <div class="card card-body">
                        <h5 class="card-title">Recent Messages</h5>
                        <?php if (count($user_messages) > 0): ?>
                            <div class="table-responsive">
                            <table class="table table-hover">
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
                                        <tr class="<?php echo $msg['is_removed'] ? 'opacity-50' : ''; ?>">
                                            <td><?php echo htmlspecialchars($msg['content'], ENT_QUOTES, 'UTF-8'); ?></td>
                                            <td><?php echo htmlspecialchars($msg['sent_at'], ENT_QUOTES, 'UTF-8'); ?></td>
                                            <td>
                                                <?php if ($msg['is_removed']): ?>
                                                    <span class="badge text-bg-danger">removed</span>
                                                <?php else: ?>
                                                    <span class="badge text-bg-success">visible</span>
                                                <?php endif; ?>
                                            </td>
                                            <td>
                                                <?php if (!$msg['is_removed']): ?>
                                                    <form method="POST" class="d-inline">
                                                        <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars(wingmate_get_csrf_token(), ENT_QUOTES, 'UTF-8'); ?>">
                                                        <input type="hidden" name="action" value="remove_message">
                                                        <input type="hidden" name="message_id" value="<?php echo (int) $msg['message_id']; ?>">
                                                        <button type="submit" class="btn btn-danger btn-sm">Remove</button>
                                                    </form>
                                                <?php endif; ?>
                                            </td>
                                        </tr>
                                    <?php endforeach; ?>
                                </tbody>
                            </table>
                            </div>
                        <?php else: ?>
                            <div class="alert alert-light text-center text-muted mb-0">No messages found.</div>
                        <?php endif; ?>
                    </div>

                    <!-- Profile Comments this user has written (Friend_Comments) -->
                    <div class="card card-body mt-4">
                        <h5 class="card-title">Profile Comments Written</h5>
                        <?php if (count($user_comments) > 0): ?>
                            <div class="table-responsive">
                            <table class="table table-hover">
                                <thead>
                                    <tr>
                                        <th>Comment</th>
                                        <th>On Profile Of</th>
                                        <th>Posted At</th>
                                        <th>Actions</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php foreach ($user_comments as $comment): ?>
                                        <tr>
                                            <td><?php echo htmlspecialchars($comment['comment_text'], ENT_QUOTES, 'UTF-8'); ?></td>
                                            <td><?php echo htmlspecialchars(($comment['owner_first'] ?? '') . ' ' . ($comment['owner_last'] ?? ''), ENT_QUOTES, 'UTF-8'); ?></td>
                                            <td><?php echo htmlspecialchars($comment['created_at'], ENT_QUOTES, 'UTF-8'); ?></td>
                                            <td>
                                                <form method="POST" class="d-inline">
                                                    <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars(wingmate_get_csrf_token(), ENT_QUOTES, 'UTF-8'); ?>">
                                                    <input type="hidden" name="action" value="delete_comment">
                                                    <input type="hidden" name="user_id" value="<?php echo (int) $view_user['user_id']; ?>">
                                                    <input type="hidden" name="comment_id" value="<?php echo (int) $comment['comment_id']; ?>">
                                                    <button type="submit" class="btn btn-danger btn-sm">Remove</button>
                                                </form>
                                            </td>
                                        </tr>
                                    <?php endforeach; ?>
                                </tbody>
                            </table>
                            </div>
                        <?php else: ?>
                            <div class="alert alert-light text-center text-muted mb-0">No comments written.</div>
                        <?php endif; ?>
                    </div>

                <?php else: ?>
                    <div class="alert alert-danger">User not found.</div>
                <?php endif; ?>

            <?php elseif ($page === 'suspended'): ?>
                <!-- View Suspended and Banned Users -->
                <h2 class="fs-3 fw-bold mb-3">Suspended & Banned Users</h2>

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
                    <div class="table-responsive">
                    <table class="table table-hover">
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
                                        <a href="/features/admin/admin.php?page=user_detail&user_id=<?php echo (int) $user['user_id']; ?>" class="admin-link text-decoration-none">
                                            <?php echo htmlspecialchars($user['first_name'] . ' ' . $user['last_name'], ENT_QUOTES, 'UTF-8'); ?>
                                        </a>
                                    </td>
                                    <td><?php echo htmlspecialchars($user['email'], ENT_QUOTES, 'UTF-8'); ?></td>
                                    <td>
                                        <?php
                                        $userBadge = match ($user['account_status']) {
                                            'active' => 'text-bg-success',
                                            'suspended' => 'text-bg-warning',
                                            'banned' => 'text-bg-danger',
                                            default => 'text-bg-secondary',
                                        };
                                        ?>
                                        <span class="badge <?php echo $userBadge; ?>">
                                            <?php echo htmlspecialchars($user['account_status'], ENT_QUOTES, 'UTF-8'); ?>
                                        </span>
                                    </td>
                                    <td><?php echo $user['suspended_until'] ? htmlspecialchars($user['suspended_until'], ENT_QUOTES, 'UTF-8') : 'Permanent'; ?></td>
                                    <td>
                                        <form method="POST" class="d-inline">
                                            <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars(wingmate_get_csrf_token(), ENT_QUOTES, 'UTF-8'); ?>">
                                            <input type="hidden" name="user_id" value="<?php echo (int) $user['user_id']; ?>">
                                            <?php if ($user['account_status'] === 'suspended'): ?>
                                                <button type="submit" name="action" value="unsuspend" class="btn btn-secondary btn-sm">Unsuspend</button>
                                            <?php endif; ?>
                                        </form>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                    </div>
                <?php else: ?>
                    <div class="alert alert-light text-center text-muted">No suspended or banned users.</div>
                <?php endif; ?>

            <?php elseif ($page === 'users'): ?>
                <!-- Manage All Users -->
                <h2 class="fs-3 fw-bold mb-3">Manage All Users</h2>

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

                <div class="table-responsive">
                <table class="table table-hover">
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
                                    <a href="/features/admin/admin.php?page=user_detail&user_id=<?php echo (int) $user['user_id']; ?>" class="admin-link text-decoration-none">
                                        <?php echo htmlspecialchars(($user['first_name'] ?? '') . ' ' . ($user['last_name'] ?? ''), ENT_QUOTES, 'UTF-8'); ?>
                                    </a>
                                </td>
                                <td><?php echo htmlspecialchars($user['email'], ENT_QUOTES, 'UTF-8'); ?></td>
                                <td><?php echo htmlspecialchars($user['user_type'], ENT_QUOTES, 'UTF-8'); ?></td>
                                <td>
                                    <?php
                                    $allUserBadge = match ($user['account_status']) {
                                        'active' => 'text-bg-success',
                                        'suspended' => 'text-bg-warning',
                                        'banned' => 'text-bg-danger',
                                        default => 'text-bg-secondary',
                                    };
                                    ?>
                                    <span class="badge <?php echo $allUserBadge; ?>">
                                        <?php echo htmlspecialchars($user['account_status'], ENT_QUOTES, 'UTF-8'); ?>
                                    </span>
                                </td>
                                <td><?php echo htmlspecialchars($user['created_at'], ENT_QUOTES, 'UTF-8'); ?></td>
                                <td>
                                    <?php if ($user['user_type'] !== 'administrator'): ?>
                                        <a href="/features/admin/admin.php?page=user_detail&user_id=<?php echo (int) $user['user_id']; ?>" class="btn btn-secondary btn-sm">View</a>
                                    <?php endif; ?>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
                </div>
            <?php endif; ?>
        </div>
    </div>
</div>

<?php include __DIR__ . '/../../includes/footer.php'; ?>

<?php
require_once __DIR__ . '/../../includes/session.php';
require_once __DIR__ . '/../../config/config.php';

wingmate_start_secure_session();

// Block access if user is not logged in or not an admin
wingmate_require_admin();

// Handle POST actions (suspend, ban, unsuspend)
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!wingmate_validate_csrf_token($_POST['csrf_token'] ?? null)) {
        http_response_code(400);
    } else {
        $action = $_POST['action'] ?? '';
        $target_user_id = (int) ($_POST['user_id'] ?? 0);

        // Prevent admin from modifying their own account
        if ($target_user_id === (int) $_SESSION['user_id']) {
            $_SESSION['admin_error'] = 'You cannot modify your own account.';
            header('Location: /features/admin/admin.php');
            exit;
        }

        // Suspend a user account
        if ($action === 'suspend' && $target_user_id > 0) {
            $stmt = $conn->prepare("UPDATE Users SET account_status = 'suspended' WHERE user_id = ?");
            $stmt->bind_param("i", $target_user_id);
            $stmt->execute();
            $stmt->close();
        }

        // Ban a user account
        if ($action === 'ban' && $target_user_id > 0) {
            $stmt = $conn->prepare("UPDATE Users SET account_status = 'banned' WHERE user_id = ?");
            $stmt->bind_param("i", $target_user_id);
            $stmt->execute();
            $stmt->close();
        }

        // Reactivate a suspended or banned account
        if ($action === 'unsuspend' && $target_user_id > 0) {
            $stmt = $conn->prepare("UPDATE Users SET account_status = 'active' WHERE user_id = ?");
            $stmt->bind_param("i", $target_user_id);
            $stmt->execute();
            $stmt->close();
        }

        // Redirect back to admin page to show updated data
        header('Location: /features/admin/admin.php');
        exit;
    }
}

// Fetch all users with their profile info for the admin table
$users = [];
$stmt = $conn->prepare("
    SELECT u.user_id, u.email, u.user_type, u.account_status, u.created_at,
           p.first_name, p.last_name
    FROM Users u
    LEFT JOIN User_Profile p ON u.user_id = p.user_id
    ORDER BY u.created_at DESC
");
$stmt->execute();
$result = $stmt->get_result();
while ($row = $result->fetch_assoc()) {
    $users[] = $row;
}
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

<main class="admin-dashboard container py-4">
    <!-- Page header with title and logout -->
    <div class="admin-header d-flex justify-content-between align-items-center mb-4">
        <p class="m-0">Admin Dashboard</p>
        <button onclick="window.location.href='/features/auth/login.php'">Logout</button>
    </div>

    <!-- Error message display -->
    <?php if ($adminError !== ''): ?>
        <p class="admin-error"><?php echo htmlspecialchars($adminError, ENT_QUOTES, 'UTF-8'); ?></p>
    <?php endif; ?>

    <!-- User management table -->
    <div class="admin-section">
        <p>Manage Users</p>
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
                        <td><?php echo htmlspecialchars(($user['first_name'] ?? '') . ' ' . ($user['last_name'] ?? ''), ENT_QUOTES, 'UTF-8'); ?></td>
                        <td><?php echo htmlspecialchars($user['email'], ENT_QUOTES, 'UTF-8'); ?></td>
                        <td><?php echo htmlspecialchars($user['user_type'], ENT_QUOTES, 'UTF-8'); ?></td>
                        <td>
                            <!-- Colour-coded status badge -->
                            <span class="status-badge status-<?php echo htmlspecialchars($user['account_status'], ENT_QUOTES, 'UTF-8'); ?>">
                                <?php echo htmlspecialchars($user['account_status'], ENT_QUOTES, 'UTF-8'); ?>
                            </span>
                        </td>
                        <td><?php echo htmlspecialchars($user['created_at'], ENT_QUOTES, 'UTF-8'); ?></td>
                        <td>
                            <!-- Only show action buttons for non-admin users -->
                            <?php if ($user['user_type'] !== 'administrator'): ?>
                                <form method="POST" class="d-inline">
                                    <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars(wingmate_get_csrf_token(), ENT_QUOTES, 'UTF-8'); ?>">
                                    <input type="hidden" name="user_id" value="<?php echo $user['user_id']; ?>">

                                    <?php if ($user['account_status'] === 'active'): ?>
                                        <!-- Active users can be suspended or banned -->
                                        <button type="submit" name="action" value="suspend" class="button-secondary admin-action-btn">Suspend</button>
                                        <button type="submit" name="action" value="ban" class="admin-action-btn">Ban</button>

                                    <?php elseif ($user['account_status'] === 'suspended'): ?>
                                        <!-- Suspended users can be reactivated or banned -->
                                        <button type="submit" name="action" value="unsuspend" class="button-secondary admin-action-btn">Unsuspend</button>
                                        <button type="submit" name="action" value="ban" class="admin-action-btn">Ban</button>

                                    <?php elseif ($user['account_status'] === 'banned'): ?>
                                        <!-- Banned users can be reactivated -->
                                        <button type="submit" name="action" value="unsuspend" class="button-secondary admin-action-btn">Unban</button>
                                    <?php endif; ?>
                                </form>
                            <?php endif; ?>
                        </td>
                    </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
    </div>
</main>

<?php include __DIR__ . '/../../includes/footer.php'; ?>

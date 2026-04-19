<?php
require_once __DIR__ . '/../../config/config.php';
require_once __DIR__ . '/../../includes/session.php';
wingmate_start_secure_session();

$error   = '';
$success = '';
$step    = isset($_GET['token']) ? 'reset' : 'request';
$token   = trim($_GET['token'] ?? '');

function clean_input($data) {
    return htmlspecialchars(stripslashes(trim($data)), ENT_QUOTES, 'UTF-8');
}


if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['email'])) {
    if (!wingmate_validate_csrf_token($_POST['csrf_token'] ?? null)) {
        $error = 'Session validation failed. Please refresh and try again.';
    } else {
        $email = clean_input($_POST['email']);

        $stmt = $conn->prepare("SELECT user_id FROM Users WHERE email = ?");
        $stmt->bind_param('s', $email);
        $stmt->execute();
        $result = $stmt->get_result();
        $stmt->close();

        
        if ($result->num_rows === 1) {
            $user = $result->fetch_assoc();

            
            $stmt = $conn->prepare("DELETE FROM Password_Resets WHERE user_id = ?");
            $stmt->bind_param('i', $user['user_id']);
            $stmt->execute();
            $stmt->close();

            
            $token      = bin2hex(random_bytes(32));
            $expires_at = date('Y-m-d H:i:s', strtotime('+1 hour'));

            $stmt = $conn->prepare("INSERT INTO Password_Resets (user_id, token, expires_at) VALUES (?, ?, ?)");
            $stmt->bind_param('iss', $user['user_id'], $token, $expires_at);
            $stmt->execute();
            $stmt->close();

            
            $reset_link = 'http://' . $_SERVER['HTTP_HOST'] . '/features/auth/forgot_password.php?token=' . $token;
            $success = 'Reset link generated! <a href="' . htmlspecialchars($reset_link) . '">Click here to reset your password</a>.';
        } else {
            $success = 'If that email exists in our system, a reset link has been generated.';
        }
    }
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['password'])) {
    if (!wingmate_validate_csrf_token($_POST['csrf_token'] ?? null)) {
        $error = 'Session validation failed. Please refresh and try again.';
    } else {
        $token       = clean_input($_POST['token']);
        $password    = $_POST['password'];
        $confirm     = $_POST['confirm_password'];

        if (strlen($password) < 8) {
            $error = 'Password must be at least 8 characters.';
        } elseif ($password !== $confirm) {
            $error = 'Passwords do not match.';
        } else {
            
            $stmt = $conn->prepare("
                SELECT user_id FROM Password_Resets
                WHERE token = ? AND expires_at > NOW() AND used = 0
            ");
            $stmt->bind_param('s', $token);
            $stmt->execute();
            $result = $stmt->get_result();
            $stmt->close();

            if ($result->num_rows === 1) {
                $row          = $result->fetch_assoc();
                $user_id      = $row['user_id'];
                $password_hash = password_hash($password, PASSWORD_DEFAULT);

                
                $stmt = $conn->prepare("UPDATE Users SET password_hash = ? WHERE user_id = ?");
                $stmt->bind_param('si', $password_hash, $user_id);
                $stmt->execute();
                $stmt->close();

                
                $stmt = $conn->prepare("UPDATE Password_Resets SET used = 1 WHERE token = ?");
                $stmt->bind_param('s', $token);
                $stmt->execute();
                $stmt->close();

                $success = 'Password reset successfully! <a href="/features/auth/login.php">Click here to log in</a>.';
                $step    = 'done';
            } else {
                $error = 'This reset link is invalid or has expired. Please request a new one.';
            }
        }
    }
}


if ($step === 'reset' && $token !== '') {
    $stmt = $conn->prepare("SELECT reset_id FROM Password_Resets WHERE token = ? AND expires_at > NOW() AND used = 0");
    $stmt->bind_param('s', $token);
    $stmt->execute();
    if ($stmt->get_result()->num_rows === 0) {
        $error = 'This reset link is invalid or has expired. Please request a new one.';
        $step  = 'request';
        $token = '';
    }
    $stmt->close();
}
?>

<?php include __DIR__ . '/../../includes/auth-header.php'; ?>
<link rel="stylesheet" href="/features/auth/auth.css">

<main class="auth-display container-fluid d-flex min-vh-100 px-0">
    <div class="auth-left-display d-none d-md-flex col-md-6 justify-content-center align-items-center">
        <img src="/assets/images/wingmate-logo.png" alt="WingMate Logo" class="logo-image">
    </div>

    <div class="auth-right-display d-flex col-12 col-md-6 justify-content-center align-items-center">
        <div class="auth-form-display">

            <?php if ($step === 'request'): ?>
            
            <div class="title-text">
                <p>Forgot Password</p>
                <p2>Enter your email to get a reset link</p2>
            </div>
            <div class="auth-input-form">
                <form action="forgot_password.php" method="POST">
                    <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars(wingmate_get_csrf_token(), ENT_QUOTES, 'UTF-8'); ?>">

                    <div class="auth-input-group">
                        <?php if ($error !== ''): ?>
                            <p class="auth-general-error"><?php echo $error; ?></p>
                        <?php endif; ?>
                        <?php if ($success !== ''): ?>
                            <p class="auth-success"><?php echo $success; ?></p>
                        <?php endif; ?>
                        <div class="auth-input-field">
                            <img src="/assets/images/mail-icon.svg" alt="" class="input-icon">
                            <input type="email" name="email" placeholder="Email" required>
                        </div>
                    </div>

                    <div class="buttons">
                        <button type="button" onclick="window.location.href='/features/auth/login.php'">Back</button>
                        <button class="button-secondary" type="submit">Send Link</button>
                    </div>
                </form>
            </div>

            <?php elseif ($step === 'reset'): ?>
            
            <div class="title-text">
                <p>Reset Password</p>
                <p2>Enter your new password below</p2>
            </div>
            <div class="auth-input-form">
                <form action="forgot_password.php" method="POST">
                    <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars(wingmate_get_csrf_token(), ENT_QUOTES, 'UTF-8'); ?>">
                    <input type="hidden" name="token" value="<?php echo htmlspecialchars($token, ENT_QUOTES, 'UTF-8'); ?>">

                    <div class="auth-input-group">
                        <?php if ($error !== ''): ?>
                            <p class="auth-general-error"><?php echo $error; ?></p>
                        <?php endif; ?>
                        <div class="auth-input-field">
                            <img src="/assets/images/lock-icon.svg" alt="" class="input-icon">
                            <input type="password" name="password" placeholder="New Password" required minlength="8">
                        </div>
                    </div>
                    <div class="auth-input-field">
                        <img src="/assets/images/lock-icon.svg" alt="" class="input-icon">
                        <input type="password" name="confirm_password" placeholder="Confirm Password" required minlength="8">
                    </div>

                    <div class="buttons">
                        <button type="button" onclick="window.location.href='/features/auth/login.php'">Back</button>
                        <button class="button-secondary" type="submit">Reset</button>
                    </div>
                </form>
            </div>

            <?php elseif ($step === 'done'): ?>
            
            <div class="title-text">
                <p>All Done!</p>
                <p2>Your password has been reset</p2>
            </div>
            <div class="auth-input-form">
                <div style="padding: 23px 27px;">
                    <p class="auth-success"><?php echo $success; ?></p>
                    <div class="buttons">
                        <button class="button-secondary" onclick="window.location.href='/features/auth/login.php'">Log In</button>
                    </div>
                </div>
            </div>
            <?php endif; ?>

        </div>
    </div>
</main>

<?php include __DIR__ . '/../../includes/footer.php'; ?>

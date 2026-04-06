<!--Requirements
1. Secure session handling
2. Input validation and sanitization
3. Protection against basic SQL Injection
4. Verify hashed password with password_verify()
5. No hard-coded credentials
-->

<?php
require_once __DIR__ . '/../../config/config.php';
require_once __DIR__ . '/../../includes/session.php';
wingmate_start_secure_session();

$loginError = '';
$loginSuccess = '';
$email = '';
$password = '';

// Query database for user by email
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $email = trim($_POST['email']);
    $password = trim($_POST['password']);

    
    try {
        $stmt = $conn->prepare("SELECT user_id, password_hash FROM Users WHERE email = ?");
        $stmt->bind_param("s", $email);
        $stmt->execute();
        $result = $stmt->get_result();



        if ($result->num_rows === 1) {
            $user = $result->fetch_assoc();
            // Verify password against stored hash
            if (password_verify($password, $user['password_hash'])) {
                session_regenerate_id(true);
                $_SESSION['user_id'] = $user['user_id'];
                header('Location: /features/friends/friends.php');
                exit;
            } else {
                $loginError = 'Invalid email or password.';
            }
        } else {
            $loginError = 'Invalid email or password.';
        }
    } catch (Exception $e) {
        $loginError = 'Invalid email or password.';
    }
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
            <div class="title-text">
                <p>Welcome Back</p>
                <p2>Enter your login details below</p2>
            </div>
            <?php if ($loginError !== ''): ?>
                <p class="auth-general-error"><?php echo htmlspecialchars($loginError, ENT_QUOTES, 'UTF-8'); ?></p>
            <?php endif; ?>
            <?php if ($loginSuccess !== ''): ?>
                <p class="auth-success"><?php echo htmlspecialchars($loginSuccess, ENT_QUOTES, 'UTF-8'); ?></p>
            <?php endif; ?>
            <div class="auth-input-form">
                <form action="login.php" method="POST">
                    <div class="auth-input-field">
                        <img src="/assets/images/mail-icon.svg" alt="" class="input-icon">
                        <input type="email" name="email" placeholder="Email" required>
                    </div>
                    <div class="auth-input-field">
                        <img src="/assets/images/lock-icon.svg" alt="" class="input-icon">
                        <input type="password" name="password" placeholder="Password" required>
                    </div>
                    <div class="buttons">
                        <button type="button" onclick="window.location.href='/features/auth/register.php'">Register</button>
                        <button class="button-secondary" type="submit">Login</button>
                    </div>
                </form>
            </div>
        </div>
    </div>
</main>

<?php include __DIR__ . '/../../includes/footer.php'; ?>    
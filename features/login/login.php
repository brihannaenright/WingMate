<!--Requirements
1. Secure session handling
2. Input validation and sanitization
3. Protection against basic SQL Injection
4. Verify hashed password with password_verify()
5. No hard-coded credentials
-->

<?php
require_once __DIR__ . '/../../config/config.php';

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
                session_start();
                session_regenerate_id(true);
                $_SESSION['user_id'] = $user['user_id'];
                $loginSuccess = 'Login successful.';
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
<link rel="stylesheet" href="./login.css">
<div class="login-display">
    <div class="left-display">
        <img src="/assets/images/wingmate-logo.png" alt="WingMate Logo" class="logo-image">
    </div>
    <div class="right-display">
        <div class="form">
            <div class="title-text">
                <p>Welcome Back</p>
                <p2>Enter your login details below</p2>
            </div>
            <?php if ($loginError !== ''): ?>
                <p class="text-danger"><?php echo htmlspecialchars($loginError, ENT_QUOTES, 'UTF-8'); ?></p>
            <?php endif; ?>
            <?php if ($loginSuccess !== ''): ?>
                <p class="text-success"><?php echo htmlspecialchars($loginSuccess, ENT_QUOTES, 'UTF-8'); ?></p>
            <?php endif; ?>
            <div class="input-form">
                <form action="login.php" method="POST">
                    <div class="input-field">
                        <img src="/assets/images/mail-icon.svg" alt="" class="input-icon">
                        <input type="email" name="email" placeholder="Email" required>
                    </div>
                    <div class="input-field">
                        <img src="/assets/images/lock-icon.svg" alt="" class="input-icon">
                        <input type="password" name="password" placeholder="Password" required>
                    </div>
                    <div class="buttons">
                        <button type="button" onclick="window.location.href='/features/register/register.php'">Register</button>
                        <button class="login-button" type="submit">Login</button>
                    </div>
                </form>
            </div>
        </div>
    </div>
</div>

<?php include __DIR__ . '/../../includes/footer.php'; ?>    
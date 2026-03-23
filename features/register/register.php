<!--Requirements
1. Passwords not stored in plain text (Password hashing)
2. Secure session handling
3. Input validation and sanitization
4. Protection against basic SQL Injection
5. No hard-coded credentials 

Extras to remember:
1. Check to ensure user is over 18
2. Regexes to validate email and password formats
-->

<?php
require_once __DIR__ . '/../../includes/session.php';

wingmate_start_secure_session();

$registerError = '';
$registerSuccess = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!wingmate_validate_csrf_token($_POST['csrf_token'] ?? null)) {
        http_response_code(400);
        $registerError = 'Session validation failed. Please refresh and try again.';
    } else {
        //Process registration form submission and create user here
        session_regenerate_id(true);
        $registerSuccess = 'Submission Successful.';
    }
}
?>

<?php include __DIR__ . '/../../includes/auth-header.php';; ?>
<link rel="stylesheet" href="./register.css">
<div class="register-display">
    <div class="left-display">
        <img src="/assets/images/wingmate-logo.png" alt="WingMate Logo" class="logo-image">
    </div>
    <div class="right-display">
        <div class="form">
            <div class="title-text">
                <p>Welcome to Wingmate</p>
                <p2>Enter your personal details below</p2>
            </div>
            <?php if ($registerError !== ''): ?>
                <p class="text-danger"><?php echo htmlspecialchars($registerError, ENT_QUOTES, 'UTF-8'); ?></p>
            <?php endif; ?>
            <?php if ($registerSuccess !== ''): ?>
                <p class="text-success"><?php echo htmlspecialchars($registerSuccess, ENT_QUOTES, 'UTF-8'); ?></p>
            <?php endif; ?>
            <div class="input-form">
                <form action="register.php" method="POST">
                    <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars(wingmate_get_csrf_token(), ENT_QUOTES, 'UTF-8'); ?>">
                    <div class="input-field">
                        <img src="/assets/images/mail-icon.svg" alt="" class="input-icon">
                        <input type="email" name="email" placeholder="Email" required>
                    </div>
                    <div class="input-field">
                        <img src="/assets/images/lock-icon.svg" alt="" class="input-icon">
                        <input type="password" name="password" placeholder="Password" required>
                    </div>
                    <div class="input-field">
                        <img src="/assets/images/lock-icon.svg" alt="" class="input-icon">
                        <input type="password" name="confirm_password" placeholder="Confirm Password" required>
                    </div>
                    <div class="input-field">
                        <img src="/assets/images/edit-icon.svg" alt="" class="input-icon">
                        <input type="text" name="first_name" placeholder="First Name" required>
                    </div>
                    <div class="input-field">
                        <img src="/assets/images/edit-icon.svg" alt="" class="input-icon">
                        <input type="text" name="last_name" placeholder="Last Name" required>
                    </div>
                    <div class="input-field">
                        <img src="/assets/images/calendar-icon.svg" alt="" class="input-icon">
                        <input type="text" name="dob" placeholder="Date of Birth" onfocus="this.type='date'" onblur="if(!this.value)this.type='text'" required>
                    </div>
                    <div class="buttons">
                        <button type="button" onclick="window.location.href='/features/login/login.php'">Login</button>
                        <button class="register-button" type="submit">Register</button>
                    </div>
                </form>
            </div>
        </div>
    </div>
</div>

<?php include __DIR__ . '/../../includes/footer.php'; ?>
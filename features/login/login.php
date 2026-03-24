<!--Requirements
1. Secure session handling
2. Input validation and sanitization
3. Protection against basic SQL Injection
4. Verify hashed password with password_verify()
5. No hard-coded credentials
-->

<?php

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    require_once __DIR__ . '/../../config/config.php';

    // TODO: Validate email and password inputs
    // TODO: Query database for user by email
    // TODO: Verify password with password_verify()
    // TODO: Start session and redirect on success
    // TODO: Handle login failure (invalid credentials)

    $conn->close();
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

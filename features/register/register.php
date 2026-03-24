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
require_once __DIR__ . '/../../config/config.php';

wingmate_start_secure_session();

$registerError = '';
$registerSuccess = '';
$email = '';
$password = '';
$confirm_password = '';
$first_name = '';
$last_name = '';
$age = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!wingmate_validate_csrf_token($_POST['csrf_token'] ?? null)) {
        http_response_code(400);
        $registerError = 'Session validation failed. Please refresh and try again.';
    } else {
        $email = test_input($_POST['email']);
        $password = test_input($_POST['password']);
        $confirm_password = test_input($_POST['confirm_password']);
        $first_name = test_input($_POST['first_name']);
        $last_name = test_input($_POST['last_name']);
        $age = test_input($_POST['dob']);

        //Validation needs:
        //1. Email format validation
        //2. Password strength validation (length, character types)
        //3. Password confirmation match
        //4. Age verification (over 18)
        //5. Check if email already exists in database
        //6. Sanitize all inputs to prevent XSS and SQL Injection
        //7. Hash password before storing in database
        //8. Provide user feedback on validation errors or successful registration

        $hashedPassword = password_hash($password, PASSWORD_BCRYPT);

        $stmt = $conn->prepare("INSERT INTO Users (email, password_hash, created_at, user_type, account_status, suspended_until) 
        VALUES (?, ?, NOW(), 'standard', 'active', NULL)");
        $stmt->bind_param("ss", $email, $hashedPassword);
        $stmt->execute();
        if ($stmt->affected_rows === 1) {
            echo "New record created successfully";
        } else {
            echo "Error: Creating user failed: ";
        }

        session_regenerate_id(true);
        $registerSuccess = 'Submission Successful.';
    }
}

function test_input($data) {
  $data = trim($data);
  $data = stripslashes($data);
  $data = htmlspecialchars($data);
  return $data;
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
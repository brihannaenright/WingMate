<?php
require_once __DIR__ . '/../../config/config.php';
require_once __DIR__ . '/../../includes/session.php';
wingmate_start_secure_session();

$loginError = '';
$loginSuccess = '';
$email = '';
$password = '';

//Checks for any errors, grabs the error message and then unsets the session variable
if (isset($_SESSION['login_error'])) {
    $loginError = (string) $_SESSION['login_error'];
    unset($_SESSION['login_error']);
}

//Checks for any previous input in login form, grabs the input and then unsets the session variable (so data doesn't persist indefinitely)
if (isset($_SESSION['login_form']) && is_array($_SESSION['login_form'])) {
    $email = (string) ($_SESSION['login_form']['email'] ?? '');
    $password = (string) ($_SESSION['login_form']['password'] ?? '');
    unset($_SESSION['login_form']);
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    // Validate CSRF token before processing login
    if (!wingmate_validate_csrf_token($_POST['csrf_token'] ?? null)) {
        http_response_code(400);
        $loginError = 'Session validation failed. Please refresh and try again.';
    } else {
        $email = clean_input($_POST['email']);
        $password = clean_input($_POST['password']);
    
        try {
            $stmt = $conn->prepare("SELECT user_id, password_hash, user_type, account_status, suspended_until FROM Users WHERE email = ?");
            $stmt->bind_param("s", $email);
            $stmt->execute();
            $result = $stmt->get_result();

            if ($result->num_rows === 1) {
                $user = $result->fetch_assoc();
                // Verify password against stored hash
                if (password_verify($password, $user['password_hash'])) {
                    // Block banned accounts permanently
                    if ($user['account_status'] === 'banned') {
                        $loginError = 'Your account has been permanently banned.';
                    // Block suspended accounts (check if suspension has expired)
                    } elseif ($user['account_status'] === 'suspended') {
                        // Auto-unsuspend if the suspension period has passed
                        if (!empty($user['suspended_until']) && strtotime($user['suspended_until']) <= time()) {
                            $stmt2 = $conn->prepare("UPDATE Users SET account_status = 'active', suspended_until = NULL WHERE user_id = ?");
                            $stmt2->bind_param("i", $user['user_id']);
                            $stmt2->execute();
                            $stmt2->close();
                            $user['account_status'] = 'active';
                        } else {
                            $loginError = 'Your account is suspended until ' . htmlspecialchars($user['suspended_until']) . '.';
                        }
                    }

                    if ($user['account_status'] === 'active') {
                        session_regenerate_id(true);
                        $_SESSION['user_id'] = $user['user_id'];
                        $_SESSION['user_type'] = $user['user_type'];

                        // Redirect admins to admin dashboard, standard users to profile page
                        if ($user['user_type'] === 'administrator') {
                            header('Location: /features/admin/admin.php');
                        } else {
                            header('Location: /features/profile/profile.php');
                        }
                        exit;
                    }
                } else {
                    $loginError = 'Incorrect email or password.';
                }
            } else {
                $loginError = 'Incorrect email or password.';
            }
        } catch (Exception $e) {
            $loginError = 'Login failed due to a server error. Please try again later.';
        }
    }

    //If there was an error, store the error message and previous input in session variables and redirect back to login page (generating a new CSRF token in the process)
    if ($loginError !== '') {
        $_SESSION['login_error'] = $loginError;
        header('Location: /features/auth/login.php');
        exit;
    }
}

function clean_input($data) {
  $data = trim($data);
  $data = stripslashes($data);
  $data = htmlspecialchars($data, ENT_QUOTES, 'UTF-8');
  return $data;
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
            <div class="auth-input-form">
                <form action="login.php" method="POST">
                    <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars(wingmate_get_csrf_token(), ENT_QUOTES, 'UTF-8'); ?>">
                    <!--Displays error message if there is one-->
                    <div class="auth-input-group">
                        <?php if ($loginError !== ''): ?>
                            <p class="auth-general-error"><?php echo htmlspecialchars($loginError, ENT_QUOTES, 'UTF-8'); ?></p>
                        <?php endif; ?>
                        <div class="auth-input-field <?php echo $loginError !== '' ? 'auth-input-field-error' : ''; ?>">
                            <img src="/assets/images/mail-icon.svg" alt="" class="input-icon">
                            <input type="email" name="email" placeholder="Email" required>
                        </div>
                    </div>
                        <div class="auth-input-field <?php echo $loginError !== '' ? 'auth-input-field-error' : ''; ?>">
                            <img src="/assets/images/lock-icon.svg" alt="" class="input-icon">
                            <input type="password" name="password" placeholder="Password" required>
                        </div>
                    <a href="/features/auth/forgot_password.php" class="forgot-link">Forgot password?</a>
                    <div class="buttons">
                        <button type="button" onclick="window.location.href='/features/auth/register.php'">Register</button>
                        <button class="button-secondary" type="submit">Login</button>
                        <?php if ($loginSuccess !== ''): ?>
                            <p class="auth-success"><?php echo htmlspecialchars($loginSuccess, ENT_QUOTES, 'UTF-8'); ?></p>
                        <?php endif; ?>
                    </div>
                </form>
            </div>
        </div>
    </div>
</main>

<?php include __DIR__ . '/../../includes/footer.php'; ?>    
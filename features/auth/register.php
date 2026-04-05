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
// Initialise field-error tracking for form validation
$fieldErrors = [
    'email' => '',
    'password' => '',
    'confirm_password' => '',
    'first_name' => '',
    'last_name' => '',
    'dob' => '',
];

// Retrieve error messages and form data from previous submission (if validation failed)
if (isset($_SESSION['register_error'])) {
    $registerError = (string) $_SESSION['register_error'];
    unset($_SESSION['register_error']);
}

if (isset($_SESSION['register_field_errors']) && is_array($_SESSION['register_field_errors'])) {
    foreach ($fieldErrors as $key => $value) {
        $fieldErrors[$key] = (string) ($_SESSION['register_field_errors'][$key] ?? '');
    }
    unset($_SESSION['register_field_errors']);
}

if (isset($_SESSION['register_form']) && is_array($_SESSION['register_form'])) {
    $email = (string) ($_SESSION['register_form']['email'] ?? '');
    $first_name = (string) ($_SESSION['register_form']['first_name'] ?? '');
    $last_name = (string) ($_SESSION['register_form']['last_name'] ?? '');
    $age = (string) ($_SESSION['register_form']['dob'] ?? '');
    unset($_SESSION['register_form']);
}

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

        if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
            $fieldErrors['email'] = 'Please enter a valid email address.';
         }

         // Validate password
         if (strlen($password) < 8 ||
            !preg_match('/[A-Z]/', $password) ||
            !preg_match('/[a-z]/', $password) ||
            !preg_match('/[0-9]/', $password) ||
            !preg_match('/[!@#$%^&*()_\-+=\[\]{};:,.<>?\/\\\\|~`]/', $password) ||
            preg_match('/\s/', $password)) {
            $fieldErrors['password'] = 'Use at least 8 characters with at least one uppercase letter, one lowercase letter, one number, and one special character.';
        }

        if ($password !== $confirm_password) {
            $fieldErrors['confirm_password'] = 'Passwords do not match.';
        }

        if ($first_name === '') {
            $fieldErrors['first_name'] = 'First name is required.';
        }

        if ($last_name === '') {
            $fieldErrors['last_name'] = 'Last name is required.';
        }

        // Validate date of birth
        $today = new DateTime('today');
        $dob = DateTime::createFromFormat('Y-m-d', $age);
        if (!$dob) {
            $fieldErrors['dob'] = 'Please enter a valid date of birth.';
        } elseif ($dob > $today) {
            $fieldErrors['dob'] = 'Date of birth cannot be in the future.';
        } elseif ($today->diff($dob)->y < 18) {
            $fieldErrors['dob'] = 'You must be at least 18 years old to register.';
        } elseif ($today->diff($dob)->y > 100) {
            $fieldErrors['dob'] = 'Please enter a realistic date of birth.';
        }

        if (!has_field_errors($fieldErrors)) {
            // Check if email is already registered
            try {
                $stmt = $conn->prepare("SELECT user_id FROM Users WHERE email = ?");
                $stmt->bind_param("s", $email);
                $stmt->execute();
                $stmt->store_result();
                if ($stmt->num_rows > 0) {
                    $fieldErrors['email'] = 'An account with this email already exists.';
                }
                $stmt->close();
            } catch (Exception $e) {
                $registerError = 'An error occurred while checking for existing email. Please try again.';
            }
        }

        if ($registerError === '' && !has_field_errors($fieldErrors)) {
        $hashedPassword = password_hash($password, PASSWORD_BCRYPT);

        $conn->begin_transaction();

        try {
            // Insert user credentials
            $stmt = $conn->prepare("INSERT INTO Users (email, password_hash, created_at, user_type, account_status, suspended_until) 
            VALUES (?, ?, NOW(), 'standard', 'active', NULL)");
            $stmt->bind_param("ss", $email, $hashedPassword);
            $stmt->execute();
            $stmt->close();

            $newUserId = $conn->insert_id;

            // Update user profile with personal details
            $stmt2 = $conn->prepare("UPDATE User_Profile SET first_name = ?, last_name = ?, date_of_birth = ? WHERE user_id = ?");
            $stmt2->bind_param("sssi", $first_name, $last_name, $age, $newUserId);
            $stmt2->execute();
            $stmt2->close();

            $conn->commit();
            session_regenerate_id(true);
            $_SESSION['user_id'] = $newUserId;
            header('Location: /features/profile/profile.php');
            exit;
        } catch (Exception $e) {
            $conn->rollback();
            $registerError = 'Registration failed. Please try again.';
        }
        }

        if ($registerError !== '') {
            $_SESSION['register_error'] = $registerError;
        }

        // If there are validation errors, store them in session and redirect back to form with user input preserved
        if ($registerError !== '' || has_field_errors($fieldErrors)) {
            $_SESSION['register_error'] = $registerError;
            $_SESSION['register_field_errors'] = $fieldErrors;
            $_SESSION['register_form'] = [
                'email' => $email,
                'first_name' => $first_name,
                'last_name' => $last_name,
                'dob' => $age,
            ];
            header('Location: /features/auth/register.php');
            exit;
        }
    }
}

function has_field_errors($fieldErrors) {
    foreach ($fieldErrors as $error) {
        if ((string) $error !== '') {
            return true;
        }
    }

    return false;
}

function test_input($data) {
  $data = trim($data);
  $data = stripslashes($data);
  $data = htmlspecialchars($data);
  return $data;
}
?>

<?php include __DIR__ . '/../../includes/auth-header.php';; ?>
<link rel="stylesheet" href="./auth.css">
<main class="auth-display container-fluid d-flex min-vh-100 px-0">
    <div class="auth-left-display d-none d-md-flex col-md-6 justify-content-center align-items-center">
        <img src="/assets/images/wingmate-logo.png" alt="WingMate Logo" class="logo-image">
    </div>
    <div class="auth-right-display d-flex col-12 col-md-6 justify-content-center align-items-center">
        <div class="auth-form-display">
            <p>Welcome to Wingmate</p>
            <p2>Enter your personal details below</p2>
            <?php if ($registerError !== ''): ?>
                <p class="auth-general-error"><?php echo htmlspecialchars($registerError, ENT_QUOTES, 'UTF-8'); ?></p>
            <?php endif; ?>
            <div class="auth-input-form">
                <form action="register.php" method="POST">
                    <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars(wingmate_get_csrf_token(), ENT_QUOTES, 'UTF-8'); ?>">
                    <div class="auth-input-group">
                        <div class="auth-input-field <?php echo $fieldErrors['email'] !== '' ? ' auth-input-field-error' : ''; ?>">
                            <img src="/assets/images/mail-icon.svg" alt="" class="input-icon">
                            <input type="email" name="email" placeholder="Email" value="<?php echo htmlspecialchars($email, ENT_QUOTES, 'UTF-8'); ?>" required>
                        </div>
                        <?php if ($fieldErrors['email'] !== ''): ?>
                            <p class="auth-field-error"><span class="auth-error-icon">!</span><?php echo htmlspecialchars($fieldErrors['email'], ENT_QUOTES, 'UTF-8'); ?></p>
                        <?php endif; ?>
                    </div>
                    <div class="auth-input-group">
                        <div class="auth-input-field<?php echo $fieldErrors['password'] !== '' ? ' auth-input-field-error' : ''; ?>">
                            <img src="/assets/images/lock-icon.svg" alt="" class="input-icon">
                            <input type="password" name="password" placeholder="Password" required>
                        </div>
                        <?php if ($fieldErrors['password'] !== ''): ?>
                            <p class="auth-field-error"><span class="auth-error-icon">!</span><?php echo htmlspecialchars($fieldErrors['password'], ENT_QUOTES, 'UTF-8'); ?></p>
                        <?php endif; ?>
                    </div>
                    <div class="auth-input-group">
                        <div class="auth-input-field<?php echo $fieldErrors['confirm_password'] !== '' ? ' auth-input-field-error' : ''; ?>">
                            <img src="/assets/images/lock-icon.svg" alt="" class="input-icon">
                            <input type="password" name="confirm_password" placeholder="Confirm Password" required>
                        </div>
                        <?php if ($fieldErrors['confirm_password'] !== ''): ?>
                            <p class="auth-field-error"><span class="auth-error-icon">!</span><?php echo htmlspecialchars($fieldErrors['confirm_password'], ENT_QUOTES, 'UTF-8'); ?></p>
                        <?php endif; ?>
                    </div>
                    <div class="auth-input-group">
                        <div class="auth-input-field<?php echo $fieldErrors['first_name'] !== '' ? ' auth-input-field-error' : ''; ?>">
                            <img src="/assets/images/edit-icon.svg" alt="" class="input-icon">
                            <input type="text" name="first_name" placeholder="First Name" value="<?php echo htmlspecialchars($first_name, ENT_QUOTES, 'UTF-8'); ?>" required>
                        </div>
                        <?php if ($fieldErrors['first_name'] !== ''): ?>
                            <p class="auth-field-error"><span class="auth-error-icon">!</span><?php echo htmlspecialchars($fieldErrors['first_name'], ENT_QUOTES, 'UTF-8'); ?></p>
                        <?php endif; ?>
                    </div>
                    <div class="auth-input-group">
                        <div class="auth-input-field<?php echo $fieldErrors['last_name'] !== '' ? ' auth-input-field-error' : ''; ?>">
                            <img src="/assets/images/edit-icon.svg" alt="" class="input-icon">
                            <input type="text" name="last_name" placeholder="Last Name" value="<?php echo htmlspecialchars($last_name, ENT_QUOTES, 'UTF-8'); ?>" required>
                        </div>
                        <?php if ($fieldErrors['last_name'] !== ''): ?>
                            <p class="auth-field-error"><span class="auth-error-icon">!</span><?php echo htmlspecialchars($fieldErrors['last_name'], ENT_QUOTES, 'UTF-8'); ?></p>
                        <?php endif; ?>
                    </div>
                    <div class="auth-input-group">
                        <div class="auth-input-field<?php echo $fieldErrors['dob'] !== '' ? ' auth-input-field-error' : ''; ?>">
                            <img src="/assets/images/calendar-icon.svg" alt="" class="input-icon">
                            <input type="text" name="dob" placeholder="Date of Birth" value="<?php echo htmlspecialchars($age, ENT_QUOTES, 'UTF-8'); ?>" onfocus="this.type='date'" onblur="if(!this.value)this.type='text'" required>
                        </div>
                        <?php if ($fieldErrors['dob'] !== ''): ?>
                            <p class="auth-field-error"><span class="auth-error-icon">!</span><?php echo htmlspecialchars($fieldErrors['dob'], ENT_QUOTES, 'UTF-8'); ?></p>
                        <?php endif; ?>
                    </div>
                    <div class="buttons">
                        <button type="button" onclick="window.location.href='/features/login/login.php'">Login</button>
                        <button class="button-secondary" type="submit">Register</button>
                        <?php if ($registerSuccess !== ''): ?>
                            <p class="auth-success"><?php echo htmlspecialchars($registerSuccess, ENT_QUOTES, 'UTF-8'); ?></p>
                        <?php endif; ?>
                    </div>
                </form>
            </div>
        </div>
    </div>
</main>

<?php include __DIR__ . '/../../includes/footer.php'; ?>
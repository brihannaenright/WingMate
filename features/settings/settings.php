<?php
require_once __DIR__ . '/../../includes/session.php';
require_once __DIR__ . '/../../config/config.php';

wingmate_start_secure_session();

// Redirect to login if not logged in
$current_user_id = $_SESSION['user_id'] ?? null;
if (!$current_user_id) {
    header('Location: /features/auth/login.php');
    exit;
}

// Determine which settings section to show (defaults to 'preferences')
$section = $_GET['section'] ?? 'preferences';

// Handle POST actions for saving preferences
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!wingmate_validate_csrf_token($_POST['csrf_token'] ?? null)) {
        http_response_code(400);
    } else {
        $action = $_POST['action'] ?? '';

        // Save account preferences
        if ($action === 'save_preferences') {
            $preferred_gender = $_POST['preferred_gender'] ?? null;
            $min_age = (int) ($_POST['min_age'] ?? 18);
            $max_age = (int) ($_POST['max_age'] ?? 100);
            $max_distance = (int) ($_POST['max_distance_km'] ?? 100);

            // Check if user already has preferences saved
            $stmt = $conn->prepare("SELECT preference_id FROM User_Preferences WHERE user_id = ?");
            $stmt->bind_param("i", $current_user_id);
            $stmt->execute();
            $result = $stmt->get_result();

            if ($result->num_rows > 0) {
                // Update existing preferences
                $stmt = $conn->prepare("UPDATE User_Preferences SET preferred_gender = ?, min_age = ?, max_age = ?, max_distance_km = ? WHERE user_id = ?");
                $stmt->bind_param("siiii", $preferred_gender, $min_age, $max_age, $max_distance, $current_user_id);
            } else {
                // Insert new preferences
                $stmt = $conn->prepare("INSERT INTO User_Preferences (user_id, preferred_gender, min_age, max_age, max_distance_km) VALUES (?, ?, ?, ?, ?)");
                $stmt->bind_param("isiii", $current_user_id, $preferred_gender, $min_age, $max_age, $max_distance);
            }
            $stmt->execute();
            $stmt->close();

            $_SESSION['settings_success'] = 'Preferences saved successfully.';
            header('Location: /features/settings/settings.php?section=preferences');
            exit;
        }
    }
}

// Fetch current user preferences
$preferences = null;
$stmt = $conn->prepare("SELECT preferred_gender, min_age, max_age, max_distance_km FROM User_Preferences WHERE user_id = ?");
$stmt->bind_param("i", $current_user_id);
$stmt->execute();
$result = $stmt->get_result();
if ($result->num_rows > 0) {
    $preferences = $result->fetch_assoc();
}
$stmt->close();

// Set defaults if no preferences exist yet
$preferred_gender = $preferences['preferred_gender'] ?? '';
$min_age = $preferences['min_age'] ?? 18;
$max_age = $preferences['max_age'] ?? 100;
$max_distance = $preferences['max_distance_km'] ?? 100;

// Check for success message
$settingsSuccess = '';
if (isset($_SESSION['settings_success'])) {
    $settingsSuccess = (string) $_SESSION['settings_success'];
    unset($_SESSION['settings_success']);
}

include __DIR__ . '/../../includes/nav-header.php';
?>
<link rel="stylesheet" href="/features/settings/settings.css">

<div class="container-fluid">
    <div class="row">
        <!-- Settings Sidebar -->
        <div class="col-lg-3 settings-sidebar d-lg-block">
            <button class="btn btn-light fw-bold w-100 mb-3 d-lg-none" type="button" data-bs-toggle="collapse"
                    data-bs-target="#settingsSidebarContent">
                Settings
            </button>
            <div class="collapse dont-collapse-lg" id="settingsSidebarContent">
                <!-- Account Settings and Privacy -->
                <div class="settings-menu mb-4">
                    <p class="settings-menu-title">Account Settings and Privacy</p>
                    <ul class="settings-menu-list">
                        <li><a href="#">Edit Account Details</a></li>
                        <li><a href="#">Account Privacy and Safety</a></li>
                        <li><a href="#">Notifications</a></li>
                        <li><a href="/features/auth/login.php">Log out</a></li>
                        <li><a href="#">Delete Account</a></li>
                    </ul>
                </div>

                <!-- Account Preferences -->
                <div class="settings-menu mb-4">
                    <p class="settings-menu-title">Account Preferences</p>
                    <ul class="settings-menu-list">
                        <li><a href="/features/settings/settings.php?section=preferences" class="<?php echo $section === 'preferences' ? 'active' : ''; ?>">Edit Account Preferences</a></li>
                    </ul>
                </div>

                <!-- Support -->
                <div class="settings-menu">
                    <p class="settings-menu-title">Support</p>
                    <ul class="settings-menu-list">
                        <li><a href="#">Contact Support</a></li>
                        <li><a href="#">Send Feedback</a></li>
                        <li><a href="#">Terms & Conditions</a></li>
                    </ul>
                </div>
            </div>
        </div>

        <!-- Settings Content Area -->
        <div class="col-lg-9 settings-content">
            <?php if ($section === 'preferences'): ?>
                <div class="settings-section">
                    <p class="settings-section-title">Account Preferences</p>
                    <p class="settings-section-subtitle">Set your preferences for potential matches.</p>

                    <!-- Success message -->
                    <?php if ($settingsSuccess !== ''): ?>
                        <p class="settings-success"><?php echo htmlspecialchars($settingsSuccess, ENT_QUOTES, 'UTF-8'); ?></p>
                    <?php endif; ?>

                    <form method="POST" action="settings.php?section=preferences">
                        <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars(wingmate_get_csrf_token(), ENT_QUOTES, 'UTF-8'); ?>">
                        <input type="hidden" name="action" value="save_preferences">

                        <div class="preferences-grid">
                            <!-- Left column -->
                            <div class="preferences-column">
                                <!-- Gender Preference -->
                                <div class="preference-field">
                                    <label>Gender Preference:</label>
                                    <select name="preferred_gender" class="preference-select">
                                        <option value="" <?php echo $preferred_gender === '' ? 'selected' : ''; ?>>Select</option>
                                        <option value="male" <?php echo $preferred_gender === 'male' ? 'selected' : ''; ?>>Male</option>
                                        <option value="female" <?php echo $preferred_gender === 'female' ? 'selected' : ''; ?>>Female</option>
                                        <option value="other" <?php echo $preferred_gender === 'other' ? 'selected' : ''; ?>>Other</option>
                                    </select>
                                </div>

                                <!-- Age Preference -->
                                <div class="preference-field">
                                    <label>Age Preference:</label>
                                    <div class="range-slider">
                                        <input type="range" name="min_age" min="18" max="100" value="<?php echo (int) $min_age; ?>" id="minAge">
                                        <input type="range" name="max_age" min="18" max="100" value="<?php echo (int) $max_age; ?>" id="maxAge">
                                    </div>
                                    <span class="range-label" id="ageRangeLabel"><?php echo (int) $min_age; ?> - <?php echo (int) $max_age; ?></span>
                                </div>

                                <!-- Distance Preference -->
                                <div class="preference-field">
                                    <label>Distance Preference:</label>
                                    <input type="range" name="max_distance_km" min="0" max="100" value="<?php echo (int) $max_distance; ?>" id="distanceSlider">
                                    <span class="range-label" id="distanceLabel"><?php echo (int) $max_distance; ?>km</span>
                                </div>
                            </div>

                            <!-- Right column -->
                            <div class="preferences-column">
                                <!-- Relationship Type -->
                                <div class="preference-field">
                                    <label>Relationship Type</label>
                                    <div class="preference-box">
                                        <p class="placeholder-text">Coming soon</p>
                                    </div>
                                </div>
                            </div>

                            <!-- Bottom row spanning full width -->
                            <div class="preference-field">
                                <label>My Preferred Interests/Attributes</label>
                                <div class="preference-box">
                                    <p class="placeholder-text">Coming soon</p>
                                </div>
                            </div>

                            <div class="preference-field">
                                <label>Set Dealbreakers:</label>
                                <div class="preference-box">
                                    <p class="placeholder-text">Coming soon</p>
                                </div>
                            </div>
                        </div>

                        <button type="submit" class="button-secondary settings-save-btn">Save Preferences</button>
                    </form>
                </div>
            <?php endif; ?>
        </div>
    </div>
</div>

<?php include __DIR__ . '/../../includes/footer.php'; ?>

<script>
document.addEventListener('DOMContentLoaded', function() {
    // Update age range label when sliders change
    const minAge = document.getElementById('minAge');
    const maxAge = document.getElementById('maxAge');
    const ageLabel = document.getElementById('ageRangeLabel');

    function updateAgeLabel() {
        // Make sure min doesn't go above max
        if (parseInt(minAge.value) > parseInt(maxAge.value)) {
            minAge.value = maxAge.value;
        }
        ageLabel.textContent = minAge.value + ' - ' + maxAge.value;
    }

    if (minAge && maxAge) {
        minAge.addEventListener('input', updateAgeLabel);
        maxAge.addEventListener('input', updateAgeLabel);
    }

    // Update distance label when slider changes
    const distanceSlider = document.getElementById('distanceSlider');
    const distanceLabel = document.getElementById('distanceLabel');

    if (distanceSlider) {
        distanceSlider.addEventListener('input', function() {
            distanceLabel.textContent = this.value + 'km';
        });
    }
});
</script>

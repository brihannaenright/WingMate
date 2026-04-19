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

// Handle POST actions
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';

    // AJAX: update looking_for tag selections (mirrors profile.php pattern — no CSRF to allow multiple saves per page load)
    if ($action === 'update_looking_for_tags') {
        header('Content-Type: application/json');
        $tagIds = json_decode($_POST['tag_ids'] ?? '[]', true);

        $stmt = $conn->prepare("DELETE FROM User_Tags WHERE user_id = ? AND selection_type = 'looking_for'");
        $stmt->bind_param('i', $current_user_id);
        $stmt->execute();
        $stmt->close();

        if (!empty($tagIds) && is_array($tagIds)) {
            $stmt = $conn->prepare("INSERT INTO User_Tags (user_id, tag_id, selection_type) VALUES (?, ?, 'looking_for')");
            foreach ($tagIds as $tagId) {
                $tagId = intval($tagId);
                $stmt->bind_param('ii', $current_user_id, $tagId);
                $stmt->execute();
            }
            $stmt->close();
        }

        echo json_encode(['success' => true]);
        exit;
    }

    // Form submission: requires CSRF
    if (!wingmate_validate_csrf_token($_POST['csrf_token'] ?? null)) {
        http_response_code(400);
    } else {
        // Save account preferences (gender, age, distance, relationship type)
        if ($action === 'save_preferences') {
            // "all" means no gender filter → stored as NULL
            $preferred_gender_raw = $_POST['preferred_gender'] ?? 'all';
            $allowed_genders = ['male', 'female', 'non-binary'];
            $preferred_gender = in_array($preferred_gender_raw, $allowed_genders, true) ? $preferred_gender_raw : null;

            $min_age = max(18, min(100, (int) ($_POST['min_age'] ?? 18)));
            $max_age = max(18, min(100, (int) ($_POST['max_age'] ?? 100)));
            if ($min_age > $max_age) {
                $tmp = $min_age; $min_age = $max_age; $max_age = $tmp;
            }
            $max_distance = max(0, min(100, (int) ($_POST['max_distance_km'] ?? 100)));

            $relationship_type_raw = $_POST['relationship_type'] ?? '';
            $allowed_relationships = ['short_term', 'long_term', 'fun'];
            $relationship_type = in_array($relationship_type_raw, $allowed_relationships, true) ? $relationship_type_raw : null;

            $stmt = $conn->prepare("SELECT preference_id FROM User_Preferences WHERE user_id = ?");
            $stmt->bind_param("i", $current_user_id);
            $stmt->execute();
            $result = $stmt->get_result();

            if ($result->num_rows > 0) {
                $stmt = $conn->prepare("UPDATE User_Preferences SET preferred_gender = ?, min_age = ?, max_age = ?, max_distance_km = ?, relationship_type = ? WHERE user_id = ?");
                $stmt->bind_param("siiisi", $preferred_gender, $min_age, $max_age, $max_distance, $relationship_type, $current_user_id);
            } else {
                $stmt = $conn->prepare("INSERT INTO User_Preferences (user_id, preferred_gender, min_age, max_age, max_distance_km, relationship_type) VALUES (?, ?, ?, ?, ?, ?)");
                $stmt->bind_param("isiiis", $current_user_id, $preferred_gender, $min_age, $max_age, $max_distance, $relationship_type);
            }
            $stmt->execute();
            $stmt->close();

            $_SESSION['settings_success'] = 'Preferences saved successfully.';
            header('Location: /features/settings/settings.php');
            exit;
        }
    }
}

// Fetch current user preferences
$preferences = null;
$stmt = $conn->prepare("SELECT preferred_gender, min_age, max_age, max_distance_km, relationship_type FROM User_Preferences WHERE user_id = ?");
$stmt->bind_param("i", $current_user_id);
$stmt->execute();
$result = $stmt->get_result();
if ($result->num_rows > 0) {
    $preferences = $result->fetch_assoc();
}
$stmt->close();

$preferred_gender = $preferences['preferred_gender'] ?? null; // NULL = All
$min_age = $preferences['min_age'] ?? 18;
$max_age = $preferences['max_age'] ?? 100;
$max_distance = $preferences['max_distance_km'] ?? 100;
$relationship_type = $preferences['relationship_type'] ?? '';

// Fetch all tags for the looking_for picker
$allTags = [];
$result = $conn->query("SELECT tag_id, tag_name, tag_type FROM Tags ORDER BY tag_name");
while ($row = $result->fetch_assoc()) {
    $allTags[] = $row;
}

// Fetch user's existing looking_for tag selections
$lookingForTagIds = [];
$stmt = $conn->prepare("SELECT tag_id FROM User_Tags WHERE user_id = ? AND selection_type = 'looking_for'");
$stmt->bind_param('i', $current_user_id);
$stmt->execute();
$result = $stmt->get_result();
while ($row = $result->fetch_assoc()) {
    $lookingForTagIds[] = (int)$row['tag_id'];
}
$stmt->close();

$userLookingForTags = array_filter($allTags, fn($t) => in_array((int)$t['tag_id'], $lookingForTagIds));

$settingsSuccess = '';
if (isset($_SESSION['settings_success'])) {
    $settingsSuccess = (string) $_SESSION['settings_success'];
    unset($_SESSION['settings_success']);
}

include __DIR__ . '/../../includes/nav-header.php';
?>
<link rel="stylesheet" href="/features/settings/settings.css">

<div class="settings-page">
    <div class="settings-content">
        <div class="settings-section">
            <p class="settings-section-title">Account Preferences</p>
            <p class="settings-section-subtitle">Set your preferences for potential matches.</p>

            <?php if ($settingsSuccess !== ''): ?>
                <p class="settings-success"><?php echo htmlspecialchars($settingsSuccess, ENT_QUOTES, 'UTF-8'); ?></p>
            <?php endif; ?>

            <form method="POST" action="settings.php">
                <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars(wingmate_get_csrf_token(), ENT_QUOTES, 'UTF-8'); ?>">
                <input type="hidden" name="action" value="save_preferences">

                <div class="preferences-grid">
                    <div class="preferences-column">
                        <!-- Gender Preference (single-select checklist) -->
                        <div class="preference-field">
                            <label>Gender Preference:</label>
                            <div class="gender-checklist">
                                <?php
                                $genderOptions = [
                                    'male' => 'Male',
                                    'female' => 'Female',
                                    'non-binary' => 'Non-binary',
                                    'all' => 'All',
                                ];
                                $currentGender = $preferred_gender ?? 'all';
                                foreach ($genderOptions as $value => $label):
                                ?>
                                    <label class="gender-option">
                                        <input type="radio" name="preferred_gender" value="<?php echo $value; ?>"
                                               <?php echo $currentGender === $value ? 'checked' : ''; ?>>
                                        <span class="gender-checkbox"></span>
                                        <span class="gender-label"><?php echo $label; ?></span>
                                    </label>
                                <?php endforeach; ?>
                            </div>
                        </div>

                        <!-- Age Preference (custom dual-handle slider built from divs) -->
                        <div class="preference-field">
                            <label>Age Preference:</label>
                            <div class="age-slider" id="ageSlider" data-min="18" data-max="100">
                                <div class="age-slider-track"></div>
                                <div class="age-slider-fill" id="ageFill"></div>
                                <div class="age-slider-thumb" id="ageThumbMin" tabindex="0" role="slider"
                                     aria-label="Minimum age" aria-valuemin="18" aria-valuemax="100"
                                     aria-valuenow="<?php echo (int) $min_age; ?>"></div>
                                <div class="age-slider-thumb" id="ageThumbMax" tabindex="0" role="slider"
                                     aria-label="Maximum age" aria-valuemin="18" aria-valuemax="100"
                                     aria-valuenow="<?php echo (int) $max_age; ?>"></div>
                            </div>
                            <input type="hidden" name="min_age" id="minAge" value="<?php echo (int) $min_age; ?>">
                            <input type="hidden" name="max_age" id="maxAge" value="<?php echo (int) $max_age; ?>">
                            <span class="range-label" id="ageRangeLabel"><?php echo (int) $min_age; ?> - <?php echo (int) $max_age; ?></span>
                        </div>

                        <!-- Distance Preference -->
                        <div class="preference-field">
                            <label>Distance Preference:</label>
                            <input type="range" name="max_distance_km" min="0" max="100" value="<?php echo (int) $max_distance; ?>" id="distanceSlider">
                            <span class="range-label" id="distanceLabel"><?php echo (int) $max_distance; ?>km</span>
                        </div>
                    </div>

                    <div class="preferences-column">
                        <!-- Relationship Type -->
                        <div class="preference-field">
                            <label>Relationship Type:</label>
                            <select name="relationship_type" class="preference-select">
                                <option value="" <?php echo $relationship_type === '' ? 'selected' : ''; ?>>Select</option>
                                <option value="short_term" <?php echo $relationship_type === 'short_term' ? 'selected' : ''; ?>>Short term</option>
                                <option value="long_term" <?php echo $relationship_type === 'long_term' ? 'selected' : ''; ?>>Long term</option>
                                <option value="fun" <?php echo $relationship_type === 'fun' ? 'selected' : ''; ?>>Looking for fun</option>
                            </select>
                        </div>

                        <!-- Looking For Attributes -->
                        <div class="preference-field">
                            <label>Looking For (Attributes):</label>
                            <div class="looking-for-pills" id="lookingForPills">
                                <?php foreach ($userLookingForTags as $tag): ?>
                                    <span class="looking-for-pill"><?php echo htmlspecialchars($tag['tag_name']); ?></span>
                                <?php endforeach; ?>
                                <button type="button" class="looking-for-add" onclick="openLookingForPicker()">+</button>
                            </div>
                        </div>
                    </div>
                </div>

                <button type="submit" class="button-secondary settings-save-btn">Save Preferences</button>
            </form>
        </div>
    </div>
</div>

<!-- Tag Picker Modal for Looking For -->
<div class="modal fade" id="lookingForModal" tabindex="-1" aria-labelledby="lookingForModalLabel" aria-hidden="true">
    <div class="modal-dialog modal-dialog-centered modal-dialog-scrollable">
        <div class="modal-content settings-modal-content">
            <div class="modal-header settings-modal-header">
                <h5 class="modal-title" id="lookingForModalLabel">Looking For</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
            </div>
            <div class="modal-body">
                <div class="settings-tag-picker" id="lookingForPickerGrid"></div>
            </div>
            <div class="modal-footer settings-modal-footer">
                <button type="button" class="btn settings-btn-cancel" data-bs-dismiss="modal">Cancel</button>
                <button type="button" class="btn settings-btn-save" onclick="saveLookingForTags()">Save</button>
            </div>
        </div>
    </div>
</div>

<?php include __DIR__ . '/../../includes/footer.php'; ?>

<script>
const allTags = <?php echo json_encode($allTags); ?>;
let selectedLookingFor = <?php echo json_encode(array_values($lookingForTagIds)); ?>;

document.addEventListener('DOMContentLoaded', function() {
    // --- Custom dual-handle age slider (divs + JS, no <input type=range>) ---
    const slider = document.getElementById('ageSlider');
    const fill = document.getElementById('ageFill');
    const thumbMin = document.getElementById('ageThumbMin');
    const thumbMax = document.getElementById('ageThumbMax');
    const minInput = document.getElementById('minAge');
    const maxInput = document.getElementById('maxAge');
    const ageLabel = document.getElementById('ageRangeLabel');

    const RANGE_MIN = parseInt(slider.dataset.min, 10);
    const RANGE_MAX = parseInt(slider.dataset.max, 10);

    function valueToPercent(v) {
        return ((v - RANGE_MIN) / (RANGE_MAX - RANGE_MIN)) * 100;
    }

    function render() {
        const minV = parseInt(minInput.value, 10);
        const maxV = parseInt(maxInput.value, 10);
        const minPct = valueToPercent(minV);
        const maxPct = valueToPercent(maxV);

        thumbMin.style.left = minPct + '%';
        thumbMax.style.left = maxPct + '%';
        fill.style.left = minPct + '%';
        fill.style.right = (100 - maxPct) + '%';

        thumbMin.setAttribute('aria-valuenow', minV);
        thumbMax.setAttribute('aria-valuenow', maxV);
        ageLabel.textContent = minV + ' - ' + maxV;
    }

    function startDrag(thumb, which) {
        thumb.addEventListener('pointerdown', function(e) {
            e.preventDefault();
            thumb.setPointerCapture(e.pointerId);

            function onMove(ev) {
                const rect = slider.getBoundingClientRect();
                const pct = Math.max(0, Math.min(1, (ev.clientX - rect.left) / rect.width));
                let value = Math.round(RANGE_MIN + pct * (RANGE_MAX - RANGE_MIN));

                if (which === 'min') {
                    value = Math.min(value, parseInt(maxInput.value, 10));
                    minInput.value = value;
                } else {
                    value = Math.max(value, parseInt(minInput.value, 10));
                    maxInput.value = value;
                }
                render();
            }

            function onUp(ev) {
                thumb.releasePointerCapture(e.pointerId);
                thumb.removeEventListener('pointermove', onMove);
                thumb.removeEventListener('pointerup', onUp);
                thumb.removeEventListener('pointercancel', onUp);
            }

            thumb.addEventListener('pointermove', onMove);
            thumb.addEventListener('pointerup', onUp);
            thumb.addEventListener('pointercancel', onUp);
        });

        // Keyboard support: arrow keys to nudge
        thumb.addEventListener('keydown', function(e) {
            const step = (e.key === 'ArrowLeft' || e.key === 'ArrowDown') ? -1
                        : (e.key === 'ArrowRight' || e.key === 'ArrowUp') ? 1 : 0;
            if (!step) return;
            e.preventDefault();
            if (which === 'min') {
                const next = Math.max(RANGE_MIN, Math.min(parseInt(maxInput.value, 10), parseInt(minInput.value, 10) + step));
                minInput.value = next;
            } else {
                const next = Math.max(parseInt(minInput.value, 10), Math.min(RANGE_MAX, parseInt(maxInput.value, 10) + step));
                maxInput.value = next;
            }
            render();
        });
    }

    startDrag(thumbMin, 'min');
    startDrag(thumbMax, 'max');
    render();

    // Distance slider label
    const distanceSlider = document.getElementById('distanceSlider');
    const distanceLabel = document.getElementById('distanceLabel');
    if (distanceSlider) {
        distanceSlider.addEventListener('input', function() {
            distanceLabel.textContent = this.value + 'km';
        });
    }
});

// --- Looking For tag picker ---
function openLookingForPicker() {
    const grid = document.getElementById('lookingForPickerGrid');
    if (!allTags || allTags.length === 0) {
        grid.innerHTML = '<p style="color:#808080; margin:0;">No attributes available.</p>';
    } else {
        grid.innerHTML = allTags.map(tag => {
            const isSelected = selectedLookingFor.includes(parseInt(tag.tag_id));
            const pillClass = isSelected ? 'settings-pill settings-pill--selected' : 'settings-pill settings-pill--unselected';
            return '<span class="' + pillClass + '" data-tag-id="' + tag.tag_id + '" onclick="toggleLookingForTag(this)">' + tag.tag_name + '</span>';
        }).join('');
    }
    bootstrap.Modal.getOrCreateInstance(document.getElementById('lookingForModal')).show();
}

function toggleLookingForTag(el) {
    const tagId = parseInt(el.dataset.tagId);
    const idx = selectedLookingFor.indexOf(tagId);
    if (idx > -1) {
        selectedLookingFor.splice(idx, 1);
        el.className = 'settings-pill settings-pill--unselected';
    } else {
        selectedLookingFor.push(tagId);
        el.className = 'settings-pill settings-pill--selected';
    }
}

function saveLookingForTags() {
    const formData = new FormData();
    formData.append('action', 'update_looking_for_tags');
    formData.append('tag_ids', JSON.stringify(selectedLookingFor));

    fetch(window.location.pathname, { method: 'POST', body: formData })
        .then(res => res.json())
        .then(data => {
            if (data.success) {
                updateLookingForPills();
                bootstrap.Modal.getInstance(document.getElementById('lookingForModal')).hide();
            } else {
                alert('Failed to save: ' + (data.error || 'Unknown error'));
            }
        })
        .catch(err => alert('Error: ' + err.message));
}

function updateLookingForPills() {
    const container = document.getElementById('lookingForPills');
    const addBtn = container.querySelector('.looking-for-add');
    container.innerHTML = '';
    allTags.filter(t => selectedLookingFor.includes(parseInt(t.tag_id)))
        .forEach(tag => {
            const pill = document.createElement('span');
            pill.className = 'looking-for-pill';
            pill.textContent = tag.tag_name;
            container.appendChild(pill);
        });
    container.appendChild(addBtn);
}
</script>

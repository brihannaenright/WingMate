<?php
require_once __DIR__ . '/../../includes/session.php';
require_once __DIR__ . '/../../config/config.php';

wingmate_start_secure_session();

$current_user_id = $_SESSION['user_id'] ?? null;
if (!$current_user_id) {
    header('Location: /features/auth/login.php');
    exit;
}

// Handle POST actions
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';

    // --- Swipe action ---
    if ($action === 'swipe') {
        header('Content-Type: application/json');
        $likedId = intval($_POST['liked_id'] ?? 0);
        $swipeType = $_POST['swipe_type'] ?? '';

        if ($likedId <= 0 || $likedId === (int)$current_user_id) {
            echo json_encode(['success' => false, 'error' => 'Invalid target user']);
            exit;
        }
        if (!in_array($swipeType, ['like', 'dislike'], true)) {
            echo json_encode(['success' => false, 'error' => 'Invalid swipe type']);
            exit;
        }

        $stmt = $conn->prepare("INSERT INTO User_Swipe (liker_id, liked_id, swipe_type) VALUES (?, ?, ?)");
        $stmt->bind_param('iis', $current_user_id, $likedId, $swipeType);
        $stmt->execute();
        $stmt->close();

        if ($swipeType === 'like') {
            $stmt = $conn->prepare("SELECT 1 FROM User_Swipe WHERE liker_id = ? AND liked_id = ? AND swipe_type = 'like' LIMIT 1");
            $stmt->bind_param('ii', $likedId, $current_user_id);
            $stmt->execute();
            $isMutual = $stmt->get_result()->num_rows > 0;
            $stmt->close();

            if ($isMutual) {
                $user1 = min((int)$current_user_id, (int)$likedId);
                $user2 = max((int)$current_user_id, (int)$likedId);

                $stmt = $conn->prepare("SELECT match_id FROM Matches WHERE user1_id = ? AND user2_id = ?");
                $stmt->bind_param('ii', $user1, $user2);
                $stmt->execute();
                $existingMatch = $stmt->get_result()->fetch_assoc();
                $stmt->close();

                if ($existingMatch) {
                    $matchId = (int)$existingMatch['match_id'];
                } else {
                    $stmt = $conn->prepare("INSERT INTO Matches (user1_id, user2_id, status, matched_at) VALUES (?, ?, 'pending', NOW())");
                    $stmt->bind_param('ii', $user1, $user2);
                    $stmt->execute();
                    $matchId = $stmt->insert_id;
                    $stmt->close();
                }

                $stmt = $conn->prepare("INSERT INTO Match_Requests (match_id, match_owner_id, matched_user_id, status, created_at) VALUES (?, ?, ?, 'pending', NOW())");
                $stmt->bind_param('iii', $matchId, $current_user_id, $likedId);
                $stmt->execute();
                $request1Id = $stmt->insert_id;
                $stmt->close();

                $stmt = $conn->prepare("INSERT INTO Match_Requests (match_id, match_owner_id, matched_user_id, status, created_at) VALUES (?, ?, ?, 'pending', NOW())");
                $stmt->bind_param('iii', $matchId, $likedId, $current_user_id);
                $stmt->execute();
                $request2Id = $stmt->insert_id;
                $stmt->close();

                $stmt = $conn->prepare(
                    "INSERT INTO Notifications (recipient_id, notification_type, reference_type, reference_id, created_at)
                     SELECT CASE WHEN user_id = ? THEN friend_id ELSE user_id END, 'vote_request', 'match_request', ?, NOW()
                     FROM Friendship WHERE status='accepted' AND (user_id = ? OR friend_id = ?)"
                );
                $stmt->bind_param('iiii', $current_user_id, $request1Id, $current_user_id, $current_user_id);
                $stmt->execute();
                $stmt->close();

                $stmt = $conn->prepare(
                    "INSERT INTO Notifications (recipient_id, notification_type, reference_type, reference_id, created_at)
                     SELECT CASE WHEN user_id = ? THEN friend_id ELSE user_id END, 'vote_request', 'match_request', ?, NOW()
                     FROM Friendship WHERE status='accepted' AND (user_id = ? OR friend_id = ?)"
                );
                $stmt->bind_param('iiii', $likedId, $request2Id, $likedId, $likedId);
                $stmt->execute();
                $stmt->close();
            }
        }

        echo json_encode(['success' => true]);
        exit;
    }

    // --- Update looking_for tags (AJAX, moved from settings.php) ---
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

    // --- Save preferences form (CSRF-required, moved from settings.php) ---
    if (!wingmate_validate_csrf_token($_POST['csrf_token'] ?? null)) {
        http_response_code(400);
    } else if ($action === 'save_preferences') {
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

        header('Location: /features/swipe/swipe.php');
        exit;
    }
}

// Load viewer profile
$stmt = $conn->prepare("SELECT gender, latitude, longitude FROM User_Profile WHERE user_id = ?");
$stmt->bind_param('i', $current_user_id);
$stmt->execute();
$viewer = $stmt->get_result()->fetch_assoc();
$stmt->close();

// Load viewer preferences
$stmt = $conn->prepare("SELECT preferred_gender, min_age, max_age, max_distance_km, relationship_type FROM User_Preferences WHERE user_id = ?");
$stmt->bind_param('i', $current_user_id);
$stmt->execute();
$prefs = $stmt->get_result()->fetch_assoc();
$stmt->close();

// Load viewer looking_for tag ids
$viewerLookingForTagIds = [];
$stmt = $conn->prepare("SELECT tag_id FROM User_Tags WHERE user_id = ? AND selection_type = 'looking_for'");
$stmt->bind_param('i', $current_user_id);
$stmt->execute();
$result = $stmt->get_result();
while ($row = $result->fetch_assoc()) {
    $viewerLookingForTagIds[] = (int)$row['tag_id'];
}
$stmt->close();

// Load all tags for the looking_for picker
$allTags = [];
$result = $conn->query("SELECT tag_id, tag_name, tag_type FROM Tags ORDER BY tag_name");
while ($row = $result->fetch_assoc()) {
    $allTags[] = $row;
}
$userLookingForTags = array_filter($allTags, fn($t) => in_array((int)$t['tag_id'], $viewerLookingForTagIds));

// Friend count (swipe requires at least one)
$stmt = $conn->prepare("SELECT COUNT(*) AS c FROM Friendship WHERE status='accepted' AND (user_id = ? OR friend_id = ?)");
$stmt->bind_param('ii', $current_user_id, $current_user_id);
$stmt->execute();
$friendCount = (int)$stmt->get_result()->fetch_assoc()['c'];
$stmt->close();

// Pre-checks
$locationMissing = !$viewer || $viewer['latitude'] === null || $viewer['longitude'] === null;
$friendsMissing = $friendCount === 0;
$prefsIncomplete = !$prefs
    || $prefs['min_age'] === null || $prefs['max_age'] === null
    || $prefs['max_distance_km'] === null || (int)$prefs['max_distance_km'] <= 0
    || $prefs['relationship_type'] === null
    || empty($viewerLookingForTagIds);

// Form defaults (used when prefs missing)
$preferred_gender = $prefs['preferred_gender'] ?? null; // NULL = All
$min_age = $prefs['min_age'] ?? 18;
$max_age = $prefs['max_age'] ?? 100;
$max_distance = $prefs['max_distance_km'] ?? 100;
$relationship_type = $prefs['relationship_type'] ?? '';

// Build candidates only when viewer has complete setup
$candidatesList = [];
if (!$locationMissing && !$friendsMissing && !$prefsIncomplete) {
    $viewerLat = (float)$viewer['latitude'];
    $viewerLng = (float)$viewer['longitude'];
    $prefGender = $prefs['preferred_gender'];
    $minAge = (int)$prefs['min_age'];
    $maxAge = (int)$prefs['max_age'];
    $maxDistance = (int)$prefs['max_distance_km'];

    // Candidates matching viewer prefs + haversine distance in km.
    $sql = "SELECT c.user_id, c.first_name, c.user_bio, c.general_location, c.gender,
                   TIMESTAMPDIFF(YEAR, c.date_of_birth, CURDATE()) AS age,
                   (SELECT relationship_type FROM User_Preferences WHERE user_id = c.user_id) AS relationship_type,
                   ROUND(6371 * acos(
                       cos(radians(?)) * cos(radians(c.latitude)) *
                       cos(radians(c.longitude) - radians(?)) +
                       sin(radians(?)) * sin(radians(c.latitude))
                   )) AS distance_km
            FROM User_Profile c
            WHERE c.user_id != ?
              AND TIMESTAMPDIFF(YEAR, c.date_of_birth, CURDATE()) BETWEEN ? AND ?
              AND c.latitude IS NOT NULL AND c.longitude IS NOT NULL
              AND c.user_id NOT IN (SELECT liked_id FROM User_Swipe WHERE liker_id = ?)
              AND c.user_id NOT IN (SELECT blocked_id FROM User_Blocks WHERE blocker_id = ?)
              AND c.user_id NOT IN (SELECT blocker_id FROM User_Blocks WHERE blocked_id = ?)";

    $types = 'dddiiiiii';
    $params = [$viewerLat, $viewerLng, $viewerLat,
               $current_user_id,
               $minAge, $maxAge,
               $current_user_id, $current_user_id, $current_user_id];

    if ($prefGender !== null && $prefGender !== '') {
        $sql .= " AND c.gender = ?";
        $types .= 's';
        $params[] = $prefGender;
    }

    if (!empty($viewerLookingForTagIds)) {
        $placeholders = implode(',', array_fill(0, count($viewerLookingForTagIds), '?'));
        $sql .= " AND EXISTS (SELECT 1 FROM User_Tags ut
                              WHERE ut.user_id = c.user_id
                                AND ut.selection_type = 'about_me'
                                AND ut.tag_id IN ($placeholders))";
        $types .= str_repeat('i', count($viewerLookingForTagIds));
        foreach ($viewerLookingForTagIds as $tid) {
            $params[] = $tid;
        }
    }

    $sql .= " HAVING distance_km <= ? ORDER BY distance_km ASC";
    $types .= 'i';
    $params[] = $maxDistance;

    $stmt = $conn->prepare($sql);
    $stmt->bind_param($types, ...$params);
    $stmt->execute();
    $result = $stmt->get_result();
    $candidates = [];
    while ($row = $result->fetch_assoc()) {
        $row['user_id'] = (int)$row['user_id'];
        $row['age'] = (int)$row['age'];
        $row['distance_km'] = (int)$row['distance_km'];
        $row['relationship_type'] = $row['relationship_type'] ?? null;
        $row['photos'] = [];
        $row['primary_photo'] = null;
        $row['about_me_tags'] = [];
        $row['looking_for_tags'] = [];
        $candidates[$row['user_id']] = $row;
    }
    $stmt->close();

    $candidateIds = array_keys($candidates);

    if (!empty($candidateIds)) {
        $idList = implode(',', $candidateIds);

        $res = $conn->query("SELECT user_id, photo_id, photo_url, is_primary
                             FROM User_Pictures
                             WHERE user_id IN ($idList) AND is_removed = 0
                             ORDER BY is_primary DESC, uploaded_at DESC");
        while ($row = $res->fetch_assoc()) {
            $uid = (int)$row['user_id'];
            $photo = [
                'photo_id'  => (int)$row['photo_id'],
                'photo_url' => '/Uploads/' . $row['photo_url'],
            ];
            if ((int)$row['is_primary'] === 1 && $candidates[$uid]['primary_photo'] === null) {
                $candidates[$uid]['primary_photo'] = $photo;
            } else {
                $candidates[$uid]['photos'][] = $photo;
            }
        }

        $res = $conn->query("SELECT ut.user_id, t.tag_name, ut.selection_type
                             FROM User_Tags ut
                             JOIN Tags t ON t.tag_id = ut.tag_id
                             WHERE ut.user_id IN ($idList)");
        while ($row = $res->fetch_assoc()) {
            $uid = (int)$row['user_id'];
            if ($row['selection_type'] === 'about_me') {
                $candidates[$uid]['about_me_tags'][] = $row['tag_name'];
            } elseif ($row['selection_type'] === 'looking_for') {
                $candidates[$uid]['looking_for_tags'][] = $row['tag_name'];
            }
        }
    }

    $candidatesList = array_values($candidates);
}
?>

<?php include __DIR__ . '/../../includes/nav-header.php'; ?>

<link rel="stylesheet" href="/features/settings/settings.css">
<link rel="stylesheet" href="swipe.css">

<div class="swipe-page">

    <?php if ($locationMissing): ?>
        <div class="alert-wingmate">
            Set your location in <a href="/features/settings/settings.php">Settings</a> before swiping.
        </div>
    <?php endif; ?>
    <?php if ($friendsMissing): ?>
        <div class="alert-wingmate">
            Add at least one <a href="/features/friends/friends.php">friend</a> before swiping.
        </div>
    <?php endif; ?>

    <!-- Match Filters -->
    <details class="swipe-filters" <?php echo $prefsIncomplete ? 'open' : ''; ?>>
        <summary class="swipe-filters-summary">Match Filters</summary>
        <form method="POST" action="/features/swipe/swipe.php" class="swipe-filters-form">
            <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars(wingmate_get_csrf_token(), ENT_QUOTES, 'UTF-8'); ?>">
            <input type="hidden" name="action" value="save_preferences">

            <div class="preferences-grid">
                <div class="preferences-column">
                    <!-- Gender Preference -->
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

                    <!-- Age Preference -->
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
    </details>

    <?php if (!$locationMissing && !$friendsMissing && !$prefsIncomplete): ?>
        <div class="swipe-container"<?php echo empty($candidatesList) ? ' style="display:none;"' : ''; ?>>

            <!-- 1. Banner (Top Left) -->
            <div class="swipe-banner">
                <div class="swipe-banner-bg"></div>
                <div class="swipe-avatar-wrapper">
                    <img src="" alt="Profile photo" class="swipe-avatar">
                </div>
                <div class="swipe-info-card">
                    <h1 class="swipe-name-age"></h1>
                    <div class="swipe-location">
                        <span class="swipe-location-icon"></span>
                    </div>
                    <div class="swipe-gender"></div>
                    <p class="swipe-bio"></p>
                </div>
            </div>

            <!-- 2. Photo Carousel (Top Right) -->
            <div class="swipe-photos-card">
                <img class="swipe-photo-main" src="" alt="" style="display:none;">
                <div class="swipe-photo-empty"></div>
                <div class="swipe-photo-dots"></div>
                <div class="swipe-photo-nav">
                    <button class="swipe-photo-arrow">&larr;</button>
                    <button class="swipe-photo-arrow">&rarr;</button>
                </div>
            </div>

            <!-- 3. Tags + Friends (Bottom Left) -->
            <div class="swipe-tags-card">
                <div class="swipe-tags-sidebar">
                    <h4 class="swipe-tags-title">About Me</h4>
                </div>
                <div class="swipe-friends-card">
                    <h3 class="swipe-friends-title">What My Friends Say About Me</h3>
                </div>
            </div>

            <!-- 4. Looking For (Bottom Right) -->
            <div class="swipe-looking-card">
                <h3 class="swipe-looking-title">Looking For</h3>
                <div class="swipe-relationship-type"></div>
                <div class="swipe-looking-pills"></div>
            </div>

            <!-- 5. Skip / Match Buttons -->
            <div class="swipe-actions">
                <button class="swipe-btn swipe-btn--skip">Skip</button>
                <button class="swipe-btn swipe-btn--match">Match</button>
            </div>

        </div>
        <div class="swipe-empty" style="text-align:center;padding:80px 20px;<?php echo !empty($candidatesList) ? 'display:none;' : ''; ?>">
            <h2>No more matches</h2>
            <p>Adjust the filters above to widen your search.</p>
        </div>
    <?php elseif ($prefsIncomplete && !$locationMissing && !$friendsMissing): ?>
        <div class="alert-wingmate">
            Fill out your match filters above to start swiping.
        </div>
    <?php endif; ?>
</div>

<!-- Tag Picker Modal for Looking For -->
<div class="modal fade" id="lookingForModal" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog modal-dialog-centered modal-dialog-scrollable">
        <div class="modal-content settings-modal-content">
            <div class="modal-header settings-modal-header">
                <h5 class="modal-title">Looking For</h5>
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

<script>
const allTags = <?php echo json_encode($allTags); ?>;
let selectedLookingFor = <?php echo json_encode(array_values($viewerLookingForTagIds)); ?>;

document.addEventListener('DOMContentLoaded', function() {
    // --- Custom dual-handle age slider ---
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

<?php if (!$locationMissing && !$friendsMissing && !$prefsIncomplete): ?>
<script>
    const swipeCandidates = <?php echo json_encode($candidatesList); ?>;
</script>
<script src="swipe.js"></script>
<?php endif; ?>

<?php include __DIR__ . '/../../includes/footer.php'; ?>

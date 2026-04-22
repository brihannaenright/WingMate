<?php
require_once __DIR__ . '/../../includes/session.php';
require_once __DIR__ . '/../../config/config.php';

wingmate_start_secure_session();

$current_user_id = $_SESSION['user_id'] ?? null;
if (!$current_user_id) {
    header('Location: /features/auth/login.php');
    exit;
}

// Handle swipe action POST
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'swipe') {
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

    echo json_encode(['success' => true]);
    exit;
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

$viewerLookingForTagIds = [];
$stmt = $conn->prepare("SELECT tag_id FROM User_Tags WHERE user_id = ? AND selection_type = 'looking_for'");
$stmt->bind_param('i', $current_user_id);
$stmt->execute();
$result = $stmt->get_result();
while ($row = $result->fetch_assoc()) {
    $viewerLookingForTagIds[] = (int)$row['tag_id'];
}
$stmt->close();

$missing = [];
if (!$viewer || $viewer['latitude'] === null || $viewer['longitude'] === null) {
    $missing[] = ['text' => 'Set your location', 'href' => '/features/profile/profile.php', 'label' => 'Go to Profile'];
}
if (!$prefs) {
    $missing[] = ['text' => 'Set your match preferences', 'href' => '/features/settings/settings.php', 'label' => 'Go to Settings'];
} else {
    if ($prefs['min_age'] === null || $prefs['max_age'] === null) {
        $missing[] = ['text' => 'Set your age preference', 'href' => '/features/settings/settings.php', 'label' => 'Go to Settings'];
    }
    if ($prefs['max_distance_km'] === null || (int)$prefs['max_distance_km'] <= 0) {
        $missing[] = ['text' => 'Set a distance greater than 0 km', 'href' => '/features/settings/settings.php', 'label' => 'Go to Settings'];
    }
    if ($prefs['relationship_type'] === null) {
        $missing[] = ['text' => 'Set your relationship type', 'href' => '/features/settings/settings.php', 'label' => 'Go to Settings'];
    }
}
if (empty($viewerLookingForTagIds)) {
    $missing[] = ['text' => 'Select at least one Looking For attribute', 'href' => '/features/settings/settings.php', 'label' => 'Go to Settings'];
}

$candidatesList = [];
if (empty($missing)) {

$viewerLat = (float)$viewer['latitude'];
$viewerLng = (float)$viewer['longitude'];
$prefGender = $prefs['preferred_gender']; // NULL means "All"
$minAge = (int)$prefs['min_age'];
$maxAge = (int)$prefs['max_age'];
$maxDistance = (int)$prefs['max_distance_km'];

// Candidates matching viewer prefs + haversine distance in km.
// Excludes self, already-swiped, and users blocked in either direction.
// Gender filter is skipped when viewer picked "All" (NULL).
// Looking-for tag filter is skipped when viewer has no looking_for tags set.
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

    // Batch photos for all candidates
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

    // Batch tags for all candidates
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

<link rel="stylesheet" href="swipe.css">

<div class="swipe-page">
    <?php if (!empty($missing)): ?>
    <div class="swipe-incomplete">
        <h2>Complete your profile to start swiping</h2>
        <ul>
            <?php foreach ($missing as $m): ?>
                <li>
                    <?php echo htmlspecialchars($m['text']); ?>
                    &mdash;
                    <a href="<?php echo htmlspecialchars($m['href']); ?>"><?php echo htmlspecialchars($m['label']); ?></a>
                </li>
            <?php endforeach; ?>
        </ul>
    </div>
    <?php else: ?>
    <div class="swipe-container">

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

    <div class="swipe-empty" style="display:none;text-align:center;padding:80px 20px;">
        <h2>No more matches</h2>
        <p>Check back later or widen your preferences in <a href="/features/settings/settings.php">Settings</a>.</p>
    </div>
    <?php endif; ?>
</div>

<?php if (empty($missing)): ?>
<script>
    const swipeCandidates = <?php echo json_encode($candidatesList); ?>;
</script>
<script src="swipe.js"></script>
<?php endif; ?>

<?php include __DIR__ . '/../../includes/footer.php'; ?>

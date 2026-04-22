<?php
require_once __DIR__ . '/../../includes/session.php';
require_once __DIR__ . '/../../config/config.php';

wingmate_start_secure_session();

// Redirect to login if not authenticated
$current_user_id = $_SESSION['user_id'] ?? null;
if (!$current_user_id) {
    header('Location: /features/auth/login.php');
    exit;
}

// Determine which profile to view
$profile_user_id = isset($_GET['user_id']) ? (int)$_GET['user_id'] : $current_user_id;
$isOwnProfile = ($profile_user_id === $current_user_id);
$isFriend = false;

// If viewing another user's profile, verify they exist and check friendship
if (!$isOwnProfile) {
    // Verify user exists and is not an administrator
    $stmt = $conn->prepare("SELECT user_id FROM Users WHERE user_id = ? AND account_status = 'active' AND user_type != 'administrator'");
    $stmt->bind_param('i', $profile_user_id);
    $stmt->execute();
    if ($stmt->get_result()->num_rows === 0) {
        $stmt->close();
        header('Location: /features/friends/friends.php?error=user_not_found');
        exit;
    }
    $stmt->close();

    // Check friendship status
    $stmt = $conn->prepare("
        SELECT 1 FROM Friendship 
        WHERE status = 'accepted'
        AND (
            (user_id = ? AND friend_id = ?) OR 
            (user_id = ? AND friend_id = ?)
        )
        LIMIT 1
    ");
    $stmt->bind_param('iiii', $current_user_id, $profile_user_id, $profile_user_id, $current_user_id);
    $stmt->execute();
    $isFriend = $stmt->get_result()->num_rows > 0;
    $stmt->close();
}

// Handle POST requests (only allow on own profile)
if ($_SERVER['REQUEST_METHOD'] === 'POST' && $isOwnProfile) {
    $action = $_POST['action'] ?? '';

    if ($action === 'update_tags') {
        header('Content-Type: application/json');
        $tagIds = json_decode($_POST['tag_ids'] ?? '[]', true);
        $selectionType = $_POST['selection_type'] ?? '';

        // Looking_for tags are managed from the settings page; profile only handles about_me
        if ($selectionType !== 'about_me') {
            echo json_encode(['success' => false, 'error' => 'Invalid selection type']);
            exit;
        }

        // Remove existing tags for this user and selection type
        $stmt = $conn->prepare("DELETE FROM User_Tags WHERE user_id = ? AND selection_type = ?");
        $stmt->bind_param('is', $current_user_id, $selectionType);
        $stmt->execute();
        $stmt->close();

        // Insert new selections
        if (!empty($tagIds)) {
            $stmt = $conn->prepare("INSERT INTO User_Tags (user_id, tag_id, selection_type) VALUES (?, ?, ?)");
            foreach ($tagIds as $tagId) {
                $tagId = intval($tagId);
                $stmt->bind_param('iis', $current_user_id, $tagId, $selectionType);
                $stmt->execute();
            }
            $stmt->close();
        }

        echo json_encode(['success' => true]);
        exit;
    }

    if ($action === 'upload_photo') {
        header('Content-Type: application/json');
        $isPrimary = intval($_POST['is_primary'] ?? 0);

        if (!isset($_FILES['photo']) || $_FILES['photo']['error'] !== UPLOAD_ERR_OK) {
            echo json_encode(['success' => false, 'error' => 'No file uploaded']);
            exit;
        }

        $file = $_FILES['photo'];
        if ($file['size'] > 2 * 1024 * 1024) {
            echo json_encode(['success' => false, 'error' => 'File must be under 2MB']);
            exit;
        }

        $allowed = ['image/jpeg', 'image/png', 'image/webp'];
        if (!in_array($file['type'], $allowed)) {
            echo json_encode(['success' => false, 'error' => 'Invalid file type']);
            exit;
        }

        $ext = pathinfo($file['name'], PATHINFO_EXTENSION);
        $filename = time() . '_' . $current_user_id . '.' . $ext;
        $uploadDir = __DIR__ . '/../../Uploads/';
        $destination = $uploadDir . $filename;

        if (!move_uploaded_file($file['tmp_name'], $destination)) {
            echo json_encode(['success' => false, 'error' => 'Failed to save file']);
            exit;
        }

        // If setting as primary, unset current primary
        if ($isPrimary) {
            $stmt = $conn->prepare("UPDATE User_Pictures SET is_primary = 0 WHERE user_id = ? AND is_primary = 1");
            $stmt->bind_param('i', $current_user_id);
            $stmt->execute();
            $stmt->close();
        }

        $stmt = $conn->prepare("INSERT INTO User_Pictures (user_id, photo_url, is_primary, is_removed) VALUES (?, ?, ?, 0)");
        $stmt->bind_param('isi', $current_user_id, $filename, $isPrimary);
        $stmt->execute();
        $photoId = $stmt->insert_id;
        $stmt->close();

        echo json_encode(['success' => true, 'photo_id' => $photoId, 'photo_url' => '/Uploads/' . $filename]);
        exit;
    }

    if ($action === 'delete_photo') {
        header('Content-Type: application/json');
        $photoId = intval($_POST['photo_id'] ?? 0);

        // Fetch filename before deleting
        $stmt = $conn->prepare("SELECT photo_url FROM User_Pictures WHERE photo_id = ? AND user_id = ?");
        $stmt->bind_param('ii', $photoId, $current_user_id);
        $stmt->execute();
        $result = $stmt->get_result();
        $photo = $result->fetch_assoc();
        $stmt->close();

        if ($photo) {
            // Delete file from disk
            $filePath = __DIR__ . '/../../Uploads/' . $photo['photo_url'];
            if (file_exists($filePath)) {
                unlink($filePath);
            }

            // Delete row from DB
            $stmt = $conn->prepare("DELETE FROM User_Pictures WHERE photo_id = ? AND user_id = ?");
            $stmt->bind_param('ii', $photoId, $current_user_id);
            $stmt->execute();
            $stmt->close();
        }

        echo json_encode(['success' => true]);
        exit;
    }

    if ($action === 'update_bio') {
        header('Content-Type: application/json');
        $bio = trim($_POST['bio'] ?? '');
        $gender = $_POST['gender'] ?? '';
        if (strlen($bio) > 500) {
            echo json_encode(['success' => false, 'error' => 'Bio too long']);
            exit;
        }
        $allowedGenders = ['male', 'female', 'non-binary', ''];
        if (!in_array($gender, $allowedGenders, true)) {
            echo json_encode(['success' => false, 'error' => 'Invalid gender']);
            exit;
        }
        $genderToSave = $gender === '' ? null : $gender;
        $stmt = $conn->prepare("UPDATE User_Profile SET user_bio = ?, gender = ? WHERE user_id = ?");
        $stmt->bind_param('ssi', $bio, $genderToSave, $current_user_id);
        $stmt->execute();
        $stmt->close();
        echo json_encode(['success' => true]);
        exit;
    }

    if ($action === 'update_location') {
        header('Content-Type: application/json');

        $lat = floatval($_POST['lat'] ?? 0);
        $lng = floatval($_POST['lng'] ?? 0);

        if ($lat === 0.0 && $lng === 0.0) {
            echo json_encode(['success' => false, 'error' => 'Invalid coordinates']);
            exit;
        }

        // Reverse geocode via Nominatim
        $url = "https://nominatim.openstreetmap.org/reverse?lat=$lat&lon=$lng&format=json&zoom=10";
        $opts = ['http' => ['header' => "User-Agent: WingMate/1.0\r\n"]];
        $context = stream_context_create($opts);
        $response = @file_get_contents($url, false, $context);

        $generalLocation = 'Unknown location';
        if ($response) {
            $data = json_decode($response, true);
            $address = $data['address'] ?? [];
            $city = $address['city'] ?? $address['town'] ?? $address['village'] ?? $address['county'] ?? '';
            $country = $address['country'] ?? '';
            // Strip native name (e.g. "Éire / Ireland" → "Ireland")
            if (strpos($country, ' / ') !== false) {
                $country = substr($country, strrpos($country, ' / ') + 3);
            }
            if ($city && $country) {
                $generalLocation = "$city, $country";
            } elseif ($country) {
                $generalLocation = $country;
            }
        }

        // Save all three to DB
        $stmt = $conn->prepare("UPDATE User_Profile SET latitude = ?, longitude = ?, general_location = ? WHERE user_id = ?");
        $stmt->bind_param('ddsi', $lat, $lng, $generalLocation, $current_user_id);
        $stmt->execute();
        $stmt->close();

        echo json_encode(['success' => true, 'general_location' => $generalLocation]);
        exit;
    }
}

// Fetch profile data from DB
$stmt = $conn->prepare("SELECT first_name, last_name, date_of_birth, gender, general_location, latitude, longitude, user_bio FROM User_Profile WHERE user_id = ?");
$stmt->bind_param('i', $profile_user_id);
$stmt->execute();
$profile = $stmt->get_result()->fetch_assoc();
$stmt->close();

// Calculate age from date of birth
$age = '';
if (!empty($profile['date_of_birth'])) {
    $dob = new DateTime($profile['date_of_birth']);
    $age = (string) $dob->diff(new DateTime('today'))->y;
}

$firstName = htmlspecialchars($profile['first_name'] ?? '');
$lastName = htmlspecialchars($profile['last_name'] ?? '');
$displayName = trim("$firstName $lastName") ?: 'New User';
$displayAge = $age ? ", $age" : '';
$displayLocation = htmlspecialchars($profile['general_location'] ?? '');
$userLat = $profile['latitude'] ?? '';
$userLng = $profile['longitude'] ?? '';
$userBio = htmlspecialchars($profile['user_bio'] ?? '');
$userGender = $profile['gender'] ?? '';

// Fetch all tags
$allTags = [];
$result = $conn->query("SELECT tag_id, tag_name, tag_type FROM Tags ORDER BY tag_name");
while ($row = $result->fetch_assoc()) {
    $allTags[] = $row;
}

// Fetch user's selected tags split by selection_type
$aboutMeTagIds = [];
$lookingForTagIds = [];
$stmt = $conn->prepare("SELECT tag_id, selection_type FROM User_Tags WHERE user_id = ?");
$stmt->bind_param('i', $profile_user_id);
$stmt->execute();
$result = $stmt->get_result();
while ($row = $result->fetch_assoc()) {
    if ($row['selection_type'] === 'about_me') {
        $aboutMeTagIds[] = (int)$row['tag_id'];
    } elseif ($row['selection_type'] === 'looking_for') {
        $lookingForTagIds[] = (int)$row['tag_id'];
    }
}
$stmt->close();

$userAboutMeTags = array_filter($allTags, fn($t) => in_array((int)$t['tag_id'], $aboutMeTagIds));
$userLookingForTags = array_filter($allTags, fn($t) => in_array((int)$t['tag_id'], $lookingForTagIds));

// Fetch user's photos
$userPhotos = [];
$primaryPhoto = null;
$stmt = $conn->prepare("SELECT photo_id, photo_url, is_primary FROM User_Pictures WHERE user_id = ? AND is_removed = 0 ORDER BY is_primary DESC, uploaded_at DESC");
$stmt->bind_param('i', $profile_user_id);
$stmt->execute();
$result = $stmt->get_result();
while ($row = $result->fetch_assoc()) {
    $row['photo_url'] = '/Uploads/' . $row['photo_url'];
    if ($row['is_primary']) {
        $primaryPhoto = $row;
    } else {
        $userPhotos[] = $row;
    }
}
$stmt->close();

// Fetch comments for profile owner (only if own profile or friend)
$comments = [];
if ($isOwnProfile || $isFriend) {
    $stmt = $conn->prepare("
        SELECT 
            fc.comment_id,
            fc.profile_owner_id,
            fc.commenter_id,
            fc.comment_text,
            fc.created_at,
            fc.updated_at,
            up.first_name,
            up.last_name,
            p.photo_url
        FROM Friend_Comments fc
        JOIN Users u ON fc.commenter_id = u.user_id
        JOIN User_Profile up ON u.user_id = up.user_id
        LEFT JOIN User_Pictures p ON u.user_id = p.user_id AND p.is_primary = 1 AND p.is_removed = 0
        WHERE fc.profile_owner_id = ?
        ORDER BY fc.created_at DESC
    ");
    $stmt->bind_param('i', $profile_user_id);
    $stmt->execute();
    $comments = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
    $stmt->close();
}
?>

<?php include __DIR__ . '/../../includes/nav-header.php'; ?>

<!-- Link the profile-specific CSS -->
<link rel="stylesheet" href="profile.css">
<!-- Leaflet map library -->
<link rel="stylesheet" href="https://unpkg.com/leaflet@1.9.4/dist/leaflet.css" />
<script src="https://unpkg.com/leaflet@1.9.4/dist/leaflet.js"></script>

<div class="profile-page">
    <div class="profile-container">
        <?php if (!$isOwnProfile): ?>
            <div class="profile-back-button" style="margin-bottom: 15px;">
                <a href="/features/friends/friends.php" class="btn btn-secondary">← Back to Friends</a>
            </div>
        <?php endif; ?>

        <!-- Top-Left Card: Pink Banner with Avatar + Profile Info -->
        <div class="profile-banner">
            <div class="profile-banner-bg"></div>
            
            <div class="profile-avatar-wrapper">
                <?php if ($primaryPhoto): ?>
                    <img class="profile-avatar" src="<?php echo $primaryPhoto['photo_url']; ?>" alt="Profile photo">
                <?php else: ?>
                    <div class="profile-avatar-empty">👤</div>
                <?php endif; ?>
            </div>

            <div class="profile-info-card">
                <h2 class="profile-name-age"><?php echo $displayName . $displayAge; ?></h2>
                <p class="profile-location">
                    <span class="profile-location-icon">📍</span>
                    <?php echo $displayLocation ?: 'Location not set'; ?>
                </p>
                <?php
                $genderLabels = ['male' => 'Male', 'female' => 'Female', 'non-binary' => 'Non-binary'];
                $genderLabel = $genderLabels[$userGender] ?? '';
                ?>
                <div class="profile-gender" id="profileGender" style="<?php echo $genderLabel === '' ? 'display:none;' : ''; ?>">
                    Gender: <?php echo htmlspecialchars($genderLabel); ?>
                </div>
                <p class="profile-bio" id="profileBio"><?php echo $userBio ?: 'No bio yet'; ?></p>
                <?php if ($isOwnProfile): ?>
                    <button class="profile-edit-icon profile-edit-icon--banner" onclick="openBioModal()" title="Edit">✏️</button>
                <?php endif; ?>
            </div>
        </div>

        <!-- Top-Right Card: Orange Photo Carousel -->
        <div class="profile-photos-card">
            <?php if ($primaryPhoto): ?>
                <img id="carouselImage" src="<?php echo $primaryPhoto['photo_url']; ?>" alt="Profile photo" class="profile-photo-main">
            <?php else: ?>
                <div class="profile-photo-empty">No Photos</div>
            <?php endif; ?>
            
            <?php if ($primaryPhoto || !empty($userPhotos)): ?>
                <div class="profile-photo-dots"></div>
            <?php endif; ?>
            
            <div class="profile-photo-nav">
                <button class="profile-photo-arrow" onclick="prevPhoto()">❮</button>
                <button class="profile-photo-arrow" onclick="nextPhoto()">❯</button>
            </div>

            <?php if ($isOwnProfile): ?>
                <button class="profile-edit-icon profile-edit-icon--photo" onclick="openPhotosModal()" title="Edit">✏️</button>
            <?php endif; ?>
        </div>

        <!-- Bottom-Left Card: Tags Sidebar + Friends Comments -->
        <div class="profile-tags-card">
            <!-- About Me Tags Sidebar -->
            <div class="profile-tags-sidebar" id="aboutMePills">
                <h3 class="profile-tags-title">About Me</h3>
                <?php foreach ($userAboutMeTags as $index => $tag): ?>
                    <span class="profile-pill profile-pill--<?php echo $index % 2 === 0 ? 'pink' : 'orange'; ?>">
                        <?php echo htmlspecialchars($tag['tag_name']); ?>
                    </span>
                <?php endforeach; ?>
                <?php if ($isOwnProfile): ?>
                    <button class="profile-add-tag" onclick="openTagPickerModal('about_me')" title="Add tags">+</button>
                <?php endif; ?>
            </div>

            <!-- What My Friends Say Comments Card -->
            <div class="profile-friends-card">
                <h3 class="profile-friends-title">What My Friends Say</h3>
                
                <?php if (!$isOwnProfile && $isFriend): ?>
                    <!-- Comment Input Form for Friends -->
                    <div class="friends-comments-section">
                        <div id="commentError" class="alert alert-danger d-none"></div>
                        <textarea id="commentText" class="comment-textarea" placeholder="Leave a comment for this person..." maxlength="500"></textarea>
                        <div class="comment-form-footer">
                            <span class="char-count"><span id="charCount">0</span>/500</span>
                            <button id="submitCommentBtn" class="profile-btn-save" onclick="saveComment()">Post Comment</button>
                        </div>
                    </div>
                    <hr style="margin: 20px 0; opacity: 0.2;">
                <?php endif; ?>
                
                <div id="commentsListContainer" class="comments-list">
                    <!-- Populated by JS -->
                </div>
            </div>
        </div>

        <!-- Bottom-Right Card: Looking For - read-only, edit in Settings -->
        <div class="profile-looking-card" id="profileLookingForPills">
            <h3 class="profile-looking-title">Looking For</h3>
            <div class="profile-looking-pills" id="lookingForPills">
                <?php if (empty($userLookingForTags)): ?>
                    <span class="profile-looking-empty">Edit in <a href="/features/settings/settings.php">Settings</a></span>
                <?php else: ?>
                    <?php foreach ($userLookingForTags as $tag): ?>
                        <span class="profile-looking-pill"><?php echo htmlspecialchars($tag['tag_name']); ?></span>
                    <?php endforeach; ?>
                <?php endif; ?>
            </div>
        </div>
    </div>

    <!-- Bio/Location Edit Modal -->
    <div class="modal fade" id="bioModal" tabindex="-1" aria-labelledby="bioModalLabel" aria-hidden="true">
        <div class="modal-dialog modal-dialog-centered">
            <div class="modal-content profile-modal-content">
                <div class="modal-header profile-modal-header">
                    <h5 class="modal-title" id="bioModalLabel">Edit Profile Info</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
                </div>
                <div class="modal-body">
                    <div class="mb-3">
                        <label class="form-label profile-modal-label">Location</label>
                        <div class="d-flex gap-2 align-items-center">
                            <input type="text" class="form-control profile-modal-input" id="editLocation" readonly placeholder="Pick on map..." value="<?php echo $displayLocation; ?>">
                            <button type="button" class="btn profile-btn-upload" onclick="openMapModal()" style="white-space:nowrap;">Pick on Map</button>
                        </div>
                        <input type="hidden" id="editLat" value="<?php echo $userLat; ?>">
                        <input type="hidden" id="editLng" value="<?php echo $userLng; ?>">
                    </div>
                    <div class="mb-3">
                        <label for="editGender" class="form-label profile-modal-label">Gender</label>
                        <select class="form-control profile-modal-input" id="editGender">
                            <option value="">Prefer not to say</option>
                            <option value="male" <?php echo $userGender === 'male' ? 'selected' : ''; ?>>Male</option>
                            <option value="female" <?php echo $userGender === 'female' ? 'selected' : ''; ?>>Female</option>
                            <option value="non-binary" <?php echo $userGender === 'non-binary' ? 'selected' : ''; ?>>Non-binary</option>
                        </select>
                    </div>
                    <div class="mb-3">
                        <label for="editBio" class="form-label profile-modal-label">Bio</label>
                        <textarea class="form-control profile-modal-input" id="editBio" rows="4" maxlength="500" placeholder="Tell people about yourself..."></textarea>
                        <div class="form-text text-end"><span id="bioCharCount">0</span>/500</div>
                    </div>
                </div>
                <div class="modal-footer profile-modal-footer">
                    <button type="button" class="btn profile-btn-cancel" data-bs-dismiss="modal">Cancel</button>
                    <button type="button" class="btn profile-btn-save" onclick="saveBio()">Save</button>
                </div>
            </div>
        </div>
    </div>

    <!-- Profile Picture Edit Modal -->
    <div class="modal fade" id="picModal" tabindex="-1" aria-labelledby="picModalLabel" aria-hidden="true">
        <div class="modal-dialog modal-dialog-centered">
            <div class="modal-content profile-modal-content">
                <div class="modal-header profile-modal-header">
                    <h5 class="modal-title" id="picModalLabel">Change Profile Picture</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
                </div>
                <div class="modal-body text-center">
                    <div class="profile-pic-preview-wrapper">
                        <img id="picPreview" src="" alt="Preview" class="profile-pic-preview" style="display:none;">
                        <div class="profile-pic-preview-empty" id="picPreviewEmpty">No photo</div>
                    </div>
                    <label class="btn profile-btn-upload mt-3">
                        Choose Photo
                        <input type="file" id="picFileInput" accept="image/jpeg,image/png,image/webp" hidden>
                    </label>
                </div>
                <div class="modal-footer profile-modal-footer">
                    <button type="button" class="btn profile-btn-remove" id="removePicBtn" onclick="removeProfilePic()">Remove</button>
                    <div class="ms-auto d-flex gap-2">
                        <button type="button" class="btn profile-btn-cancel" data-bs-dismiss="modal">Cancel</button>
                        <button type="button" class="btn profile-btn-save" onclick="saveProfilePic()">Save</button>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <!-- Carousel Photos Edit Modal -->
    <div class="modal fade" id="photosModal" tabindex="-1" aria-labelledby="photosModalLabel" aria-hidden="true">
        <div class="modal-dialog modal-dialog-centered modal-lg">
            <div class="modal-content profile-modal-content">
                <div class="modal-header profile-modal-header">
                    <h5 class="modal-title" id="photosModalLabel">Manage Photos</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
                </div>
                <div class="modal-body">
                    <div class="profile-photos-grid" id="photosGrid">
                        <!-- Populated by JS -->
                    </div>
                    <label class="btn profile-btn-upload mt-3" id="addPhotoBtn">
                        + Add Photo
                        <input type="file" id="photoFileInput" accept="image/jpeg,image/png,image/webp" hidden>
                    </label>
                    <div class="form-text">Max 6 photos</div>
                </div>
                <div class="modal-footer profile-modal-footer">
                    <button type="button" class="btn profile-btn-save" data-bs-dismiss="modal">Done</button>
                </div>
            </div>
        </div>
    </div>

    <!-- Map Location Picker Modal -->
    <div class="modal fade" id="mapModal" tabindex="-1" aria-labelledby="mapModalLabel" aria-hidden="true">
        <div class="modal-dialog modal-dialog-centered modal-lg">
            <div class="modal-content profile-modal-content">
                <div class="modal-header profile-modal-header">
                    <h5 class="modal-title" id="mapModalLabel">Pick Your Location</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
                </div>
                <div class="modal-body">
                    <p class="form-text mb-2">Click on the map to select your location</p>
                    <div id="map"></div>
                </div>
                <div class="modal-footer profile-modal-footer">
                    <button type="button" class="btn profile-btn-cancel" data-bs-dismiss="modal">Cancel</button>
                    <button type="button" class="btn profile-btn-save" id="confirmLocationBtn" onclick="confirmLocation()" disabled>Confirm Location</button>
                </div>
            </div>
        </div>
    </div>

    <!-- Tag Picker Modal -->
    <div class="modal fade" id="tagPickerModal" tabindex="-1" aria-labelledby="tagPickerModalLabel" aria-hidden="true">
        <div class="modal-dialog modal-dialog-centered">
            <div class="modal-content profile-modal-content">
                <div class="modal-header profile-modal-header">
                    <h5 class="modal-title" id="tagPickerModalLabel">Select Tags</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
                </div>
                <div class="modal-body">
                    <div class="profile-tag-picker" id="tagPickerGrid"></div>
                </div>
                <div class="modal-footer profile-modal-footer">
                    <button type="button" class="btn profile-btn-cancel" data-bs-dismiss="modal">Cancel</button>
                    <button type="button" class="btn profile-btn-save" onclick="saveTags()">Save</button>
                </div>
            </div>
        </div>
    </div>

</div>

<script>
    const allTags = <?php echo json_encode($allTags); ?>;
    const aboutMeTagIds = <?php echo json_encode(array_values($aboutMeTagIds)); ?>;
    const lookingForTagIds = <?php echo json_encode(array_values($lookingForTagIds)); ?>;
    
    // Combine primary and carousel photos into userPhotos array for profile.js
    const primaryPhotoData = <?php echo json_encode($primaryPhoto); ?>;
    const carouselPhotosData = <?php echo json_encode(array_values($userPhotos)); ?>;
    let userPhotos = [];
    if (primaryPhotoData) {
        userPhotos.push(primaryPhotoData);
    }
    userPhotos = userPhotos.concat(carouselPhotosData);
    
    const primaryPhotoId = <?php echo json_encode($primaryPhoto ? (int)$primaryPhoto['photo_id'] : null); ?>;
    const currentUserId = <?php echo json_encode($current_user_id); ?>;
    const profileUserId = <?php echo json_encode($profile_user_id); ?>;
    const isOwnProfile = <?php echo json_encode($isOwnProfile); ?>;
    const initialComments = <?php echo json_encode($comments); ?>;

    // --- Comment Management ---
    const commentsListContainer = document.getElementById('commentsListContainer');
    
    function renderComments(comments, userId) {
        if (comments.length === 0) {
            commentsListContainer.innerHTML = '<p class="text-muted text-center py-3">No comments yet</p>';
            return;
        }

        commentsListContainer.innerHTML = comments.map(comment => {
            const isOwner = userId === comment.commenter_id;
            const photoUrl = comment.photo_url ? `/Uploads/${comment.photo_url}` : null;
            const createdDate = new Date(comment.created_at).toLocaleDateString('en-US', { year: 'numeric', month: 'short', day: 'numeric' });
            const isEdited = comment.updated_at !== comment.created_at;

            return `
                <div class="comment-item" data-comment-id="${comment.comment_id}">
                    <div class="comment-header">
                        <div class="comment-author">
                            ${photoUrl ? 
                                `<img src="${photoUrl}" alt="${comment.first_name}" class="comment-avatar">` :
                                '<div class="comment-avatar-placeholder">👤</div>'
                            }
                            <div class="comment-author-info">
                                <strong>${comment.first_name} ${comment.last_name}</strong>
                                <small class="text-muted">${createdDate}${isEdited ? ' (edited)' : ''}</small>
                            </div>
                        </div>
                        ${isOwner ? `
                            <div class="comment-actions">
                                <button class="btn-action edit-comment-btn" title="Edit">✏️</button>
                                <button class="btn-action delete-comment-btn" title="Delete">🗑️</button>
                            </div>
                        ` : ''}
                    </div>
                    <p class="comment-text" id="commentText${comment.comment_id}">${comment.comment_text}</p>
                    ${isOwner ? `
                        <div class="comment-edit-form d-none" id="editForm${comment.comment_id}">
                            <textarea class="comment-textarea" id="editTextarea${comment.comment_id}" maxlength="500">${comment.comment_text}</textarea>
                            <div class="comment-form-footer mt-2">
                                <span class="char-count"><span class="edit-char-count">${comment.comment_text.length}</span>/500</span>
                                <button class="btn btn-primary btn-sm save-comment-btn" data-comment-id="${comment.comment_id}">Save</button>
                                <button class="btn btn-secondary btn-sm cancel-edit-btn">Cancel</button>
                            </div>
                        </div>
                    ` : ''}
                </div>
            `;
        }).join('');

        attachCommentEventListeners();
    }

    function attachCommentEventListeners() {
        // Edit button
        document.querySelectorAll('.edit-comment-btn').forEach(btn => {
            btn.addEventListener('click', function() {
                const commentItem = this.closest('.comment-item');
                const commentId = commentItem.dataset.commentId;
                const editForm = document.getElementById(`editForm${commentId}`);
                const commentText = document.getElementById(`commentText${commentId}`);
                
                editForm.classList.remove('d-none');
                commentText.style.display = 'none';
                this.closest('.comment-actions').style.display = 'none';
                
                const textarea = document.getElementById(`editTextarea${commentId}`);
                textarea.focus();
                textarea.addEventListener('input', function() {
                    this.parentElement.querySelector('.edit-char-count').textContent = this.value.length;
                });
            });
        });

        // Cancel edit
        document.querySelectorAll('.cancel-edit-btn').forEach(btn => {
            btn.addEventListener('click', function() {
                const editForm = this.closest('.comment-edit-form');
                const commentItem = editForm.closest('.comment-item');
                const commentId = commentItem.dataset.commentId;
                const commentText = document.getElementById(`commentText${commentId}`);
                
                editForm.classList.add('d-none');
                commentText.style.display = '';
                commentItem.querySelector('.comment-actions').style.display = '';
            });
        });

        // Save edit
        document.querySelectorAll('.save-comment-btn').forEach(btn => {
            btn.addEventListener('click', async function() {
                const commentId = this.dataset.commentId;
                const textarea = document.getElementById(`editTextarea${commentId}`);
                const newText = textarea.value.trim();

                if (!newText) {
                    alert('Comment cannot be empty');
                    return;
                }

                try {
                    this.disabled = true;
                    this.textContent = 'Saving...';

                    const response = await fetch('/features/profile/comments-api.php?action=edit_comment', {
                        method: 'POST',
                        headers: {
                            'Content-Type': 'application/x-www-form-urlencoded',
                        },
                        body: `comment_id=${commentId}&comment_text=${encodeURIComponent(newText)}`
                    });

                    const data = await response.json();

                    if (data.success) {
                        // Reload comments from API
                        const response2 = await fetch(`/features/profile/comments-api.php?action=get_comments&profile_owner_id=${profileUserId}`);
                        const data2 = await response2.json();
                        if (data2.success) {
                            renderComments(data2.comments, currentUserId);
                        }
                    } else {
                        alert(data.error || 'Failed to update comment');
                    }
                } catch (error) {
                    alert('An error occurred. Please try again.');
                    console.error('Error:', error);
                } finally {
                    this.disabled = false;
                    this.textContent = 'Save';
                }
            });
        });

        // Delete button
        document.querySelectorAll('.delete-comment-btn').forEach(btn => {
            btn.addEventListener('click', async function() {
                if (!confirm('Are you sure you want to delete this comment?')) {
                    return;
                }

                const commentItem = this.closest('.comment-item');
                const commentId = commentItem.dataset.commentId;

                try {
                    const response = await fetch('/features/profile/comments-api.php?action=delete_comment', {
                        method: 'POST',
                        headers: {
                            'Content-Type': 'application/x-www-form-urlencoded',
                        },
                        body: `comment_id=${commentId}`
                    });

                    const data = await response.json();

                    if (data.success) {
                        // Reload comments from API
                        const response2 = await fetch(`/features/profile/comments-api.php?action=get_comments&profile_owner_id=${profileUserId}`);
                        const data2 = await response2.json();
                        if (data2.success) {
                            renderComments(data2.comments, currentUserId);
                        }
                    } else {
                        alert(data.error || 'Failed to delete comment');
                    }
                } catch (error) {
                    alert('An error occurred. Please try again.');
                    console.error('Error:', error);
                }
            });
        });
    }

    // Initialize comments on page load
    document.addEventListener('DOMContentLoaded', function() {
        renderComments(initialComments, currentUserId);
    });
</script>
<script src="profile.js"></script>

<?php include __DIR__ . '/../../includes/footer.php'; ?>
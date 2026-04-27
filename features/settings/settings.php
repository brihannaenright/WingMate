<?php
require_once __DIR__ . '/../../includes/session.php';
require_once __DIR__ . '/../../config/config.php';

wingmate_start_secure_session();

$current_user_id = $_SESSION['user_id'] ?? null;
if (!$current_user_id) {
    header('Location: /features/auth/login.php');
    exit;
}

// Handle POST actions (moved from profile.php — settings is now the profile editor)
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';

    if ($action === 'update_tags') {
        header('Content-Type: application/json');
        $tagIds = json_decode($_POST['tag_ids'] ?? '[]', true);
        $selectionType = $_POST['selection_type'] ?? '';

        // Only about_me tags are edited on this page; looking_for lives on swipe.
        if ($selectionType !== 'about_me') {
            echo json_encode(['success' => false, 'error' => 'Invalid selection type']);
            exit;
        }

        $stmt = $conn->prepare("DELETE FROM User_Tags WHERE user_id = ? AND selection_type = ?");
        $stmt->bind_param('is', $current_user_id, $selectionType);
        $stmt->execute();
        $stmt->close();

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

        $stmt = $conn->prepare("SELECT photo_url FROM User_Pictures WHERE photo_id = ? AND user_id = ?");
        $stmt->bind_param('ii', $photoId, $current_user_id);
        $stmt->execute();
        $photo = $stmt->get_result()->fetch_assoc();
        $stmt->close();

        if ($photo) {
            $filePath = __DIR__ . '/../../Uploads/' . $photo['photo_url'];
            if (file_exists($filePath)) {
                unlink($filePath);
            }
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
        $relationship = $_POST['relationship_type'] ?? '';
        if (strlen($bio) > 500) {
            echo json_encode(['success' => false, 'error' => 'Bio too long']);
            exit;
        }
        $allowedGenders = ['male', 'female', 'non-binary', ''];
        if (!in_array($gender, $allowedGenders, true)) {
            echo json_encode(['success' => false, 'error' => 'Invalid gender']);
            exit;
        }
        $allowedRelationships = ['short_term', 'long_term', 'fun', ''];
        if (!in_array($relationship, $allowedRelationships, true)) {
            echo json_encode(['success' => false, 'error' => 'Invalid relationship type']);
            exit;
        }
        $genderToSave = $gender === '' ? null : $gender;
        $relationshipToSave = $relationship === '' ? null : $relationship;

        $stmt = $conn->prepare("UPDATE User_Profile SET user_bio = ?, gender = ? WHERE user_id = ?");
        $stmt->bind_param('ssi', $bio, $genderToSave, $current_user_id);
        $stmt->execute();
        $stmt->close();

        // Relationship type lives in User_Preferences; UPSERT so first-time setters get a row
        $stmt = $conn->prepare("SELECT preference_id FROM User_Preferences WHERE user_id = ?");
        $stmt->bind_param('i', $current_user_id);
        $stmt->execute();
        $hasPrefs = $stmt->get_result()->num_rows > 0;
        $stmt->close();
        if ($hasPrefs) {
            $stmt = $conn->prepare("UPDATE User_Preferences SET relationship_type = ? WHERE user_id = ?");
            $stmt->bind_param('si', $relationshipToSave, $current_user_id);
        } else {
            $stmt = $conn->prepare("INSERT INTO User_Preferences (user_id, relationship_type) VALUES (?, ?)");
            $stmt->bind_param('is', $current_user_id, $relationshipToSave);
        }
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

        // Release session lock so other tabs don't block on this request
        session_write_close();

        // 3s timeout so a slow Nominatim response doesn't hold the PHP slot
        $url = "https://nominatim.openstreetmap.org/reverse?lat=$lat&lon=$lng&format=json&zoom=10";
        $context = stream_context_create(['http' => [
            'header'  => "User-Agent: WingMate/1.0\r\n",
            'timeout' => 3,
        ]]);
        $response = @file_get_contents($url, false, $context);

        $generalLocation = 'Unknown location';
        if ($response) {
            $data = json_decode($response, true);
            $address = $data['address'] ?? [];
            $city = $address['city'] ?? $address['town'] ?? $address['village'] ?? $address['county'] ?? '';
            $country = $address['country'] ?? '';
            if (strpos($country, ' / ') !== false) {
                $country = substr($country, strrpos($country, ' / ') + 3);
            }
            if ($city && $country) {
                $generalLocation = "$city, $country";
            } elseif ($country) {
                $generalLocation = $country;
            }
        }

        $stmt = $conn->prepare("UPDATE User_Profile SET latitude = ?, longitude = ?, general_location = ? WHERE user_id = ?");
        $stmt->bind_param('ddsi', $lat, $lng, $generalLocation, $current_user_id);
        $stmt->execute();
        $stmt->close();

        echo json_encode(['success' => true, 'general_location' => $generalLocation]);
        exit;
    }

    if ($action === 'delete_account') {
        // Hard delete: remove all rows referencing this user across every table, in
        // child-first order so FK constraints don't fire. Wrapped in a transaction so a
        // mid-flight failure doesn't leave a half-deleted account.
        header('Content-Type: application/json');

        // Require password re-entry: a logged-in session alone shouldn't be enough to wipe an account
        $submittedPassword = (string) ($_POST['password'] ?? '');
        $stmt = $conn->prepare("SELECT password_hash FROM Users WHERE user_id = ?");
        $stmt->bind_param('i', $current_user_id);
        $stmt->execute();
        $row = $stmt->get_result()->fetch_assoc();
        $stmt->close();
        if (!$row || !password_verify($submittedPassword, $row['password_hash'])) {
            echo json_encode(['success' => false, 'error' => 'Incorrect password']);
            exit;
        }

        // 1. Unlink photo files from disk before deleting DB rows
        $stmt = $conn->prepare("SELECT photo_url FROM User_Pictures WHERE user_id = ?");
        $stmt->bind_param('i', $current_user_id);
        $stmt->execute();
        $photos = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
        $stmt->close();
        foreach ($photos as $photo) {
            $filePath = __DIR__ . '/../../Uploads/' . $photo['photo_url'];
            if (file_exists($filePath)) {
                @unlink($filePath);
            }
        }

        // 2. Wipe all DB rows referencing this user, children-first
        $conn->begin_transaction();
        try {
            // Friend_Votes: votes this user cast, plus votes on requests they were part of
            $stmt = $conn->prepare("DELETE FROM Friend_Votes WHERE friend_voter_id = ?");
            $stmt->bind_param('i', $current_user_id);
            $stmt->execute(); $stmt->close();

            $stmt = $conn->prepare("DELETE FROM Friend_Votes WHERE request_id IN (SELECT request_id FROM Match_Requests WHERE match_owner_id = ? OR matched_user_id = ?)");
            $stmt->bind_param('ii', $current_user_id, $current_user_id);
            $stmt->execute(); $stmt->close();

            // Decision rows tied to this user's match requests
            $stmt = $conn->prepare("DELETE FROM Decision WHERE match_request_id IN (SELECT request_id FROM Match_Requests WHERE match_owner_id = ? OR matched_user_id = ?)");
            $stmt->bind_param('ii', $current_user_id, $current_user_id);
            $stmt->execute(); $stmt->close();

            $stmt = $conn->prepare("DELETE FROM Match_Requests WHERE match_owner_id = ? OR matched_user_id = ?");
            $stmt->bind_param('ii', $current_user_id, $current_user_id);
            $stmt->execute(); $stmt->close();

            $stmt = $conn->prepare("DELETE FROM Matches WHERE user1_id = ? OR user2_id = ?");
            $stmt->bind_param('ii', $current_user_id, $current_user_id);
            $stmt->execute(); $stmt->close();

            $stmt = $conn->prepare("DELETE FROM User_Swipe WHERE liker_id = ? OR liked_id = ?");
            $stmt->bind_param('ii', $current_user_id, $current_user_id);
            $stmt->execute(); $stmt->close();

            $stmt = $conn->prepare("DELETE FROM Friendship WHERE user_id = ? OR friend_id = ?");
            $stmt->bind_param('ii', $current_user_id, $current_user_id);
            $stmt->execute(); $stmt->close();

            $stmt = $conn->prepare("DELETE FROM Notifications WHERE recipient_id = ?");
            $stmt->bind_param('i', $current_user_id);
            $stmt->execute(); $stmt->close();

            $stmt = $conn->prepare("DELETE FROM Friend_Comments WHERE commenter_id = ? OR profile_owner_id = ?");
            $stmt->bind_param('ii', $current_user_id, $current_user_id);
            $stmt->execute(); $stmt->close();

            // Message_Receipts: receipts the user owns, plus receipts on messages they sent
            $stmt = $conn->prepare("DELETE FROM Message_Receipts WHERE receiver_id = ?");
            $stmt->bind_param('i', $current_user_id);
            $stmt->execute(); $stmt->close();

            $stmt = $conn->prepare("DELETE FROM Message_Receipts WHERE message_id IN (SELECT message_id FROM Messages WHERE sender_id = ?)");
            $stmt->bind_param('i', $current_user_id);
            $stmt->execute(); $stmt->close();

            $stmt = $conn->prepare("DELETE FROM Messages WHERE sender_id = ?");
            $stmt->bind_param('i', $current_user_id);
            $stmt->execute(); $stmt->close();

            // User leaves all chats (orphaned chats stay; cleanup is admin's call)
            $stmt = $conn->prepare("DELETE FROM Chat_Members WHERE user_id = ?");
            $stmt->bind_param('i', $current_user_id);
            $stmt->execute(); $stmt->close();

            $stmt = $conn->prepare("DELETE FROM User_Reports WHERE reporter_id = ? OR reported_id = ?");
            $stmt->bind_param('ii', $current_user_id, $current_user_id);
            $stmt->execute(); $stmt->close();

            $stmt = $conn->prepare("DELETE FROM User_Blocks WHERE blocker_id = ? OR blocked_id = ?");
            $stmt->bind_param('ii', $current_user_id, $current_user_id);
            $stmt->execute(); $stmt->close();

            $stmt = $conn->prepare("DELETE FROM Password_Resets WHERE user_id = ?");
            $stmt->bind_param('i', $current_user_id);
            $stmt->execute(); $stmt->close();

            $stmt = $conn->prepare("DELETE FROM User_Tags WHERE user_id = ?");
            $stmt->bind_param('i', $current_user_id);
            $stmt->execute(); $stmt->close();

            $stmt = $conn->prepare("DELETE FROM User_Preferences WHERE user_id = ?");
            $stmt->bind_param('i', $current_user_id);
            $stmt->execute(); $stmt->close();

            $stmt = $conn->prepare("DELETE FROM User_Pictures WHERE user_id = ?");
            $stmt->bind_param('i', $current_user_id);
            $stmt->execute(); $stmt->close();

            $stmt = $conn->prepare("DELETE FROM User_Profile WHERE user_id = ?");
            $stmt->bind_param('i', $current_user_id);
            $stmt->execute(); $stmt->close();

            $stmt = $conn->prepare("DELETE FROM Users WHERE user_id = ?");
            $stmt->bind_param('i', $current_user_id);
            $stmt->execute(); $stmt->close();

            $conn->commit();
            echo json_encode(['success' => true]);
        } catch (Exception $e) {
            $conn->rollback();
            echo json_encode(['success' => false, 'error' => 'Deletion failed']);
        }
        exit;
    }
}

// Fetch profile data
$stmt = $conn->prepare("SELECT gender, general_location, latitude, longitude, user_bio FROM User_Profile WHERE user_id = ?");
$stmt->bind_param('i', $current_user_id);
$stmt->execute();
$profile = $stmt->get_result()->fetch_assoc();
$stmt->close();

$displayLocation = $profile['general_location'] ?? '';
$userLat = $profile['latitude'] ?? '';
$userLng = $profile['longitude'] ?? '';
$userBio = $profile['user_bio'] ?? '';
$userGender = $profile['gender'] ?? '';

// Fetch relationship_type from User_Preferences (moved from swipe filters)
$stmt = $conn->prepare("SELECT relationship_type FROM User_Preferences WHERE user_id = ?");
$stmt->bind_param('i', $current_user_id);
$stmt->execute();
$prefsRow = $stmt->get_result()->fetch_assoc();
$stmt->close();
$userRelationship = $prefsRow['relationship_type'] ?? '';

$RELATIONSHIP_LABELS = ['short_term' => 'Short term', 'long_term' => 'Long term', 'fun' => 'Looking for fun'];

// Fetch all tags + user's about_me selections
$allTags = [];
$result = $conn->query("SELECT tag_id, tag_name, tag_type FROM Tags ORDER BY tag_name");
while ($row = $result->fetch_assoc()) {
    $allTags[] = $row;
}

$aboutMeTagIds = [];
$stmt = $conn->prepare("SELECT tag_id FROM User_Tags WHERE user_id = ? AND selection_type = 'about_me'");
$stmt->bind_param('i', $current_user_id);
$stmt->execute();
$result = $stmt->get_result();
while ($row = $result->fetch_assoc()) {
    $aboutMeTagIds[] = (int)$row['tag_id'];
}
$stmt->close();

$userAboutMeTags = array_filter($allTags, fn($t) => in_array((int)$t['tag_id'], $aboutMeTagIds));

// Fetch user's photos
$carouselPhotos = [];
$primaryPhoto = null;
$stmt = $conn->prepare("SELECT photo_id, photo_url, is_primary FROM User_Pictures WHERE user_id = ? AND is_removed = 0 ORDER BY is_primary DESC, uploaded_at DESC");
$stmt->bind_param('i', $current_user_id);
$stmt->execute();
$result = $stmt->get_result();
while ($row = $result->fetch_assoc()) {
    $row['photo_url'] = '/Uploads/' . $row['photo_url'];
    if ($row['is_primary']) {
        $primaryPhoto = $row;
    } else {
        $carouselPhotos[] = $row;
    }
}
$stmt->close();

include __DIR__ . '/../../includes/nav-header.php';
?>
<link rel="stylesheet" href="/features/profile/profile.css">
<link rel="stylesheet" href="/features/settings/settings.css">
<link rel="stylesheet" href="https://unpkg.com/leaflet@1.9.4/dist/leaflet.css" />
<script src="https://unpkg.com/leaflet@1.9.4/dist/leaflet.js"></script>

<div class="settings-page">
    <div class="settings-content">
        <p class="settings-section-title">Edit Profile</p>
        <p class="settings-section-subtitle">These details appear on your profile.</p>

        <!-- Profile Picture (primary) -->
        <div class="settings-section">
            <div class="settings-section-body">
                <div class="settings-field-label">Profile Picture</div>
                <div class="settings-primary-photo" id="primaryPhotoDisplay">
                    <?php if ($primaryPhoto): ?>
                        <img src="<?php echo htmlspecialchars($primaryPhoto['photo_url']); ?>" class="settings-primary-photo-img" alt="Profile picture">
                    <?php else: ?>
                        <div class="settings-primary-photo-empty">👤</div>
                    <?php endif; ?>
                </div>
            </div>
            <button type="button" class="btn profile-btn-upload" onclick="openPicModal()">Edit</button>
        </div>

        <!-- Photos (swipable carousel) -->
        <div class="settings-section">
            <div class="settings-section-body">
                <div class="settings-field-label">Photos</div>
                <div class="settings-photos-row" id="photoThumbnails">
                    <?php foreach ($carouselPhotos as $photo): ?>
                        <img src="<?php echo htmlspecialchars($photo['photo_url']); ?>" class="settings-photo-thumb" alt="Photo">
                    <?php endforeach; ?>
                    <?php if (empty($carouselPhotos)): ?>
                        <span class="settings-empty">No photos yet.</span>
                    <?php endif; ?>
                </div>
            </div>
            <button type="button" class="btn profile-btn-upload" onclick="openPhotosModal()">Edit</button>
        </div>

        <!-- Bio / Location / Gender -->
        <div class="settings-section">
            <div class="settings-section-body">
                <div class="settings-field-label">About You</div>
                <p class="settings-field-row"><strong>Location:</strong> <span id="locationDisplay"><?php echo htmlspecialchars($displayLocation ?: 'Not set'); ?></span></p>
                <p class="settings-field-row"><strong>Gender:</strong> <span id="genderDisplay"><?php echo htmlspecialchars($userGender ? ucfirst($userGender) : 'Not set'); ?></span></p>
                <p class="settings-field-row"><strong>Relationship Type:</strong> <span id="relationshipDisplay"><?php echo htmlspecialchars($RELATIONSHIP_LABELS[$userRelationship] ?? 'Not set'); ?></span></p>
                <p class="settings-field-row"><strong>Bio:</strong> <span id="bioDisplay"><?php echo htmlspecialchars($userBio ?: 'Not set'); ?></span></p>
            </div>
            <button type="button" class="btn profile-btn-upload" onclick="openBioModal()">Edit</button>
        </div>

        <!-- About Me Tags -->
        <div class="settings-section">
            <div class="settings-section-body">
                <div class="settings-field-label">About Me</div>
                <div id="aboutMePills" class="settings-pills-row">
                    <?php if (empty($userAboutMeTags)): ?>
                        <span class="settings-empty">No tags selected yet.</span>
                    <?php endif; ?>
                    <?php foreach ($userAboutMeTags as $index => $tag): ?>
                        <span class="profile-pill profile-pill--<?php echo $index % 2 === 0 ? 'pink' : 'orange'; ?>">
                            <?php echo htmlspecialchars($tag['tag_name']); ?>
                        </span>
                    <?php endforeach; ?>
                </div>
            </div>
            <button type="button" class="btn profile-btn-upload" onclick="openTagPickerModal()">Edit</button>
        </div>

        <!-- Delete Account: irreversible self-serve action -->
        <div class="settings-section">
            <div class="settings-section-body">
                <div class="settings-field-label">Delete Account</div>
                <p class="settings-field-row">Permanently disable your account. You won't be able to log back in.</p>
            </div>
            <button type="button" class="btn profile-btn-remove" onclick="openDeleteAccountModal()">Delete</button>
        </div>
    </div>
</div>

<!-- Delete Account Confirmation Modal -->
<div class="modal fade" id="deleteAccountModal" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog modal-dialog-centered">
        <div class="modal-content profile-modal-content">
            <div class="modal-header profile-modal-header">
                <h5 class="modal-title">Delete Account</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
            </div>
            <div class="modal-body">
                <p>Are you sure you want to delete your account? This action cannot be undone and you will not be able to log back in.</p>
                <div class="mb-2">
                    <label for="deleteAccountPassword" class="form-label profile-modal-label">Confirm your password</label>
                    <input type="password" class="form-control profile-modal-input" id="deleteAccountPassword" autocomplete="current-password">
                    <div id="deleteAccountError" class="form-text text-danger" style="display:none;"></div>
                </div>
            </div>
            <div class="modal-footer profile-modal-footer">
                <button type="button" class="btn profile-btn-cancel" data-bs-dismiss="modal">Cancel</button>
                <button type="button" class="btn profile-btn-remove" onclick="deleteAccount()">Delete My Account</button>
            </div>
        </div>
    </div>
</div>

<!-- Bio/Location Edit Modal -->
<div class="modal fade" id="bioModal" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog modal-dialog-centered">
        <div class="modal-content profile-modal-content">
            <div class="modal-header profile-modal-header">
                <h5 class="modal-title">Edit Profile Info</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
            </div>
            <div class="modal-body">
                <div class="mb-3">
                    <label class="form-label profile-modal-label">Location</label>
                    <div class="d-flex gap-2 align-items-center">
                        <input type="text" class="form-control profile-modal-input" id="editLocation" readonly placeholder="Pick on map..." value="<?php echo htmlspecialchars($displayLocation); ?>">
                        <button type="button" class="btn profile-btn-upload" onclick="openMapModal()" style="white-space:nowrap;">Pick on Map</button>
                    </div>
                    <input type="hidden" id="editLat" value="<?php echo htmlspecialchars($userLat); ?>">
                    <input type="hidden" id="editLng" value="<?php echo htmlspecialchars($userLng); ?>">
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
                    <label for="editRelationship" class="form-label profile-modal-label">Relationship Type</label>
                    <select class="form-control profile-modal-input" id="editRelationship">
                        <option value="">Select</option>
                        <option value="short_term" <?php echo $userRelationship === 'short_term' ? 'selected' : ''; ?>>Short term</option>
                        <option value="long_term" <?php echo $userRelationship === 'long_term' ? 'selected' : ''; ?>>Long term</option>
                        <option value="fun" <?php echo $userRelationship === 'fun' ? 'selected' : ''; ?>>Looking for fun</option>
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
<div class="modal fade" id="picModal" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog modal-dialog-centered">
        <div class="modal-content profile-modal-content">
            <div class="modal-header profile-modal-header">
                <h5 class="modal-title">Change Profile Picture</h5>
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
<div class="modal fade" id="photosModal" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog modal-dialog-centered modal-lg">
        <div class="modal-content profile-modal-content">
            <div class="modal-header profile-modal-header">
                <h5 class="modal-title">Manage Photos</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
            </div>
            <div class="modal-body">
                <div class="profile-photos-grid" id="photosGrid"></div>
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
<div class="modal fade" id="mapModal" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog modal-dialog-centered modal-lg">
        <div class="modal-content profile-modal-content">
            <div class="modal-header profile-modal-header">
                <h5 class="modal-title">Pick Your Location</h5>
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
<div class="modal fade" id="tagPickerModal" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog modal-dialog-centered">
        <div class="modal-content profile-modal-content">
            <div class="modal-header profile-modal-header">
                <h5 class="modal-title">Select Tags</h5>
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

<script>
    const allTags = <?php echo json_encode($allTags); ?>;
    let selectedAboutMe = <?php echo json_encode(array_values($aboutMeTagIds)); ?>;

    // Photos: primary (single) + carousel (array) kept separate
    let primaryPhoto = <?php echo json_encode($primaryPhoto); ?>;
    let carouselPhotos = <?php echo json_encode(array_values($carouselPhotos)); ?>;

    let currentBio = <?php echo json_encode($userBio); ?>;
    let currentGender = <?php echo json_encode($userGender); ?>;
    let currentLocation = <?php echo json_encode($displayLocation); ?>;
    let currentRelationship = <?php echo json_encode($userRelationship); ?>;

    const GENDER_LABELS = { male: 'Male', female: 'Female', 'non-binary': 'Non-binary' };
    const RELATIONSHIP_LABELS = { short_term: 'Short term', long_term: 'Long term', fun: 'Looking for fun' };

    // --- Bio / Location / Gender / Relationship modal ---
    function openBioModal() {
        document.getElementById('editBio').value = currentBio;
        document.getElementById('editLocation').value = currentLocation;
        document.getElementById('editGender').value = currentGender;
        document.getElementById('editRelationship').value = currentRelationship;
        document.getElementById('bioCharCount').textContent = currentBio.length;
        new bootstrap.Modal(document.getElementById('bioModal')).show();
    }

    document.getElementById('editBio').addEventListener('input', function() {
        document.getElementById('bioCharCount').textContent = this.value.length;
    });

    function saveBio() {
        const newBio = document.getElementById('editBio').value.trim();
        const newGender = document.getElementById('editGender').value;
        const newRelationship = document.getElementById('editRelationship').value;

        const formData = new FormData();
        formData.append('action', 'update_bio');
        formData.append('bio', newBio);
        formData.append('gender', newGender);
        formData.append('relationship_type', newRelationship);

        fetch(window.location.pathname, { method: 'POST', body: formData })
            .then(res => res.json())
            .then(data => {
                if (data.success) {
                    currentBio = newBio;
                    currentGender = newGender;
                    currentRelationship = newRelationship;
                    document.getElementById('bioDisplay').textContent = newBio || 'Not set';
                    document.getElementById('genderDisplay').textContent = GENDER_LABELS[newGender] || 'Not set';
                    document.getElementById('relationshipDisplay').textContent = RELATIONSHIP_LABELS[newRelationship] || 'Not set';
                    bootstrap.Modal.getInstance(document.getElementById('bioModal')).hide();
                } else {
                    alert('Failed to save: ' + (data.error || 'Unknown error'));
                }
            })
            .catch(err => alert('Error: ' + err.message));
    }

    // --- Profile picture modal (primary) ---
    let selectedPicFile = null;

    function openPicModal() {
        const preview = document.getElementById('picPreview');
        const empty = document.getElementById('picPreviewEmpty');
        if (primaryPhoto) {
            preview.src = primaryPhoto.photo_url;
            preview.style.display = '';
            empty.style.display = 'none';
        } else {
            preview.style.display = 'none';
            empty.style.display = '';
        }
        selectedPicFile = null;
        document.getElementById('picFileInput').value = '';
        document.getElementById('removePicBtn').style.display = primaryPhoto ? '' : 'none';
        new bootstrap.Modal(document.getElementById('picModal')).show();
    }

    document.getElementById('picFileInput').addEventListener('change', function(e) {
        const file = e.target.files[0];
        if (!file) return;
        if (file.size > 2 * 1024 * 1024) { alert('File must be under 2MB'); return; }
        selectedPicFile = file;
        const preview = document.getElementById('picPreview');
        preview.src = URL.createObjectURL(file);
        preview.style.display = '';
        document.getElementById('picPreviewEmpty').style.display = 'none';
    });

    function saveProfilePic() {
        if (!selectedPicFile) {
            bootstrap.Modal.getInstance(document.getElementById('picModal')).hide();
            return;
        }

        const saveBtn = document.querySelector('#picModal .profile-btn-save');
        if (saveBtn?.disabled) return;
        if (saveBtn) saveBtn.disabled = true;

        const formData = new FormData();
        formData.append('action', 'upload_photo');
        formData.append('photo', selectedPicFile);
        formData.append('is_primary', '1');

        fetch(window.location.pathname, { method: 'POST', body: formData })
            .then(res => res.json())
            .then(data => {
                if (data.success) {
                    // Server demoted the old primary to is_primary=0 — reflect that locally
                    if (primaryPhoto) carouselPhotos.unshift(primaryPhoto);
                    primaryPhoto = { photo_id: data.photo_id, photo_url: data.photo_url };
                    updatePrimaryPhotoDisplay();
                    updatePhotoThumbnails();
                    bootstrap.Modal.getInstance(document.getElementById('picModal')).hide();
                } else {
                    alert('Upload failed: ' + (data.error || 'Unknown error'));
                }
            })
            .catch(err => alert('Error: ' + err.message))
            .finally(() => {
                if (saveBtn) saveBtn.disabled = false;
            });
    }

    function removeProfilePic() {
        if (!primaryPhoto) return;
        const formData = new FormData();
        formData.append('action', 'delete_photo');
        formData.append('photo_id', primaryPhoto.photo_id);

        fetch(window.location.pathname, { method: 'POST', body: formData })
            .then(res => res.json())
            .then(data => {
                if (data.success) {
                    primaryPhoto = null;
                    updatePrimaryPhotoDisplay();
                    bootstrap.Modal.getInstance(document.getElementById('picModal')).hide();
                } else {
                    alert('Remove failed: ' + (data.error || 'Unknown error'));
                }
            })
            .catch(err => alert('Error: ' + err.message));
    }

    function updatePrimaryPhotoDisplay() {
        const el = document.getElementById('primaryPhotoDisplay');
        el.innerHTML = primaryPhoto
            ? `<img src="${primaryPhoto.photo_url}" class="settings-primary-photo-img" alt="Profile picture">`
            : '<div class="settings-primary-photo-empty">👤</div>';
    }

    // --- Photos modal (carousel only — primary is managed separately) ---
    function openPhotosModal() {
        renderPhotosGrid();
        new bootstrap.Modal(document.getElementById('photosModal')).show();
    }

    function renderPhotosGrid() {
        const grid = document.getElementById('photosGrid');
        grid.innerHTML = carouselPhotos.map((photo, i) => `
            <div class="profile-photo-thumb">
                <img src="${photo.photo_url}" alt="Photo ${i + 1}">
                <button class="profile-photo-delete" onclick="deleteCarouselPhoto(${i})">&times;</button>
            </div>
        `).join('');
        document.getElementById('addPhotoBtn').style.display = carouselPhotos.length >= 6 ? 'none' : '';
    }

    function updatePhotoThumbnails() {
        const container = document.getElementById('photoThumbnails');
        container.innerHTML = carouselPhotos.length === 0
            ? '<span class="settings-empty">No photos yet.</span>'
            : carouselPhotos.map(p => `<img src="${p.photo_url}" class="settings-photo-thumb" alt="Photo">`).join('');
    }

    document.getElementById('photoFileInput').addEventListener('change', function(e) {
        const file = e.target.files[0];
        if (!file) return;
        if (file.size > 2 * 1024 * 1024) { alert('File must be under 2MB'); return; }
        if (carouselPhotos.length >= 6) { alert('Maximum 6 photos allowed'); return; }

        const formData = new FormData();
        formData.append('action', 'upload_photo');
        formData.append('photo', file);
        formData.append('is_primary', '0');

        fetch(window.location.pathname, { method: 'POST', body: formData })
            .then(res => res.json())
            .then(data => {
                if (data.success) {
                    carouselPhotos.push({ photo_id: data.photo_id, photo_url: data.photo_url });
                    renderPhotosGrid();
                    updatePhotoThumbnails();
                } else {
                    alert('Upload failed: ' + (data.error || 'Unknown error'));
                }
            })
            .catch(err => alert('Error: ' + err.message));

        this.value = '';
    });

    function deleteCarouselPhoto(index) {
        const photo = carouselPhotos[index];
        const formData = new FormData();
        formData.append('action', 'delete_photo');
        formData.append('photo_id', photo.photo_id);

        fetch(window.location.pathname, { method: 'POST', body: formData })
            .then(res => res.json())
            .then(data => {
                if (data.success) {
                    carouselPhotos.splice(index, 1);
                    renderPhotosGrid();
                    updatePhotoThumbnails();
                } else {
                    alert('Delete failed: ' + (data.error || 'Unknown error'));
                }
            })
            .catch(err => alert('Error: ' + err.message));
    }

    // --- Tag picker (about_me only) ---
    function openTagPickerModal() {
        const grid = document.getElementById('tagPickerGrid');
        let colorIndex = 0;
        grid.innerHTML = allTags.map(tag => {
            const isSelected = selectedAboutMe.includes(parseInt(tag.tag_id));
            const color = colorIndex % 2 === 0 ? 'pink' : 'orange';
            colorIndex++;
            const pillClass = isSelected
                ? 'profile-pill profile-pill--' + color
                : 'profile-pill profile-pill--unselected';
            return '<span class="' + pillClass + '" data-tag-id="' + tag.tag_id + '" data-color="' + color + '" onclick="toggleTag(this)">' + tag.tag_name + '</span>';
        }).join('');
        new bootstrap.Modal(document.getElementById('tagPickerModal')).show();
    }

    function toggleTag(el) {
        const tagId = parseInt(el.dataset.tagId);
        const color = el.dataset.color;
        const idx = selectedAboutMe.indexOf(tagId);
        if (idx > -1) {
            selectedAboutMe.splice(idx, 1);
            el.className = 'profile-pill profile-pill--unselected';
        } else {
            selectedAboutMe.push(tagId);
            el.className = 'profile-pill profile-pill--' + color;
        }
    }

    function saveTags() {
        const formData = new FormData();
        formData.append('action', 'update_tags');
        formData.append('tag_ids', JSON.stringify(selectedAboutMe));
        formData.append('selection_type', 'about_me');

        fetch(window.location.pathname, { method: 'POST', body: formData })
            .then(res => res.json())
            .then(data => {
                if (data.success) {
                    updateAboutMePills();
                    bootstrap.Modal.getInstance(document.getElementById('tagPickerModal')).hide();
                } else {
                    alert('Failed to save: ' + (data.error || 'Unknown error'));
                }
            })
            .catch(err => alert('Error: ' + err.message));
    }

    function updateAboutMePills() {
        const container = document.getElementById('aboutMePills');
        const selected = allTags.filter(t => selectedAboutMe.includes(parseInt(t.tag_id)));
        if (selected.length === 0) {
            container.innerHTML = '<span class="settings-empty">No tags selected yet.</span>';
            return;
        }
        container.innerHTML = '';
        selected.forEach((tag, i) => {
            const pill = document.createElement('span');
            pill.className = 'profile-pill profile-pill--' + (i % 2 === 0 ? 'pink' : 'orange');
            pill.textContent = tag.tag_name;
            container.appendChild(pill);
        });
    }

    // --- Map picker ---
    let map = null;
    let mapMarker = null;
    let selectedLat = null;
    let selectedLng = null;

    function openMapModal() {
        const bioModalEl = document.getElementById('bioModal');
        const bioModalInstance = bootstrap.Modal.getInstance(bioModalEl);
        const mapModalEl = document.getElementById('mapModal');

        function showMap() {
            new bootstrap.Modal(mapModalEl).show();
        }

        if (bioModalInstance) {
            bioModalEl.addEventListener('hidden.bs.modal', function onHidden() {
                bioModalEl.removeEventListener('hidden.bs.modal', onHidden);
                showMap();
            });
            bioModalInstance.hide();
        } else {
            showMap();
        }

        mapModalEl.addEventListener('shown.bs.modal', function initMap() {
            if (!map) {
                const existingLat = parseFloat(document.getElementById('editLat').value);
                const existingLng = parseFloat(document.getElementById('editLng').value);
                const startLat = existingLat || 52.8;
                const startLng = existingLng || -7.5;
                const startZoom = existingLat ? 12 : 7;

                map = L.map('map').setView([startLat, startLng], startZoom);
                L.tileLayer('https://{s}.tile.openstreetmap.org/{z}/{x}/{y}.png', {
                    attribution: '&copy; OpenStreetMap contributors'
                }).addTo(map);

                if (existingLat && existingLng) {
                    mapMarker = L.marker([existingLat, existingLng]).addTo(map);
                    selectedLat = existingLat;
                    selectedLng = existingLng;
                    document.getElementById('confirmLocationBtn').disabled = false;
                }

                map.on('click', function(e) {
                    selectedLat = e.latlng.lat;
                    selectedLng = e.latlng.lng;
                    if (mapMarker) {
                        mapMarker.setLatLng(e.latlng);
                    } else {
                        mapMarker = L.marker(e.latlng).addTo(map);
                    }
                    document.getElementById('confirmLocationBtn').disabled = false;
                });
            } else {
                map.invalidateSize();
            }
            mapModalEl.removeEventListener('shown.bs.modal', initMap);
        });
    }

    function confirmLocation() {
        if (!selectedLat || !selectedLng) return;
        const btn = document.getElementById('confirmLocationBtn');
        btn.disabled = true;
        btn.textContent = 'Saving...';

        const formData = new FormData();
        formData.append('action', 'update_location');
        formData.append('lat', selectedLat);
        formData.append('lng', selectedLng);

        fetch(window.location.pathname, { method: 'POST', body: formData })
            .then(res => res.json())
            .then(data => {
                if (data.success) {
                    currentLocation = data.general_location;
                    document.getElementById('editLocation').value = data.general_location;
                    document.getElementById('editLat').value = selectedLat;
                    document.getElementById('editLng').value = selectedLng;
                    document.getElementById('locationDisplay').textContent = data.general_location;

                    const mapModalEl = document.getElementById('mapModal');
                    const bioModalEl = document.getElementById('bioModal');
                    mapModalEl.addEventListener('hidden.bs.modal', function reopenBio() {
                        mapModalEl.removeEventListener('hidden.bs.modal', reopenBio);
                        new bootstrap.Modal(bioModalEl).show();
                    });
                    bootstrap.Modal.getInstance(mapModalEl).hide();
                } else {
                    alert('Failed to save location: ' + (data.error || 'Unknown error'));
                }
            })
            .catch(err => alert('Error: ' + err.message))
            .finally(() => {
                btn.disabled = false;
                btn.textContent = 'Confirm Location';
            });
    }

    // --- Delete account ---
    function openDeleteAccountModal() {
        document.getElementById('deleteAccountPassword').value = '';
        document.getElementById('deleteAccountError').style.display = 'none';
        new bootstrap.Modal(document.getElementById('deleteAccountModal')).show();
    }

    function deleteAccount() {
        const passwordInput = document.getElementById('deleteAccountPassword');
        const errorEl = document.getElementById('deleteAccountError');
        const password = passwordInput.value;
        if (!password) {
            errorEl.textContent = 'Please enter your password.';
            errorEl.style.display = '';
            return;
        }

        const btn = document.querySelector('#deleteAccountModal .profile-btn-remove');
        if (btn?.disabled) return;
        if (btn) { btn.disabled = true; btn.textContent = 'Deleting...'; }
        errorEl.style.display = 'none';

        const formData = new FormData();
        formData.append('action', 'delete_account');
        formData.append('password', password);

        fetch(window.location.pathname, { method: 'POST', body: formData })
            .then(res => res.json())
            .then(data => {
                if (data.success) {
                    window.location.href = '/features/auth/logout.php';
                } else {
                    errorEl.textContent = data.error || 'Failed to delete';
                    errorEl.style.display = '';
                    if (btn) { btn.disabled = false; btn.textContent = 'Delete My Account'; }
                }
            })
            .catch(err => {
                errorEl.textContent = 'Error: ' + err.message;
                errorEl.style.display = '';
                if (btn) { btn.disabled = false; btn.textContent = 'Delete My Account'; }
            });
    }
</script>

<?php include __DIR__ . '/../../includes/footer.php'; ?>

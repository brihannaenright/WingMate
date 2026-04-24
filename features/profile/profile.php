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
    $stmt = $conn->prepare("SELECT user_id FROM Users WHERE user_id = ? AND account_status = 'active' AND user_type != 'administrator'");
    $stmt->bind_param('i', $profile_user_id);
    $stmt->execute();
    if ($stmt->get_result()->num_rows === 0) {
        $stmt->close();
        header('Location: /features/friends/friends.php?error=user_not_found');
        exit;
    }
    $stmt->close();

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

// Fetch profile data from DB
$stmt = $conn->prepare("SELECT first_name, last_name, date_of_birth, gender, general_location, user_bio FROM User_Profile WHERE user_id = ?");
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
$userBio = htmlspecialchars($profile['user_bio'] ?? '');

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

<link rel="stylesheet" href="profile.css">

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
                <p class="profile-bio" id="profileBio"><?php echo $userBio ?: 'No bio yet'; ?></p>
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

        <!-- Bottom-Right Card: Looking For -->
        <div class="profile-looking-card" id="profileLookingForPills">
            <h3 class="profile-looking-title">Looking For</h3>
            <div class="profile-looking-pills">
                <?php foreach ($userLookingForTags as $tag): ?>
                    <span class="profile-looking-pill"><?php echo htmlspecialchars($tag['tag_name']); ?></span>
                <?php endforeach; ?>
            </div>
        </div>
    </div>
</div>

<script>
    // Combine primary and carousel photos for the read-only carousel
    const primaryPhotoData = <?php echo json_encode($primaryPhoto); ?>;
    const carouselPhotosData = <?php echo json_encode(array_values($userPhotos)); ?>;
    let userPhotos = [];
    if (primaryPhotoData) userPhotos.push(primaryPhotoData);
    userPhotos = userPhotos.concat(carouselPhotosData);

    const currentUserId = <?php echo json_encode($current_user_id); ?>;
    const profileUserId = <?php echo json_encode($profile_user_id); ?>;
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
                        headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
                        body: `comment_id=${commentId}&comment_text=${encodeURIComponent(newText)}`
                    });

                    const data = await response.json();

                    if (data.success) {
                        const response2 = await fetch(`/features/profile/comments-api.php?action=get_comments&profile_owner_id=${profileUserId}`);
                        const data2 = await response2.json();
                        if (data2.success) renderComments(data2.comments, currentUserId);
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

        document.querySelectorAll('.delete-comment-btn').forEach(btn => {
            btn.addEventListener('click', async function() {
                if (!confirm('Are you sure you want to delete this comment?')) return;

                const commentItem = this.closest('.comment-item');
                const commentId = commentItem.dataset.commentId;

                try {
                    const response = await fetch('/features/profile/comments-api.php?action=delete_comment', {
                        method: 'POST',
                        headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
                        body: `comment_id=${commentId}`
                    });

                    const data = await response.json();

                    if (data.success) {
                        const response2 = await fetch(`/features/profile/comments-api.php?action=get_comments&profile_owner_id=${profileUserId}`);
                        const data2 = await response2.json();
                        if (data2.success) renderComments(data2.comments, currentUserId);
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

    document.addEventListener('DOMContentLoaded', function() {
        renderComments(initialComments, currentUserId);
    });
</script>
<script src="profile.js"></script>

<?php include __DIR__ . '/../../includes/footer.php'; ?>

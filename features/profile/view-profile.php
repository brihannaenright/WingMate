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

// Get the user_id to view from query parameter
$view_user_id = isset($_GET['user_id']) ? (int)$_GET['user_id'] : null;

// Validate user_id
if (!$view_user_id || $view_user_id <= 0) {
    header('Location: /features/friends/friends.php');
    exit;
}

// Don't allow viewing own profile through this page (redirect to regular profile)
if ($view_user_id === $current_user_id) {
    header('Location: /features/profile/profile.php');
    exit;
}

// Verify that the viewed user exists and is active
$stmt = $conn->prepare("SELECT user_id FROM Users WHERE user_id = ? AND account_status = 'active' AND user_type != 'administrator'");
$stmt->bind_param('i', $view_user_id);
$stmt->execute();
if ($stmt->get_result()->num_rows === 0) {
    $stmt->close();
    header('Location: /features/friends/friends.php?error=user_not_found');
    exit;
}
$stmt->close();

// Fetch profile data from DB
$stmt = $conn->prepare("SELECT first_name, last_name, date_of_birth, gender, general_location, latitude, longitude, user_bio FROM User_Profile WHERE user_id = ?");
$stmt->bind_param('i', $view_user_id);
$stmt->execute();
$profile = $stmt->get_result()->fetch_assoc();
$stmt->close();

if (!$profile) {
    header('Location: /features/friends/friends.php?error=profile_not_found');
    exit;
}

// Calculate age from date of birth
$age = '';
if (!empty($profile['date_of_birth'])) {
    $dob = new DateTime($profile['date_of_birth']);
    $age = (string) $dob->diff(new DateTime('today'))->y;
}

$firstName = htmlspecialchars($profile['first_name'] ?? '');
$lastName = htmlspecialchars($profile['last_name'] ?? '');
$displayName = trim("$firstName $lastName") ?: 'User';
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
$stmt->bind_param('i', $view_user_id);
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
$stmt->bind_param('i', $view_user_id);
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

// Check if current user is friends with the viewed user
$isFriend = false;
$stmt = $conn->prepare("
    SELECT 1 FROM Friendship 
    WHERE status = 'accepted'
    AND (
        (user_id = ? AND friend_id = ?) OR 
        (user_id = ? AND friend_id = ?)
    )
    LIMIT 1
");
$stmt->bind_param('iiii', $current_user_id, $view_user_id, $view_user_id, $current_user_id);
$stmt->execute();
$isFriend = $stmt->get_result()->num_rows > 0;
$stmt->close();

// Fetch comments for this profile
$comments = [];
if ($isFriend) {
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
    $stmt->bind_param('i', $view_user_id);
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
        <!-- Back Button -->
        <div class="profile-back-button" style="margin-bottom: 15px;">
            <a href="/features/friends/friends.php" class="btn btn-secondary">
                ← Back to Friends
            </a>
        </div>

        <!-- 1. Profile Banner (Top Left) -->
        <div class="profile-banner">
            <div class="profile-banner-bg"></div>
            <div class="profile-avatar-wrapper">
                <?php if ($primaryPhoto): ?>
                    <img src="<?php echo htmlspecialchars($primaryPhoto['photo_url']); ?>" alt="Profile photo" class="profile-avatar">
                    <div class="profile-avatar-empty" id="avatarEmpty" style="display:none;">+</div>
                <?php else: ?>
                    <img src="" alt="No photo" class="profile-avatar" style="display:none;">
                    <div class="profile-avatar-empty" id="avatarEmpty">👤</div>
                <?php endif; ?>
            </div>
            <div class="profile-info-card">
                <h1 class="profile-name-age" id="profileNameAge"><?php echo $displayName . $displayAge; ?></h1>
                <div class="profile-location" id="profileLocation">
                    <span class="profile-location-icon">📍</span> <?php echo $displayLocation ?: 'Location not set'; ?>
                </div>
                <p class="profile-bio" id="profileBio"><?php echo $userBio; ?></p>
            </div>
        </div>

        <!-- 2. Photo Carousel Card (Top Right) -->
        <div class="profile-photos-card">
            <img id="carouselImage" src="" alt="No photos yet" class="profile-photo-main" style="display:none;">
            <div id="carouselEmpty" class="profile-photo-empty">No additional photos</div>
            <div class="profile-photo-dots"></div>
            <div class="profile-photo-nav">
                <button class="profile-photo-arrow" onclick="prevPhoto()">←</button>
                <button class="profile-photo-arrow" onclick="nextPhoto()">→</button>
            </div>
        </div>

        <!-- 3. Tags sidebar and Friends Surveys (Bottom Left) -->
        <div class="profile-tags-card">
            <div class="profile-tags-sidebar" id="aboutMePills">
                <h4 class="profile-tags-title">About Me</h4>
                <?php $i = 0; foreach ($userAboutMeTags as $tag): ?>
                    <span class="profile-pill profile-pill--<?php echo $i % 2 === 0 ? 'pink' : 'orange'; ?>"><?php echo htmlspecialchars($tag['tag_name']); ?></span>
                <?php $i++; endforeach; ?>
            </div>

            <div class="profile-friends-card">
                <h3 class="profile-friends-title">What My Friends Say About Me</h3>
                
                <!-- Comments Section -->
                <div class="friends-comments-section" id="commentsSection">
                    <!-- Comment form - only show if user is a friend -->
                    <?php if ($isFriend && $current_user_id !== $view_user_id): ?>
                        <div class="comment-form-container" id="commentFormContainer">
                            <textarea 
                                id="commentTextarea" 
                                class="comment-textarea" 
                                placeholder="Share what you appreciate about <?php echo htmlspecialchars($firstName); ?>... (max 500 characters)"
                                maxlength="500"></textarea>
                            <div class="comment-form-footer">
                                <span class="char-count"><span id="charCount">0</span>/500</span>
                                <button class="btn btn-primary btn-sm" id="submitCommentBtn">Add Comment</button>
                            </div>
                            <div id="commentError" class="alert alert-danger d-none mt-2"></div>
                        </div>
                    <?php endif; ?>
                    
                    <!-- Comments list -->
                    <div class="comments-list-container" id="commentsListContainer">
                        <?php if (empty($comments)): ?>
                            <p class="text-muted text-center py-3">No comments yet</p>
                        <?php else: ?>
                            <?php foreach ($comments as $comment): ?>
                                <div class="comment-item" data-comment-id="<?php echo (int)$comment['comment_id']; ?>">
                                    <div class="comment-header">
                                        <div class="comment-author">
                                            <?php if ($comment['photo_url']): ?>
                                                <img src="/Uploads/<?php echo htmlspecialchars($comment['photo_url']); ?>" alt="<?php echo htmlspecialchars($comment['first_name']); ?>" class="comment-avatar">
                                            <?php else: ?>
                                                <div class="comment-avatar-placeholder">👤</div>
                                            <?php endif; ?>
                                            <div class="comment-author-info">
                                                <strong><?php echo htmlspecialchars($comment['first_name'] . ' ' . $comment['last_name']); ?></strong>
                                                <small class="text-muted"><?php echo date('M d, Y', strtotime($comment['created_at'])); ?></small>
                                                <?php if ($comment['updated_at'] !== $comment['created_at']): ?>
                                                    <small class="text-muted">(edited)</small>
                                                <?php endif; ?>
                                            </div>
                                        </div>
                                        <?php if ($current_user_id === $comment['commenter_id']): ?>
                                            <div class="comment-actions">
                                                <button class="btn-action edit-comment-btn" title="Edit">✏️</button>
                                                <button class="btn-action delete-comment-btn" title="Delete">🗑️</button>
                                            </div>
                                        <?php endif; ?>
                                    </div>
                                    <p class="comment-text" id="commentText<?php echo (int)$comment['comment_id']; ?>"><?php echo htmlspecialchars($comment['comment_text']); ?></p>
                                    
                                    <!-- Edit form (hidden by default) -->
                                    <?php if ($current_user_id === $comment['commenter_id']): ?>
                                        <div class="comment-edit-form d-none" id="editForm<?php echo (int)$comment['comment_id']; ?>">
                                            <textarea class="comment-textarea" id="editTextarea<?php echo (int)$comment['comment_id']; ?>" maxlength="500"><?php echo htmlspecialchars($comment['comment_text']); ?></textarea>
                                            <div class="comment-form-footer mt-2">
                                                <span class="char-count"><span class="edit-char-count">0</span>/500</span>
                                                <button class="btn btn-primary btn-sm save-comment-btn" data-comment-id="<?php echo (int)$comment['comment_id']; ?>">Save</button>
                                                <button class="btn btn-secondary btn-sm cancel-edit-btn">Cancel</button>
                                            </div>
                                        </div>
                                    <?php endif; ?>
                                </div>
                            <?php endforeach; ?>
                        <?php endif; ?>
                    </div>
                </div>
            </div>
        </div>

        <!-- 4. Looking For (Bottom Right) -->
        <div class="profile-looking-card">
            <h3 class="profile-looking-title">Looking For</h3>
            <div class="profile-looking-pills" id="lookingForPills">
                <?php if (empty($userLookingForTags)): ?>
                    <span class="profile-looking-empty">Not specified</span>
                <?php else: ?>
                    <?php foreach ($userLookingForTags as $tag): ?>
                        <span class="profile-looking-pill"><?php echo htmlspecialchars($tag['tag_name']); ?></span>
                    <?php endforeach; ?>
                <?php endif; ?>
            </div>
        </div>

    </div>
</div>

<script>
    const allTags = <?php echo json_encode($allTags); ?>;
    const aboutMeTagIds = <?php echo json_encode(array_values($aboutMeTagIds)); ?>;
    const lookingForTagIds = <?php echo json_encode(array_values($lookingForTagIds)); ?>;
    const userPhotos = <?php echo json_encode(array_values($userPhotos)); ?>;
    const primaryPhotoId = <?php echo json_encode($primaryPhoto ? (int)$primaryPhoto['photo_id'] : null); ?>;
    const viewUserId = <?php echo json_encode($view_user_id); ?>;
    const currentUserId = <?php echo json_encode($current_user_id); ?>;
    const isFriend = <?php echo json_encode($isFriend); ?>;

    // --- Carousel state ---
    // Initialize photos from DB data
    let photos = (typeof userPhotos !== 'undefined' ? userPhotos : []).map(p => ({
        id: p.photo_id,
        url: p.photo_url
    }));
    let currentIndex = 0;

    function updateCarousel() {
        const img = document.getElementById('carouselImage');
        const empty = document.getElementById('carouselEmpty');
        const dotsContainer = document.querySelector('.profile-photo-dots');

        if (photos.length === 0) {
            img.style.display = 'none';
            empty.style.display = '';
            dotsContainer.innerHTML = '';
            return;
        }

        empty.style.display = 'none';
        img.style.display = '';

        img.style.opacity = '0.7';
        setTimeout(() => {
            img.src = photos[currentIndex].url;
            img.style.opacity = '1';
        }, 100);

        // Rebuild dots to match photo count
        dotsContainer.innerHTML = photos.map((_, i) =>
            `<span class="profile-photo-dot ${i === currentIndex ? 'active' : ''}" onclick="setPhoto(${i})"></span>`
        ).join('');
    }

    function nextPhoto() {
        if (photos.length === 0) return;
        currentIndex = (currentIndex + 1) % photos.length;
        updateCarousel();
    }

    function prevPhoto() {
        if (photos.length === 0) return;
        currentIndex = (currentIndex - 1 + photos.length) % photos.length;
        updateCarousel();
    }

    function setPhoto(index) {
        currentIndex = index;
        updateCarousel();
    }

    // --- Comment Management ---
    const commentTextarea = document.getElementById('commentTextarea');
    const submitCommentBtn = document.getElementById('submitCommentBtn');
    const charCount = document.getElementById('charCount');
    const commentError = document.getElementById('commentError');
    const commentsListContainer = document.getElementById('commentsListContainer');

    if (commentTextarea) {
        commentTextarea.addEventListener('input', function() {
            charCount.textContent = this.value.length;
        });

        submitCommentBtn.addEventListener('click', async function() {
            const commentText = commentTextarea.value.trim();

            if (!commentText) {
                showCommentError('Please enter a comment');
                return;
            }

            if (commentText.length > 500) {
                showCommentError('Comment cannot exceed 500 characters');
                return;
            }

            try {
                submitCommentBtn.disabled = true;
                submitCommentBtn.textContent = 'Adding...';
                commentError.classList.add('d-none');

                const response = await fetch('/features/profile/comments-api.php?action=add_comment', {
                    method: 'POST',
                    headers: {
                        'Content-Type': 'application/x-www-form-urlencoded',
                    },
                    body: `profile_owner_id=${viewUserId}&comment_text=${encodeURIComponent(commentText)}`
                });

                const data = await response.json();

                if (data.success) {
                    commentTextarea.value = '';
                    charCount.textContent = '0';
                    reloadComments();
                } else {
                    showCommentError(data.error || 'Failed to add comment');
                }
            } catch (error) {
                showCommentError('An error occurred. Please try again.');
                console.error('Error:', error);
            } finally {
                submitCommentBtn.disabled = false;
                submitCommentBtn.textContent = 'Add Comment';
            }
        });
    }

    function showCommentError(message) {
        commentError.textContent = message;
        commentError.classList.remove('d-none');
    }

    async function reloadComments() {
        try {
            const response = await fetch(`/features/profile/comments-api.php?action=get_comments&profile_owner_id=${viewUserId}`);
            const data = await response.json();

            if (data.success) {
                renderComments(data.comments, data.currentUserId);
            }
        } catch (error) {
            console.error('Error loading comments:', error);
        }
    }

    function renderComments(comments, currentUserId) {
        if (comments.length === 0) {
            commentsListContainer.innerHTML = '<p class="text-muted text-center py-3">No comments yet</p>';
            return;
        }

        commentsListContainer.innerHTML = comments.map(comment => {
            const isOwner = currentUserId === comment.commenter_id;
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
                        reloadComments();
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
                        reloadComments();
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

    // Initialize carousel and comments on page load
    document.addEventListener('DOMContentLoaded', function() {
        updateCarousel();
        if (isFriend && currentUserId !== viewUserId) {
            attachCommentEventListeners();
        }
    });
</script>

<?php include __DIR__ . '/../../includes/footer.php'; ?>

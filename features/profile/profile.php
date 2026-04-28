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

$genderLabels = ['male' => 'Male', 'female' => 'Female', 'non-binary' => 'Non-binary'];
$displayGender = $genderLabels[$profile['gender'] ?? ''] ?? '';

// Fetch user's "about_me" tags
$userAboutMeTags = [];
$stmt = $conn->prepare("SELECT t.tag_id, t.tag_name, t.tag_type FROM User_Tags ut JOIN Tags t ON ut.tag_id = t.tag_id WHERE ut.user_id = ? AND ut.selection_type = 'about_me' ORDER BY t.tag_name");
$stmt->bind_param('i', $profile_user_id);
$stmt->execute();
$result = $stmt->get_result();
while ($row = $result->fetch_assoc()) {
    $userAboutMeTags[] = $row;
}
$stmt->close();

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

<div class="profile-page bg-light min-vh-100 py-4">
    <div class="container mx-auto">
        <?php if (!$isOwnProfile): ?>
            <div class="profile-back-button" style="margin-bottom: 15px;">
                <a href="/features/friends/friends.php" class="btn btn-secondary">← Back to Friends</a>
            </div>
        <?php endif; ?>

        <div class="row g-4">

            <!-- Banner (top-left) -->
            <div class="col-12 col-md-6 col-lg-7">
                <div class="profile-banner position-relative overflow-hidden h-100 rounded d-flex align-items-stretch">
                    <div class="profile-banner-bg"></div>
                    <div class="profile-avatar-wrapper d-flex align-items-center p-3 flex-shrink-0 position-relative">
                        <?php if ($primaryPhoto): ?>
                            <img class="profile-avatar rounded-circle object-fit-cover border border-white bg-white" src="<?php echo $primaryPhoto['photo_url']; ?>" alt="Profile photo">
                        <?php else: ?>
                            <div class="profile-avatar-empty rounded-circle d-flex align-items-center justify-content-center">👤</div>
                        <?php endif; ?>
                    </div>

                    <div class="card profile-info-card position-relative flex-grow-1 m-3 m-md-4 p-4 rounded border-0 justify-content-center">
                        <h2 class="profile-name-age"><?php echo $displayName . $displayAge; ?></h2>
                        <p class="profile-location">
                            <span class="profile-location-icon">📍</span>
                            <?php echo $displayLocation ?: 'Location not set'; ?>
                        </p>
                        <?php if ($displayGender): ?>
                            <div class="profile-bio text-muted mb-2">Gender: <?php echo $displayGender; ?></div>
                        <?php endif; ?>
                        <p class="profile-bio text-muted" id="profileBio"><?php echo $userBio ?: 'No bio yet'; ?></p>
                    </div>
                </div>
            </div>

            <!-- Photo Carousel (top-right) -->
            <div class="col-12 col-md-6 col-lg-5">
                <div class="profile-photos-card rounded d-flex flex-column p-3 position-relative">
                    <div id="profilePhotoCarousel" class="carousel slide profile-photo-carousel flex-grow-1 mh-0 rounded overflow-hidden position-relative" data-bs-ride="false" data-bs-interval="false">
                        <div class="carousel-indicators"></div>
                        <div class="carousel-inner"></div>
                        <button class="carousel-control-prev" type="button" data-bs-target="#profilePhotoCarousel" data-bs-slide="prev">
                            <span class="carousel-control-prev-icon" aria-hidden="true"></span>
                            <span class="visually-hidden">Previous</span>
                        </button>
                        <button class="carousel-control-next" type="button" data-bs-target="#profilePhotoCarousel" data-bs-slide="next">
                            <span class="carousel-control-next-icon" aria-hidden="true"></span>
                            <span class="visually-hidden">Next</span>
                        </button>
                    </div>
                    <div class="profile-photo-empty d-none d-flex align-items-center justify-content-center flex-grow-1 rounded p-3">No Photos</div>
                </div>
            </div>

            <!-- Tags Sidebar + Friends (full-width row 2 with inner sub-row) -->
            <div class="col-12">
                <div class="profile-tags-card row g-4">
                    <!-- About Me Tags Sidebar -->
                    <div class="col-12 col-lg-5">
                        <div class="card profile-tags-sidebar h-100 border-0 rounded p-4" id="aboutMePills">
                            <h3 class="profile-tags-title text-center fw-bold mb-3">About Me</h3>
                            <?php foreach ($userAboutMeTags as $index => $tag): ?>
                                <span class="badge rounded-pill profile-pill profile-pill--<?php echo $index % 2 === 0 ? 'pink' : 'orange'; ?> w-100 fw-semibold">
                                    <?php echo htmlspecialchars($tag['tag_name']); ?>
                                </span>
                            <?php endforeach; ?>
                        </div>
                    </div>

                    <!-- What My Friends Say Comments Card -->
                    <div class="col-12 col-lg-7">
                        <div class="card profile-friends-card h-100 border-0 rounded p-4 position-relative overflow-hidden">
                            <h3 class="profile-friends-title text-center fw-bold mb-3 position-relative z-1">What My Friends Say</h3>

                            <?php if (!$isOwnProfile && $isFriend): ?>
                                <!-- Comment Input Form for Friends -->
                                <div class="friends-comments-section d-flex flex-column gap-3 position-relative z-1">
                                    <div id="commentError" class="alert alert-danger d-none"></div>
                                    <textarea id="commentText" class="form-control comment-textarea" placeholder="Leave a comment for this person..." maxlength="500"></textarea>
                                    <div class="comment-form-footer d-flex justify-content-between align-items-center gap-2">
                                        <span class="char-count text-muted small"><span id="charCount">0</span>/500</span>
                                        <button id="submitCommentBtn" class="btn btn-primary fw-semibold" onclick="saveComment()">Post Comment</button>
                                    </div>
                                </div>
                                <hr class="my-4 opacity-25">
                            <?php endif; ?>

                            <div id="commentsListContainer" class="comments-list position-relative z-1">
                                <!-- Populated by JS -->
                            </div>
                        </div>
                    </div>
                </div>
            </div>

        </div>
    </div>
</div>

<script>
    const userPhotos = <?php echo json_encode(array_values($userPhotos)); ?>;
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
                            <textarea class="form-control comment-textarea" id="editTextarea${comment.comment_id}" maxlength="500">${comment.comment_text}</textarea>
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
        
        const carousel = document.getElementById('profilePhotoCarousel');
        const emptyState = document.querySelector('.profile-photo-empty');
        
        if (userPhotos.length > 0) {
            carousel.classList.remove('d-none');
            emptyState.classList.add('d-none');
        } else {
            carousel.classList.add('d-none');
            emptyState.classList.remove('d-none');
        }
    });

    // --- Photo carousel ---
    const photos = (typeof userPhotos !== 'undefined' ? userPhotos : []);
    const carouselEl = document.getElementById('profilePhotoCarousel');
    const carouselEmpty = document.querySelector('.profile-photo-empty');

    function renderCarousel() {
        if (!carouselEl) return;
        const inner = carouselEl.querySelector('.carousel-inner');
        const indicators = carouselEl.querySelector('.carousel-indicators');

        if (typeof bootstrap !== 'undefined') {
            const existing = bootstrap.Carousel.getInstance(carouselEl);
            if (existing) existing.dispose();
        }

        inner.innerHTML = '';
        indicators.innerHTML = '';

        if (photos.length === 0) {
            carouselEl.style.display = 'none';
            if (carouselEmpty) carouselEmpty.style.display = '';
            return;
        }
        carouselEl.style.display = '';
        if (carouselEmpty) carouselEmpty.style.display = 'none';

        photos.forEach((p, i) => {
            const item = document.createElement('div');
            item.className = 'carousel-item' + (i === 0 ? ' active' : '');
            const img = document.createElement('img');
            img.src = p.photo_url;
            img.className = 'd-block w-100 h-100 object-fit-cover profile-photo-img';
            img.alt = '';
            item.appendChild(img);
            inner.appendChild(item);

            const indicator = document.createElement('button');
            indicator.type = 'button';
            indicator.dataset.bsTarget = '#profilePhotoCarousel';
            indicator.dataset.bsSlideTo = String(i);
            indicator.setAttribute('aria-label', 'Slide ' + (i + 1));
            if (i === 0) {
                indicator.className = 'active';
                indicator.setAttribute('aria-current', 'true');
            }
            indicators.appendChild(indicator);
        });
    }

    // --- Friend comments posting ---
    document.getElementById('commentText')?.addEventListener('input', function() {
        document.getElementById('charCount').textContent = this.value.length;
    });

    function saveComment() {
        const commentText = document.getElementById('commentText')?.value.trim();
        if (!commentText) {
            alert('Please enter a comment');
            return;
        }

        const formData = new FormData();
        formData.append('action', 'add_comment');
        formData.append('comment_text', commentText);
        formData.append('profile_owner_id', profileUserId);

        fetch('/features/profile/comments-api.php', { method: 'POST', body: formData })
            .then(res => res.json())
            .then(data => {
                if (data.success) {
                    document.getElementById('commentText').value = '';
                    document.getElementById('charCount').textContent = '0';
                    const errorEl = document.getElementById('commentError');
                    if (errorEl) errorEl.classList.add('d-none');
                    loadComments();
                } else {
                    const errorEl = document.getElementById('commentError');
                    if (errorEl) {
                        errorEl.textContent = data.error || 'Failed to post comment';
                        errorEl.classList.remove('d-none');
                    }
                }
            })
            .catch(err => {
                const errorEl = document.getElementById('commentError');
                if (errorEl) {
                    errorEl.textContent = 'Error: ' + err.message;
                    errorEl.classList.remove('d-none');
                }
            });
    }

    function loadComments() {
        const pUserId = (typeof profileUserId !== 'undefined') ? profileUserId : null;
        if (!pUserId) return;

        fetch('/features/profile/comments-api.php?action=get_comments&profile_owner_id=' + pUserId)
            .then(res => res.json())
            .then(data => {
                if (data.success && typeof renderComments === 'function') {
                    const cUserId = (typeof currentUserId !== 'undefined') ? currentUserId : null;
                    renderComments(data.comments, cUserId);
                }
            })
            .catch(err => console.error('Error loading comments:', err));
    }

    // Render carousel on page load
    renderCarousel();
</script>

<?php include __DIR__ . '/../../includes/footer.php'; ?>
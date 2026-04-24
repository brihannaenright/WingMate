// --- Carousel state ---
// Initialize photos from DB data (set in profile.php)
let photos = (typeof userPhotos !== 'undefined' ? userPhotos : []);
let currentIndex = 0;

function updateCarousel() {
    const img = document.getElementById('carouselImage');
    const empty = document.querySelector('.profile-photo-empty');
    const dotsContainer = document.querySelector('.profile-photo-dots');

    if (!img) return;

    if (photos.length === 0) {
        img.style.display = 'none';
        if (empty) empty.style.display = '';
        if (dotsContainer) dotsContainer.innerHTML = '';
        return;
    }

    if (empty) empty.style.display = 'none';
    img.style.display = '';

    img.style.opacity = '0.7';
    setTimeout(() => {
        img.src = photos[currentIndex].photo_url;
        img.style.opacity = '1';
    }, 100);

    if (dotsContainer) {
        dotsContainer.innerHTML = photos.map((_, i) =>
            `<span class="profile-photo-dot ${i === currentIndex ? 'active' : ''}" onclick="setPhoto(${i})"></span>`
        ).join('');
    }
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

// Render carousel on page load if photos exist
updateCarousel();

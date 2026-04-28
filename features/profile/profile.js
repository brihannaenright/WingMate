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
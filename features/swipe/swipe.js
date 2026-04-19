// Candidate deck loaded from PHP via swipeCandidates
const el = {
    container: document.querySelector('.swipe-container'),
    empty: document.querySelector('.swipe-empty'),
    avatar: document.querySelector('.swipe-avatar'),
    nameAge: document.querySelector('.swipe-name-age'),
    location: document.querySelector('.swipe-location'),
    bio: document.querySelector('.swipe-bio'),
    gender: document.querySelector('.swipe-gender'),
    carouselImg: document.querySelector('.swipe-photo-main'),
    carouselEmpty: document.querySelector('.swipe-photo-empty'),
    dots: document.querySelector('.swipe-photo-dots'),
    aboutMe: document.querySelector('.swipe-tags-sidebar'),
    lookingFor: document.querySelector('.swipe-looking-pills'),
    relationshipType: document.querySelector('.swipe-relationship-type'),
};

const RELATIONSHIP_LABELS = {
    short_term: 'Short term',
    long_term: 'Long term',
    fun: 'Looking for fun',
};

const GENDER_LABELS = {
    male: 'Male',
    female: 'Female',
    'non-binary': 'Non-binary',
};

let swipeDeck = (typeof swipeCandidates !== 'undefined' ? swipeCandidates : []).slice();
let swipeCardIndex = 0;
let swipePhotoIndex = 0;

function currentCandidate() {
    return swipeDeck[swipeCardIndex] || null;
}

function swipeRender() {
    const c = currentCandidate();
    if (!c) {
        el.container.style.display = 'none';
        el.empty.style.display = '';
        return;
    }
    el.container.style.display = '';
    el.empty.style.display = 'none';

    el.nameAge.textContent = `${c.first_name}, ${c.age}`;

    const locParts = [];
    if (c.general_location) locParts.push(c.general_location);
    locParts.push(`${c.distance_km} km away`);
    el.location.innerHTML =
        '<span class="swipe-location-icon">📍</span> ' + locParts.join(' · ');

    const genderLabel = GENDER_LABELS[c.gender];
    if (genderLabel) {
        el.gender.textContent = 'Gender: ' + genderLabel;
        el.gender.style.display = '';
    } else {
        el.gender.textContent = '';
        el.gender.style.display = 'none';
    }

    el.bio.textContent = c.user_bio || '';

    if (c.primary_photo) {
        el.avatar.src = c.primary_photo.photo_url;
        el.avatar.style.display = '';
    } else {
        el.avatar.removeAttribute('src');
        el.avatar.style.display = 'none';
    }

    swipePhotoIndex = 0;
    renderCarousel();

    el.aboutMe.innerHTML = '<h4 class="swipe-tags-title">About Me</h4>';
    c.about_me_tags.forEach((name, i) => {
        const pill = document.createElement('span');
        pill.className = 'swipe-pill swipe-pill--' + (i % 2 === 0 ? 'pink' : 'orange');
        pill.textContent = name;
        el.aboutMe.appendChild(pill);
    });

    const relLabel = RELATIONSHIP_LABELS[c.relationship_type];
    if (relLabel) {
        el.relationshipType.textContent = relLabel;
        el.relationshipType.style.display = '';
    } else {
        el.relationshipType.textContent = '';
        el.relationshipType.style.display = 'none';
    }

    el.lookingFor.innerHTML = '';
    c.looking_for_tags.forEach(name => {
        const pill = document.createElement('span');
        pill.className = 'swipe-looking-pill';
        pill.textContent = name;
        el.lookingFor.appendChild(pill);
    });
}

function renderCarousel() {
    const c = currentCandidate();
    const photos = c ? c.photos : [];
    if (photos.length === 0) {
        el.carouselImg.style.display = 'none';
        el.carouselEmpty.style.display = '';
        el.dots.innerHTML = '';
        return;
    }
    el.carouselImg.style.display = '';
    el.carouselImg.src = photos[swipePhotoIndex].photo_url;
    el.carouselEmpty.style.display = 'none';
    el.dots.innerHTML = photos.map((_, i) =>
        `<span class="swipe-photo-dot${i === swipePhotoIndex ? ' active' : ''}" data-photo-index="${i}"></span>`
    ).join('');
    el.dots.querySelectorAll('.swipe-photo-dot').forEach(dot => {
        dot.addEventListener('click', () => {
            swipePhotoIndex = parseInt(dot.dataset.photoIndex, 10);
            renderCarousel();
        });
    });
}

function nextPhoto() {
    const photos = currentCandidate()?.photos || [];
    if (photos.length === 0) return;
    swipePhotoIndex = (swipePhotoIndex + 1) % photos.length;
    renderCarousel();
}

function prevPhoto() {
    const photos = currentCandidate()?.photos || [];
    if (photos.length === 0) return;
    swipePhotoIndex = (swipePhotoIndex - 1 + photos.length) % photos.length;
    renderCarousel();
}

function swipeAction(type) {
    const c = currentCandidate();
    if (!c) return;

    const formData = new FormData();
    formData.append('action', 'swipe');
    formData.append('liked_id', c.user_id);
    formData.append('swipe_type', type);

    fetch(window.location.pathname, { method: 'POST', body: formData })
        .then(res => res.text().then(text => {
            if (!res.ok) throw new Error('Server ' + res.status + ': ' + text.substring(0, 200));
            try { return JSON.parse(text); } catch { throw new Error('Invalid response: ' + text.substring(0, 200)); }
        }))
        .then(data => {
            if (!data.success) {
                alert('Swipe failed: ' + (data.error || 'Unknown error'));
                return;
            }
            swipeCardIndex++;
            swipeRender();
        })
        .catch(err => alert('Error: ' + err.message));
}

document.querySelector('.swipe-btn--skip').addEventListener('click', () => swipeAction('dislike'));
document.querySelector('.swipe-btn--match').addEventListener('click', () => swipeAction('like'));
const arrows = document.querySelectorAll('.swipe-photo-arrow');
arrows[0].addEventListener('click', prevPhoto);
arrows[1].addEventListener('click', nextPhoto);

swipeRender();

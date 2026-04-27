// Candidate deck loaded from PHP via swipeCandidates
const el = {
    container: document.querySelector('.swipe-container'),
    empty: document.querySelector('.swipe-empty'),
    avatar: document.querySelector('.swipe-avatar'),
    nameAge: document.querySelector('.swipe-name-age'),
    location: document.querySelector('.swipe-location'),
    bio: document.querySelector('.swipe-bio'),
    gender: document.querySelector('.swipe-gender'),
    carousel: document.getElementById('swipePhotoCarousel'),
    carouselEmpty: document.querySelector('.swipe-photo-empty'),
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

    renderCarousel();

    el.aboutMe.innerHTML = '<h4 class="swipe-tags-title">About Me</h4>';
    c.about_me_tags.forEach((name, i) => {
        const pill = document.createElement('span');
        pill.className = 'badge rounded-pill swipe-pill swipe-pill--' + (i % 2 === 0 ? 'pink' : 'orange');
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
        pill.className = 'badge rounded-pill swipe-looking-pill';
        pill.textContent = name;
        el.lookingFor.appendChild(pill);
    });
}

function renderCarousel() {
    if (!el.carousel) return;
    const c = currentCandidate();
    const photos = c ? c.photos : [];
    const inner = el.carousel.querySelector('.carousel-inner');
    const indicators = el.carousel.querySelector('.carousel-indicators');

    if (typeof bootstrap !== 'undefined') {
        const existing = bootstrap.Carousel.getInstance(el.carousel);
        if (existing) existing.dispose();
    }

    inner.innerHTML = '';
    indicators.innerHTML = '';

    if (photos.length === 0) {
        el.carousel.style.display = 'none';
        el.carouselEmpty.style.display = '';
        return;
    }
    el.carousel.style.display = '';
    el.carouselEmpty.style.display = 'none';

    photos.forEach((p, i) => {
        const item = document.createElement('div');
        item.className = 'carousel-item' + (i === 0 ? ' active' : '');
        const img = document.createElement('img');
        img.src = p.photo_url;
        img.className = 'd-block w-100 swipe-photo-img';
        img.alt = '';
        item.appendChild(img);
        inner.appendChild(item);

        const indicator = document.createElement('button');
        indicator.type = 'button';
        indicator.dataset.bsTarget = '#swipePhotoCarousel';
        indicator.dataset.bsSlideTo = String(i);
        indicator.setAttribute('aria-label', 'Slide ' + (i + 1));
        if (i === 0) {
            indicator.className = 'active';
            indicator.setAttribute('aria-current', 'true');
        }
        indicators.appendChild(indicator);
    });
}

let isSwipeInFlight = false;

function swipeAction(type) {
    if (isSwipeInFlight) return;
    const c = currentCandidate();
    if (!c) return;

    isSwipeInFlight = true;
    const skipBtn = document.querySelector('.swipe-btn--skip');
    const matchBtn = document.querySelector('.swipe-btn--match');
    if (skipBtn) skipBtn.disabled = true;
    if (matchBtn) matchBtn.disabled = true;

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
        .catch(err => alert('Error: ' + err.message))
        .finally(() => {
            isSwipeInFlight = false;
            if (skipBtn) skipBtn.disabled = false;
            if (matchBtn) matchBtn.disabled = false;
        });
}

document.querySelector('.swipe-btn--skip').addEventListener('click', () => swipeAction('dislike'));
document.querySelector('.swipe-btn--match').addEventListener('click', () => swipeAction('like'));

swipeRender();

// --- Carousel state ---
// Initialize photos from DB data (set in profile.php)
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

// --- Bio/Location Modal ---
function openBioModal() {
    const bioText = document.getElementById('profileBio').textContent;
    const locEl = document.getElementById('profileLocation');
    const locText = locEl.textContent.replace('📍', '').trim();

    document.getElementById('editBio').value = bioText;
    document.getElementById('editLocation').value = locText;
    document.getElementById('bioCharCount').textContent = bioText.length;

    new bootstrap.Modal(document.getElementById('bioModal')).show();
}

document.getElementById('editBio')?.addEventListener('input', function() {
    document.getElementById('bioCharCount').textContent = this.value.length;
});

function saveBio() {
    const newBio = document.getElementById('editBio').value.trim();
    const newGender = document.getElementById('editGender').value;

    const formData = new FormData();
    formData.append('action', 'update_bio');
    formData.append('bio', newBio);
    formData.append('gender', newGender);

    fetch(window.location.pathname, { method: 'POST', body: formData })
        .then(res => res.text().then(text => {
            if (!res.ok) throw new Error('Server ' + res.status + ': ' + text.substring(0, 200));
            try { return JSON.parse(text); } catch { throw new Error('Invalid response: ' + text.substring(0, 200)); }
        }))
        .then(data => {
            if (data.success) {
                document.getElementById('profileBio').textContent = newBio;
                bootstrap.Modal.getInstance(document.getElementById('bioModal')).hide();
            } else {
                alert('Failed to save: ' + (data.error || 'Unknown error'));
            }
        })
        .catch(err => alert('Error: ' + err.message));
}

// --- Profile Picture Modal ---
let selectedPicFile = null;
let currentPrimaryId = (typeof primaryPhotoId !== 'undefined') ? primaryPhotoId : null;

function openPicModal() {
    const avatar = document.querySelector('.profile-avatar');
    const preview = document.getElementById('picPreview');
    const previewEmpty = document.getElementById('picPreviewEmpty');

    if (avatar.src && avatar.style.display !== 'none') {
        preview.src = avatar.src;
        preview.style.display = '';
        previewEmpty.style.display = 'none';
    } else {
        preview.style.display = 'none';
        previewEmpty.style.display = '';
    }

    selectedPicFile = null;
    document.getElementById('picFileInput').value = '';
    // Only show Remove button if there's a current profile picture
    document.getElementById('removePicBtn').style.display = currentPrimaryId ? '' : 'none';
    new bootstrap.Modal(document.getElementById('picModal')).show();
}

document.getElementById('picFileInput')?.addEventListener('change', function(e) {
    const file = e.target.files[0];
    if (!file) return;
    if (file.size > 2 * 1024 * 1024) {
        alert('File must be under 2MB');
        return;
    }
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

    const formData = new FormData();
    formData.append('action', 'upload_photo');
    formData.append('photo', selectedPicFile);
    formData.append('is_primary', '1');

    fetch(window.location.pathname, { method: 'POST', body: formData })
        .then(res => res.text().then(text => {
            if (!res.ok) throw new Error('Server ' + res.status + ': ' + text.substring(0, 200));
            try { return JSON.parse(text); } catch { throw new Error('Invalid response: ' + text.substring(0, 200)); }
        }))
        .then(data => {
            if (data.success) {
                const avatar = document.querySelector('.profile-avatar');
                avatar.src = data.photo_url;
                avatar.style.display = '';
                document.getElementById('avatarEmpty').style.display = 'none';
                currentPrimaryId = data.photo_id;
                bootstrap.Modal.getInstance(document.getElementById('picModal')).hide();
            } else {
                alert('Upload failed: ' + (data.error || 'Unknown error'));
            }
        })
        .catch(err => alert('Error: ' + err.message));
}

function removeProfilePic() {
    if (!currentPrimaryId) return;

    const formData = new FormData();
    formData.append('action', 'delete_photo');
    formData.append('photo_id', currentPrimaryId);

    fetch(window.location.pathname, { method: 'POST', body: formData })
        .then(res => res.text().then(text => {
            if (!res.ok) throw new Error('Server ' + res.status + ': ' + text.substring(0, 200));
            try { return JSON.parse(text); } catch { throw new Error('Invalid response: ' + text.substring(0, 200)); }
        }))
        .then(data => {
            if (data.success) {
                const avatar = document.querySelector('.profile-avatar');
                avatar.style.display = 'none';
                document.getElementById('avatarEmpty').style.display = '';
                currentPrimaryId = null;
                bootstrap.Modal.getInstance(document.getElementById('picModal')).hide();
            } else {
                alert('Remove failed: ' + (data.error || 'Unknown error'));
            }
        })
        .catch(err => alert('Error: ' + err.message));
}

// --- Carousel Photos Modal ---
function openPhotosModal() {
    renderPhotosGrid();
    new bootstrap.Modal(document.getElementById('photosModal')).show();
}

function renderPhotosGrid() {
    const grid = document.getElementById('photosGrid');
    grid.innerHTML = photos.map((photo, i) => `
        <div class="profile-photo-thumb">
            <img src="${photo.url}" alt="Photo ${i + 1}">
            <button class="profile-photo-delete" onclick="deletePhoto(${i})">&times;</button>
        </div>
    `).join('');

    // Hide add button if at max
    document.getElementById('addPhotoBtn').style.display = photos.length >= 6 ? 'none' : '';
}

document.getElementById('photoFileInput')?.addEventListener('change', function(e) {
    const file = e.target.files[0];
    if (!file) return;
    if (file.size > 2 * 1024 * 1024) {
        alert('File must be under 2MB');
        return;
    }
    if (photos.length >= 6) {
        alert('Maximum 6 photos allowed');
        return;
    }

    const formData = new FormData();
    formData.append('action', 'upload_photo');
    formData.append('photo', file);
    formData.append('is_primary', '0');

    fetch(window.location.pathname, { method: 'POST', body: formData })
        .then(res => res.text().then(text => {
            if (!res.ok) throw new Error('Server ' + res.status + ': ' + text.substring(0, 200));
            try { return JSON.parse(text); } catch { throw new Error('Invalid response: ' + text.substring(0, 200)); }
        }))
        .then(data => {
            if (data.success) {
                photos.push({ id: data.photo_id, url: data.photo_url });
                renderPhotosGrid();
                updateCarousel();
            } else {
                alert('Upload failed: ' + (data.error || 'Unknown error'));
            }
        })
        .catch(err => alert('Error: ' + err.message));

    this.value = '';
});

function deletePhoto(index) {
    const photo = photos[index];

    const formData = new FormData();
    formData.append('action', 'delete_photo');
    formData.append('photo_id', photo.id);

    fetch(window.location.pathname, { method: 'POST', body: formData })
        .then(res => res.text().then(text => {
            if (!res.ok) throw new Error('Server ' + res.status + ': ' + text.substring(0, 200));
            try { return JSON.parse(text); } catch { throw new Error('Invalid response: ' + text.substring(0, 200)); }
        }))
        .then(data => {
            if (data.success) {
                photos.splice(index, 1);
                if (currentIndex >= photos.length) currentIndex = Math.max(0, photos.length - 1);
                renderPhotosGrid();
                updateCarousel();
            } else {
                alert('Delete failed: ' + (data.error || 'Unknown error'));
            }
        })
        .catch(err => alert('Error: ' + err.message));
}

// --- Tag Picker ---
let currentTagType = '';
let selectedAboutMe = [...aboutMeTagIds];
let selectedLookingFor = [...lookingForTagIds];

function openTagPickerModal(selectionType) {
    currentTagType = selectionType;
    const grid = document.getElementById('tagPickerGrid');
    const title = document.getElementById('tagPickerModalLabel');
    title.textContent = selectionType === 'about_me' ? 'About Me' : 'Looking For';

    const currentIds = selectionType === 'about_me' ? selectedAboutMe : selectedLookingFor;

    let colorIndex = 0;
    grid.innerHTML = allTags.map(tag => {
        const isSelected = currentIds.includes(parseInt(tag.tag_id));
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
    const currentIds = currentTagType === 'about_me' ? selectedAboutMe : selectedLookingFor;
    const idx = currentIds.indexOf(tagId);

    if (idx > -1) {
        currentIds.splice(idx, 1);
        el.className = 'profile-pill profile-pill--unselected';
    } else {
        currentIds.push(tagId);
        el.className = 'profile-pill profile-pill--' + color;
    }
}

function saveTags() {
    const currentIds = currentTagType === 'about_me' ? selectedAboutMe : selectedLookingFor;
    const formData = new FormData();
    formData.append('action', 'update_tags');
    formData.append('tag_ids', JSON.stringify(currentIds));
    formData.append('selection_type', currentTagType);

    fetch(window.location.pathname, { method: 'POST', body: formData })
        .then(res => res.text().then(text => {
            if (!res.ok) throw new Error('Server ' + res.status + ': ' + text.substring(0, 200));
            try { return JSON.parse(text); } catch { throw new Error('Invalid response: ' + text.substring(0, 200)); }
        }))
        .then(data => {
            if (data.success) {
                updateProfilePills();
                bootstrap.Modal.getInstance(document.getElementById('tagPickerModal')).hide();
            } else {
                alert('Failed to save: ' + (data.error || 'Unknown error'));
            }
        })
        .catch(err => alert('Error: ' + err.message));
}

function updateProfilePills() {
    // Update About Me pills
    const aboutMe = document.getElementById('aboutMePills');
    const aboutBtn = aboutMe.querySelector('.profile-add-tag');
    const title = aboutMe.querySelector('.profile-tags-title');
    aboutMe.innerHTML = '';
    aboutMe.appendChild(title);
    allTags.filter(t => selectedAboutMe.includes(parseInt(t.tag_id)))
        .forEach((tag, i) => {
            const pill = document.createElement('span');
            pill.className = 'profile-pill profile-pill--' + (i % 2 === 0 ? 'pink' : 'orange');
            pill.textContent = tag.tag_name;
            aboutMe.appendChild(pill);
        });
    aboutMe.appendChild(aboutBtn);

    // Looking For pills are read-only on the profile page — edited in Settings.
}

// --- Map Location Picker ---
let map = null;
let mapMarker = null;
let selectedLat = null;
let selectedLng = null;

function openMapModal() {
    const bioModalEl = document.getElementById('bioModal');
    const bioModalInstance = bootstrap.Modal.getInstance(bioModalEl);
    const mapModalEl = document.getElementById('mapModal');

    function showMap() {
        const mapModal = new bootstrap.Modal(mapModalEl);
        mapModal.show();
    }

    // Close bio modal first, then open map after it's hidden
    if (bioModalInstance) {
        bioModalEl.addEventListener('hidden.bs.modal', function onHidden() {
            bioModalEl.removeEventListener('hidden.bs.modal', onHidden);
            showMap();
        });
        bioModalInstance.hide();
    } else {
        showMap();
    }

    // Initialize map after map modal is fully shown (Leaflet needs visible container)
    mapModalEl.addEventListener('shown.bs.modal', function initMap() {
        if (!map) {
            // Default centre: Ireland. Use existing coords if available.
            const existingLat = parseFloat(document.getElementById('editLat').value);
            const existingLng = parseFloat(document.getElementById('editLng').value);
            const startLat = existingLat || 52.8;
            const startLng = existingLng || -7.5;
            const startZoom = existingLat ? 12 : 7;

            map = L.map('map').setView([startLat, startLng], startZoom);
            L.tileLayer('https://{s}.tile.openstreetmap.org/{z}/{x}/{y}.png', {
                attribution: '&copy; OpenStreetMap contributors'
            }).addTo(map);

            // If user already has a location, show marker
            if (existingLat && existingLng) {
                mapMarker = L.marker([existingLat, existingLng]).addTo(map);
                selectedLat = existingLat;
                selectedLng = existingLng;
                document.getElementById('confirmLocationBtn').disabled = false;
            }

            // Click handler — place/move marker
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

// Render carousel on page load if photos exist
updateCarousel();

function confirmLocation() {
    if (!selectedLat || !selectedLng) return;

    const btn = document.getElementById('confirmLocationBtn');
    btn.disabled = true;
    btn.textContent = 'Saving...';

    // Send lat/lng to backend
    const formData = new FormData();
    formData.append('action', 'update_location');
    formData.append('lat', selectedLat);
    formData.append('lng', selectedLng);

    fetch(window.location.pathname, {
        method: 'POST',
        body: formData
    })
    .then(res => res.text().then(text => {
        if (!res.ok) throw new Error('Server ' + res.status + ': ' + text.substring(0, 200));
        try { return JSON.parse(text); } catch { throw new Error('Invalid response: ' + text.substring(0, 200)); }
    }))
    .then(data => {
        if (data.success) {
            // Update the bio modal location field
            document.getElementById('editLocation').value = data.general_location;
            document.getElementById('editLat').value = selectedLat;
            document.getElementById('editLng').value = selectedLng;

            // Update the profile page location display
            document.getElementById('profileLocation').innerHTML =
                '<span class="profile-location-icon">📍</span> ' + data.general_location;

            // Close map modal, reopen bio modal
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
    .catch(err => {
        alert('Error: ' + err.message);
    })
    .finally(() => {
        btn.disabled = false;
        btn.textContent = 'Confirm Location';
    });
}

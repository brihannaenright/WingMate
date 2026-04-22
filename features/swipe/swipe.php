<?php
require_once __DIR__ . '/../../includes/session.php';
require_once __DIR__ . '/../../config/config.php';

wingmate_start_secure_session();

$current_user_id = $_SESSION['user_id'] ?? null;
if (!$current_user_id) {
    header('Location: /features/auth/login.php');
    exit;
}
?>

<?php include __DIR__ . '/../../includes/nav-header.php'; ?>

<link rel="stylesheet" href="swipe.css">

<div class="swipe-page">
    <div class="swipe-container">

        <!-- 1. Banner (Top Left) -->
        <div class="swipe-banner">
            <div class="swipe-banner-bg"></div>
            <div class="swipe-avatar-wrapper">
                <img src="" alt="Profile photo" class="swipe-avatar">
            </div>
            <div class="swipe-info-card">
                <h1 class="swipe-name-age"></h1>
                <div class="swipe-location">
                    <span class="swipe-location-icon"></span>
                </div>
                <p class="swipe-bio"></p>
            </div>
        </div>

        <!-- 2. Photo Carousel (Top Right) -->
        <div class="swipe-photos-card">
            <div class="swipe-photo-empty"></div>
            <div class="swipe-photo-dots"></div>
            <div class="swipe-photo-nav">
                <button class="swipe-photo-arrow">&larr;</button>
                <button class="swipe-photo-arrow">&rarr;</button>
            </div>
        </div>

        <!-- 3. Tags + Friends (Bottom Left) -->
        <div class="swipe-tags-card">
            <div class="swipe-tags-sidebar">
                <h4 class="swipe-tags-title">About Me</h4>
            </div>
            <div class="swipe-friends-card">
                <h3 class="swipe-friends-title">What My Friends Say About Me</h3>
            </div>
        </div>

        <!-- 4. Looking For (Bottom Right) -->
        <div class="swipe-looking-card">
            <h3 class="swipe-looking-title">Looking For</h3>
            <div class="swipe-looking-pills"></div>
        </div>

        <!-- 5. Skip / Match Buttons -->
        <div class="swipe-actions">
            <button class="swipe-btn swipe-btn--skip">Skip</button>
            <button class="swipe-btn swipe-btn--match">Match</button>
        </div>

    </div>
</div>

<?php include __DIR__ . '/../../includes/footer.php'; ?>

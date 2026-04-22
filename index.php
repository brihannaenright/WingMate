<?php
declare(strict_types=1);
?>

<?php include __DIR__ . '/includes/auth-header.php';; ?>
<div class="entry-page d-flex flex-column">
    <nav class="navbar navbar-expand-lg navbar-light wingmate-navbar sticky-top">
        <div class="container-fluid">
            <!-- Logo on the left -->
            <div class="navbar-brand">
                <img src="../../assets/images/wingmate-navbar.png" alt="WingMate" class="navbar-logo" style="height: 50px; width: auto;">
            </div>
        </div>

        <button class="navbar-toggler" type="button" data-bs-toggle="collapse" data-bs-target="#navbarNav">
            <span class="navbar-toggler-icon"></span>
        </button>

        <div class="collapse navbar-collapse" id="navbarNav">
            <ul class="navbar-nav ms-auto">
                <li class="nav-item">
                    <a class="nav-link <?php echo strpos($_SERVER['REQUEST_URI'], '/features/auth/register.php') !== false ? 'active' : ''; ?>" href="/features/auth/register.php">Sign-Up</a>
                </li>
                <li class="nav-item">
                    <a class="nav-link <?php echo strpos($_SERVER['REQUEST_URI'], '/features/auth/login.php') !== false ? 'active' : ''; ?>" href="/features/auth/login.php">Login</a>
                </li>
            </ul>
        </div>
    </nav>

    <div class="entry-container d-flex flex-column align-items-center justify-content-center flex-grow-1">
        <div class="entry-welcome d-flex flex-column align-items-center justify-content-center text-center">
            <h1 class="entry-title">Welcome to WingMate</h1>
            <p class="entry-description">Dating made fun! Let your friends decide who you date. <br>
                Because who knows you better than your friends?
            </p>
        </div>

        <div class="entry-options gap-3 d-flex flex-row">
            <button type="button" class="floating-button button-secondary" onclick="window.location.href='/features/auth/register.php'">Sign-Up</button>
            <button type="button" class="floating-button button-secondary" onclick="window.location.href='/features/auth/login.php'">Log in</button>
        </div>
    </div>
</div>
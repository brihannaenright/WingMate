<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>WingMate</title>

    <!-- Bootstrap CSS -->
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.8/dist/css/bootstrap.min.css" rel="stylesheet"
    integrity="sha384-sRIl4kxILFvY47J16cr9ZwB07vP4J8+LH7qKQnuqkuIAvNWLzeN8tE5YBujZqJLB" crossorigin="anonymous">

    <!-- Global CSS -->
    <link rel="stylesheet" href="/assets/global.css">
</head>

<body>

<nav class="navbar navbar-expand-lg navbar-light wingmate-navbar">
    <div class="container-fluid">
        <!-- Logo on the left -->
        <div class="navbar-brand">
            <img src="../../assets/images/wingmate-navbar.png" alt="WingMate" class="navbar-logo" style="height: 50px; width: auto;">
        </div>
        
        <!-- Mobile toggle button -->
        <button class="navbar-toggler" type="button" data-bs-toggle="collapse" data-bs-target="#navbarNav"
                aria-controls="navbarNav" aria-expanded="false" aria-label="Toggle navigation">
            <span class="navbar-toggler-icon"></span>
        </button>
        
        <!-- Links on the right -->
        <div class="collapse navbar-collapse" id="navbarNav">
            <ul class="navbar-nav ms-auto">
                <li class="nav-item">
                    <a class="nav-link <?php echo strpos($_SERVER['REQUEST_URI'], '/features/profile/profile.php') !== false ? 'active' : ''; ?>" href="/features/profile/profile.php">Profile</a>
                </li>
                <li class="nav-item">
                    <a class="nav-link <?php echo strpos($_SERVER['REQUEST_URI'], '/features/swipe/swipe.php') !== false ? 'active' : ''; ?>" href="/features/swipe/swipe.php">Swipe</a>
                </li>
                <li class="nav-item">
                    <a class="nav-link <?php echo strpos($_SERVER['REQUEST_URI'], '/features/matches/matches.php') !== false ? 'active' : ''; ?>" href="/features/matches/matches.php">Matches</a>
                </li>
                <li class="nav-item">
                    <a class="nav-link <?php echo strpos($_SERVER['REQUEST_URI'], '/features/friends/friends.php') !== false ? 'active' : ''; ?>" href="/features/friends/friends.php">Friends</a>
                </li>
                <li class="nav-item">
                    <a class="nav-link <?php echo strpos($_SERVER['REQUEST_URI'], '/features/notifications/notifications.php') !== false ? 'active' : ''; ?>" href="/features/notifications/notifications.php">
                        Notifications
                        <span class="notification-badge hidden" id="notificationBadge"></span>
                    </a>
                </li>
                <li class="nav-item">
                    <a class="nav-link" href="#">Vote</a>
                </li>
                <li class="nav-item dropdown">
                    <a class="nav-link dropdown-toggle <?php echo strpos($_SERVER['REQUEST_URI'], '/features/settings/settings.php') !== false ? 'active' : ''; ?>"
                       href="#" id="settingsDropdown" role="button" data-bs-toggle="dropdown" aria-expanded="false">
                        Settings
                    </a>
                    <ul class="dropdown-menu dropdown-menu-end" aria-labelledby="settingsDropdown">
                        <li><a class="dropdown-item" href="/features/settings/settings.php">Account Settings</a></li>
                        <li><a class="dropdown-item" href="/features/auth/logout.php">Logout</a></li>
                    </ul>
                </li>
            </ul>
        </div>
    </div>
</nav>

<script>
    // Smooth page transitions - no extra requests, just visual feedback
    document.querySelectorAll('.nav-link:not(.dropdown-toggle), .dropdown-item').forEach(link => {
        link.addEventListener('click', function(e) {
            const href = this.getAttribute('href');

            // Only apply transition for internal navigation links that will cause reload
            if (href && href !== '#' && !href.startsWith('javascript:')) {
                document.body.classList.add('page-loading');
            }
        });
    });

    // Auto-collapse mobile dropdown after selection
    const navbarCollapse = document.getElementById('navbarNav');
    document.querySelectorAll('.nav-link:not(.dropdown-toggle), .dropdown-item').forEach(link => {
        link.addEventListener('click', () => {
            const bsCollapse = new bootstrap.Collapse(navbarCollapse, { toggle: false });
            if (navbarCollapse.classList.contains('show')) {
                bsCollapse.hide();
            }
        });
    });

    // Load unread notification count
    function updateNotificationBadge() {
        fetch('/features/notifications/notifications-api.php')
            .then(response => response.json())
            .then(data => {
                const badge = document.getElementById('notificationBadge');
                if (data.unread_count > 0) {
                    badge.textContent = data.unread_count > 9 ? '9+' : data.unread_count;
                    badge.classList.remove('hidden');
                } else {
                    badge.classList.add('hidden');
                }
            })
            .catch(error => console.error('Error fetching notifications:', error));
    }

    // Update notif badge every 10 seconds
    document.addEventListener('DOMContentLoaded', function() {
        updateNotificationBadge();
        setInterval(updateNotificationBadge, 10000);
    });
</script>
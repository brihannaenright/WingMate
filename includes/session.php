<?php
declare(strict_types=1);

function wingmate_start_secure_session(): void
{
    if (session_status() === PHP_SESSION_ACTIVE) {
        return;
    }

    $isHttps = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off')
        || (isset($_SERVER['SERVER_PORT']) && (int) $_SERVER['SERVER_PORT'] === 443);

    ini_set('session.use_strict_mode', '1'); //rejects fake session IDs
    ini_set('session.use_only_cookies', '1'); //ensures session ID comes from cookie
    ini_set('session.cookie_httponly', '1'); //prevents JavaScript access to session cookie
    ini_set('session.cookie_secure', $isHttps ? '1' : '0'); //ensures cookie is sent over HTTPS only
    ini_set('session.cookie_samesite', 'Lax'); //prevents CSRF attacks

    session_name('WINGMATESESSID');
    session_set_cookie_params([
        'lifetime' => 0,
        'path' => '/',
        'domain' => '',
        'secure' => $isHttps,
        'httponly' => true,
        'samesite' => 'Lax',
    ]);

    session_start();

    if (!isset($_SESSION['created_at'])) {
        $_SESSION['created_at'] = time();
    }

    //changes the session ID every 15 minutes
    if (!isset($_SESSION['last_regenerated_at']) || time() - (int) $_SESSION['last_regenerated_at'] > 900) {
        session_regenerate_id(true);
        $_SESSION['last_regenerated_at'] = time();
    }
}

function wingmate_get_csrf_token(): string
{
    if (empty($_SESSION['csrf_token'])) {
        $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
    }

    return (string) $_SESSION['csrf_token'];
}

function wingmate_validate_csrf_token(?string $submittedToken): bool
{
    if (!is_string($submittedToken) || empty($_SESSION['csrf_token'])) {
        return false;
    }

    $isValid = hash_equals((string) $_SESSION['csrf_token'], $submittedToken);

    if ($isValid) {
        // Change CSRF token after successful use
        $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
    }

    return $isValid;
}

function wingmate_destroy_session(): void
{
    if (session_status() !== PHP_SESSION_ACTIVE) {
        return;
    }

    $_SESSION = [];

    if (ini_get('session.use_cookies')) {
        $params = session_get_cookie_params();
        setcookie(session_name(), '', [
            'expires' => time() - 42000,
            'path' => $params['path'],
            'domain' => $params['domain'],
            'secure' => (bool) $params['secure'],
            'httponly' => (bool) $params['httponly'],
            'samesite' => $params['samesite'] ?? 'Lax',
        ]);
    }

    session_destroy();
}
<?php
/**
 * Authentication helpers.
 * Viewing is public; editing requires a shared password. A successful login
 * sets a server-side session flag, so the password travels over the wire only
 * once (at login), not on every save.
 */
declare(strict_types=1);

function svdp_start_session(): void
{
    if (session_status() === PHP_SESSION_ACTIVE) {
        return;
    }
    $secure = (bool)(svdp_config()['secure_cookies'] ?? false);
    session_set_cookie_params([
        'lifetime' => 0,          // session cookie
        'path'     => '/',
        'httponly' => true,       // not readable by JavaScript
        'secure'   => $secure,    // HTTPS-only once you enable it in config
        'samesite' => 'Lax',
    ]);
    session_name('svdp_session');
    session_start();
}

function svdp_can_edit(): bool
{
    svdp_start_session();
    return !empty($_SESSION['can_edit']);
}

/**
 * Verify the submitted password against the stored hash and, on success,
 * mark this session as allowed to edit.
 */
function svdp_login(string $password): bool
{
    $hash = svdp_config()['edit_password_hash'] ?? '';
    if ($hash === '' || !password_verify($password, $hash)) {
        return false;
    }
    svdp_start_session();
    session_regenerate_id(true);   // prevent session fixation
    $_SESSION['can_edit'] = true;
    return true;
}

function svdp_logout(): void
{
    svdp_start_session();
    $_SESSION = [];
    if (ini_get('session.use_cookies')) {
        $p = session_get_cookie_params();
        setcookie(session_name(), '', time() - 42000,
            $p['path'], $p['domain'], $p['secure'], $p['httponly']);
    }
    session_destroy();
}

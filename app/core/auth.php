<?php
declare(strict_types=1);

/** Where each kind of person lands after signing in. */
function home_path(string $role): string
{
    return match ($role) {
        'admin'   => '/admin/index.php',
        'teacher' => '/teacher/index.php',
        default   => '/student/index.php',
    };
}

/** The signed-in person (read fresh from the database on every request), or null. */
function current_user(): ?array
{
    static $loaded = false;
    static $user = null;
    if ($loaded) {
        return $user;
    }
    $loaded = true;

    $id = $_SESSION['user_id'] ?? null;
    if (!is_int($id)) {
        return null;
    }
    // Re-checking the database means a deactivated account is locked out immediately.
    $user = AuthService::findActiveById($id);
    if ($user === null) {
        unset($_SESSION['user_id']);
    }
    return $user;
}

/** Call right after a correct password. A fresh session id stops "session fixation" attacks. */
function login_user(array $user): void
{
    session_regenerate_id(true);
    $_SESSION['user_id'] = (int) $user['id'];
    $_SESSION['last_activity'] = time();
    unset($_SESSION['csrf'], $_SESSION['signed_out_idle']);
}

function logout_user(): void
{
    $_SESSION = [];
    if (ini_get('session.use_cookies')) {
        $p = session_get_cookie_params();
        setcookie(session_name(), '', [
            'expires'  => time() - 42000,
            'path'     => $p['path'],
            'domain'   => $p['domain'],
            'secure'   => $p['secure'],
            'httponly' => $p['httponly'],
            'samesite' => 'Lax',
        ]);
    }
    session_destroy();
}

/** Every protected page starts with this (or require_role). */
function require_login(): array
{
    $user = current_user();
    if ($user === null) {
        redirect('/login.php');
    }
    if ((int) $user['must_change_password'] === 1 && basename((string) ($_SERVER['SCRIPT_NAME'] ?? '')) !== 'change_password.php') {
        redirect('/change_password.php');   // built on Day 5
    }
    return $user;
}

/**
 * Only the listed roles may see this page. Anyone else gets "not found",
 * so they cannot even tell the page exists.
 */
function require_role(string ...$roles): array
{
    $user = require_login();
    if (!in_array($user['role'], $roles, true)) {
        abort_404();
    }
    return $user;
}

<?php
declare(strict_types=1);

/**
 * Sessions: how the site remembers who you are between page loads.
 * Guide section 7: HttpOnly, SameSite=Lax, strict mode, Secure on HTTPS, 30-minute inactivity expiry.
 */
function session_boot(): void
{
    if (session_status() === PHP_SESSION_ACTIVE) {
        return;
    }

    // "Secure" cookies only travel over HTTPS. Your laptop uses plain http, so we allow that only while developing.
    $secure = env('APP_ENV', 'production') !== 'development';

    ini_set('session.use_strict_mode', '1');   // refuse session ids the server did not create
    ini_set('session.use_only_cookies', '1');
    ini_set('session.use_trans_sid', '0');

    session_name('QUIVERSESSID');
    session_set_cookie_params([
        'lifetime' => 0,          // ends when the browser closes
        'path'     => '/',
        'secure'   => $secure,
        'httponly' => true,       // JavaScript cannot read it
        'samesite' => 'Lax',
    ]);
    session_start();

    $idleLimit = (int) env('SESSION_IDLE_SECONDS', '1800');   // 30 minutes
    $now = time();
    if (isset($_SESSION['last_activity']) && ($now - (int) $_SESSION['last_activity']) > $idleLimit) {
        $_SESSION = [];
        session_regenerate_id(true);
        $_SESSION['signed_out_idle'] = true;
    }
    $_SESSION['last_activity'] = $now;
}

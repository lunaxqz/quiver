<?php
declare(strict_types=1);

require_once __DIR__ . '/../app/bootstrap.php';

// Signing out changes state, so it only works as a POST with a valid ticket (never a plain link).
if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') {
    redirect('/login.php');
}
csrf_verify();
logout_user();
redirect('/login.php');

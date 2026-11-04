<?php
declare(strict_types=1);

require_once __DIR__ . '/../app/bootstrap.php';

// The front door: send each person to the right place.
$user = current_user();
redirect($user ? home_path($user['role']) : '/login.php');

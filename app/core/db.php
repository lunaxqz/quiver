<?php
declare(strict_types=1);

/**
 * Database connection (PDO). Safe settings from guide section 7:
 *  - errors become exceptions, so failures are never silent
 *  - real prepared statements, so user input can never become SQL
 *  - the database clock is set to UTC
 */

function db_connect(?string $user, ?string $pass): PDO
{
    $dsn = sprintf(
        'mysql:host=%s;port=%s;dbname=%s;charset=utf8mb4',
        env('DB_HOST', '127.0.0.1'),
        env('DB_PORT', '3306'),
        env('DB_NAME', 'quiver')
    );
    $pdo = new PDO($dsn, $user, $pass, [
        PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_EMULATE_PREPARES   => false,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
    ]);
    $pdo->exec("SET time_zone = '+00:00'");
    return $pdo;
}

/** The connection the website uses. One per request, created the first time it is needed. */
function db(): PDO
{
    static $pdo = null;
    if ($pdo === null) {
        $pdo = db_connect(env('DB_USER'), env('DB_PASS', ''));
    }
    return $pdo;
}

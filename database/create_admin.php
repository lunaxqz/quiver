<?php
declare(strict_types=1);

/**
 * Creates the first administrator account. Run it once from the terminal:
 *
 *   php database/create_admin.php admin "Your Name"
 *
 * It invents a strong password and shows it ONCE. Copy it somewhere safe.
 */

if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}

require_once __DIR__ . '/../app/bootstrap.php';

$username = $argv[1] ?? '';
$fullName = $argv[2] ?? '';
if ($username === '' || $fullName === '') {
    fwrite(STDERR, "Usage: php database/create_admin.php USERNAME \"Full Name\"\n");
    exit(1);
}
if (!preg_match('/^[A-Za-z0-9._-]{3,30}$/', $username)) {
    fwrite(STDERR, "The username must be 3 to 30 letters, numbers, dots, dashes or underscores.\n");
    exit(1);
}

$password = AuthService::randomPassword();

try {
    AuthService::createUser($username, $fullName, $password, 'admin');
} catch (DuplicateUsernameException $e) {
    fwrite(STDERR, "That username already exists. Pick another.\n");
    exit(1);
}

echo "Administrator created.\n";
echo "  Username: $username\n";
echo "  Password: $password\n";
echo "Copy the password now. It is not stored anywhere in readable form.\n";

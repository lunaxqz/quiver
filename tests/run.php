<?php
declare(strict_types=1);

/**
 * Runs every safety check:   php tests/run.php
 *   1. tests/authz.php   who is allowed to see or change what (uses the development database,
 *                        creates its own throwaway accounts and deletes them afterwards)
 *   2. PHPUnit           the scoring rules (only if Composer has installed it)
 * Exits with 1 if anything fails, so it can gate a commit.
 */

if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}

require_once __DIR__ . '/../app/bootstrap.php';

if (env('APP_ENV') !== 'development') {
    fwrite(STDERR, "Refusing to run: these tests write to the database, so APP_ENV must be 'development'.\n");
    exit(1);
}

$GLOBALS['quiver_pass'] = 0;
$GLOBALS['quiver_fail'] = [];

/** Record one check. */
function check(string $name, bool $ok): void
{
    if ($ok) {
        $GLOBALS['quiver_pass']++;
        echo "  PASS  $name\n";
    } else {
        $GLOBALS['quiver_fail'][] = $name;
        echo "  FAIL  $name\n";
    }
}

echo "Authorization checks\n";
require __DIR__ . '/authz.php';

$failed = count($GLOBALS['quiver_fail']);
echo "\n" . $GLOBALS['quiver_pass'] . ' passed, ' . $failed . " failed.\n";

$phpunit = __DIR__ . '/../vendor/bin/phpunit';
$phpunitCode = 0;
if (is_file($phpunit)) {
    echo "\nScoring rules (PHPUnit)\n";
    passthru('"' . PHP_BINARY . '" ' . escapeshellarg($phpunit) . ' --configuration ' . escapeshellarg(__DIR__ . '/../phpunit.xml'), $phpunitCode);
} else {
    echo "\nPHPUnit is not installed yet (run: composer install). Skipped the scoring tests.\n";
}

exit($failed > 0 || $phpunitCode !== 0 ? 1 : 0);

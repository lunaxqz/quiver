<?php
declare(strict_types=1);

/**
 * Builds (or updates) the database tables from the files in database/migrations.
 *
 *   php database/migrate.php            builds any tables that are missing
 *   php database/migrate.php --fresh    ERASES every table, then rebuilds (development only)
 *
 * Runs from the terminal only. Uses the MIGRATE_USER account from .env, not the website account.
 */

if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}

require_once __DIR__ . '/../app/core/env.php';
require_once __DIR__ . '/../app/core/db.php';

env_load(__DIR__ . '/../.env');

$pdo = db_connect(env('MIGRATE_USER', 'root'), env('MIGRATE_PASS', ''));

if (in_array('--fresh', $argv, true)) {
    if (env('APP_ENV') !== 'development') {
        fwrite(STDERR, "Refusing: --fresh only runs when APP_ENV=development.\n");
        exit(1);
    }
    echo "This will ERASE every table in the '" . env('DB_NAME', 'quiver') . "' database. Type yes to continue: ";
    if (trim((string) fgets(STDIN)) !== 'yes') {
        echo "Cancelled. Nothing was changed.\n";
        exit(0);
    }
    $pdo->exec('SET FOREIGN_KEY_CHECKS = 0');
    foreach ($pdo->query('SHOW TABLES')->fetchAll(PDO::FETCH_COLUMN) as $table) {
        $pdo->exec('DROP TABLE `' . str_replace('`', '', (string) $table) . '`');
    }
    $pdo->exec('SET FOREIGN_KEY_CHECKS = 1');
    echo "All tables erased.\n";
}

$pdo->exec('CREATE TABLE IF NOT EXISTS schema_migrations (
    filename   VARCHAR(190) NOT NULL PRIMARY KEY,
    applied_at DATETIME NOT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci');

$applied = $pdo->query('SELECT filename FROM schema_migrations')->fetchAll(PDO::FETCH_COLUMN);
$files = glob(__DIR__ . '/migrations/*.sql');
sort($files);

$ran = 0;
foreach ($files as $file) {
    $name = basename($file);
    if (in_array($name, $applied, true)) {
        continue;
    }
    try {
        $pdo->exec((string) file_get_contents($file));
    } catch (PDOException $e) {
        fwrite(STDERR, "FAILED on $name\n" . $e->getMessage() . "\n");
        exit(1);
    }
    $stmt = $pdo->prepare('INSERT INTO schema_migrations (filename, applied_at) VALUES (?, ?)');
    $stmt->execute([$name, gmdate('Y-m-d H:i:s')]);
    echo "  built  $name\n";
    $ran++;
}

echo $ran === 0 ? "Nothing to do. The database is up to date.\n" : "Done. Built $ran file(s).\n";

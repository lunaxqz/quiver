<?php
declare(strict_types=1);

require_once __DIR__ . '/../app/bootstrap.php';
require_once __DIR__ . '/../app/helpers/layout.php';

// Development-only page: it does not exist on a live site.
if (env('APP_ENV') !== 'development') {
    http_response_code(404);
    exit;
}

teacher_start('Database check', 'overview');

try {
    $pdo = db();

    $dbUtc  = (string) $pdo->query('SELECT UTC_TIMESTAMP()')->fetchColumn();
    $tables = $pdo->query(
        'SELECT table_name FROM information_schema.tables WHERE table_schema = DATABASE() ORDER BY table_name'
    )->fetchAll(PDO::FETCH_COLUMN);
    $charset = $pdo->query('SELECT @@character_set_database AS cs, @@collation_database AS co')->fetch();
    $grants  = array_filter(
        $pdo->query('SHOW GRANTS')->fetchAll(PDO::FETCH_COLUMN),
        fn($g) => !str_starts_with((string) $g, 'GRANT USAGE')   // that line contains a password hash
    );

    // The website account must NOT be able to create or drop tables.
    $locked = false;
    try {
        $pdo->exec('CREATE TABLE _probe (id INT)');
        $pdo->exec('DROP TABLE _probe');
    } catch (PDOException $e) {
        $locked = true;
    }
    ?>
    <p><?= status_mark('complete', 'Connected') ?></p>

    <h2 style="margin:var(--s5) 0 var(--s2)">Time</h2>
    <p>Database clock (UTC): <strong><?= e($dbUtc) ?></strong><br>
       PHP clock (UTC): <strong><?= e(utc_now()) ?></strong><br>
       Shown in Manila time: <strong><?= e(to_manila(utc_now())) ?></strong></p>

    <h2 style="margin:var(--s5) 0 var(--s2)">Tables (<?= count($tables) ?>)</h2>
    <p>You should see 16: the 15 QUIVER tables plus <code>schema_migrations</code>.</p>
    <p><?= e(implode(', ', $tables)) ?></p>

    <h2 style="margin:var(--s5) 0 var(--s2)">Text encoding</h2>
    <p><?= e($charset['cs']) ?> / <?= e($charset['co']) ?> (should be utf8mb4)</p>

    <h2 style="margin:var(--s5) 0 var(--s2)">Website account rights</h2>
    <p><?= $locked
        ? status_mark('complete', 'Cannot create or drop tables (correct)')
        : status_mark('problem', 'Can create tables: the website account has too many rights') ?></p>
    <p style="color:var(--ink-2); font-size:var(--t-sm)"><?= e(implode(' | ', $grants)) ?></p>
    <?php
} catch (Throwable $t) {
    echo state_error('Could not connect to the database. Is MySQL started in XAMPP, and are the DB_ values in .env correct? Details: ' . $t->getMessage(), '/dbtest.php');
}

teacher_end();

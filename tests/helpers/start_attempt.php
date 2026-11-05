<?php
declare(strict_types=1);

/**
 * Helper for tests/attempts.php: starts an attempt in its own PHP process and prints "result attempt_id".
 * Two of these run at the same moment to imitate two browser tabs pressing Begin.
 *   php tests/helpers/start_attempt.php <student_id> <quiz_id>
 */

if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}

require_once __DIR__ . '/../../app/bootstrap.php';

$r = AttemptService::start((int) ($argv[1] ?? 0), (int) ($argv[2] ?? 0));
echo $r['result'] . ' ' . ($r['attempt_id'] ?? 0) . "\n";

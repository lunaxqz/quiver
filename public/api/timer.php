<?php
declare(strict_types=1);

/**
 * Returns the server's authoritative remaining_seconds for this student's attempt.
 * Used by attempt.js to resync its monotonic countdown on visibility/focus and on every response.
 */

require_once __DIR__ . '/../../app/bootstrap.php';

$user = current_user();
if ($user === null || ($user['role'] ?? '') !== 'student') {
    json_out(['error' => 'Not signed in'], 401);
}

$attemptId = (int) ($_GET['attempt_id'] ?? $_POST['attempt_id'] ?? 0);
$stmt = db()->prepare(
    'SELECT id, status, deadline_at, last_seen_at FROM quiz_attempts WHERE id = ? AND student_id = ?'
);
$stmt->execute([$attemptId, (int) $user['id']]);
$row = $stmt->fetch();

if ($row === false) {
    json_out(['error' => 'Not found'], 404);
}

$remaining = 0;
if ($row['status'] === 'in_progress') {
    $deadlineTs = (new DateTimeImmutable((string) $row['deadline_at'], new DateTimeZone('UTC')))->getTimestamp();
    $remaining = max(0, $deadlineTs - time());
}

json_out([
    'attempt_id'        => (int) $row['id'],
    'status'            => (string) $row['status'],
    'remaining_seconds' => $remaining,
    'server_manila'     => to_manila(utc_now(), 'j M Y, H:i:s'),
    'ends_manila'       => to_manila((string) $row['deadline_at'], 'H:i'),
]);

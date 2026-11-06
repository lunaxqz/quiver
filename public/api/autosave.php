<?php
declare(strict_types=1);

/**
 * Student autosave and 20s heartbeat endpoint (Day 13).
 *
 * Accepts POST (JSON body or form fields):
 *   - attempt_id: int
 *   - answers: object/array keyed by question_id (or list of {question_id, option_ids, answer_text, revision})
 *   - _csrf: string (or X-CSRF-Token header)
 *
 * Enforces:
 *   - Student authentication & CSRF verification
 *   - Row lock (`SELECT ... FOR UPDATE`), ownership check, 15s grace window, revision check, and `last_seen_at` heartbeat via AttemptService::autosave().
 */

require_once __DIR__ . '/../../app/bootstrap.php';

if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') {
    header('Allow: POST');
    json_out(['error' => 'Method not allowed'], 405);
}

$user = current_user();
if ($user === null || ($user['role'] ?? '') !== 'student') {
    json_out(['error' => 'Not signed in'], 401);
}

$rawBody = file_get_contents('php://input');
$jsonBody = [];
if (is_string($rawBody) && $rawBody !== '') {
    $decoded = json_decode($rawBody, true);
    if (is_array($decoded)) {
        $jsonBody = $decoded;
    }
}

$sentCsrf = $_POST['_csrf']
    ?? ($jsonBody['_csrf'] ?? ($_SERVER['HTTP_X_CSRF_TOKEN'] ?? ''));

if (!is_string($sentCsrf) || !hash_equals(csrf_token(), $sentCsrf)) {
    json_out(['error' => 'Invalid or expired security token'], 403);
}

$attemptId = (int) ($_POST['attempt_id'] ?? ($jsonBody['attempt_id'] ?? 0));
if ($attemptId <= 0) {
    json_out(['error' => 'Not found'], 404);
}

$answers = $_POST['answers'] ?? ($jsonBody['answers'] ?? []);
if (is_string($answers)) {
    $parsedAnswers = json_decode($answers, true);
    $answers = is_array($parsedAnswers) ? $parsedAnswers : [];
} elseif (!is_array($answers)) {
    $answers = [];
}

$res = AttemptService::autosave($attemptId, (int) $user['id'], $answers);

if ($res['result'] === 'not_found') {
    json_out(['error' => 'Not found'], 404);
}

if ($res['result'] === 'closed') {
    json_out([
        'ok'                => false,
        'error'             => 'Attempt is closed',
        'attempt_id'        => $res['attempt_id'],
        'status'            => $res['status'],
        'remaining_seconds' => $res['remaining_seconds'],
        'saved_manila'      => $res['saved_manila'],
        'saved_revisions'   => (object) $res['saved_revisions'],
    ], 409);
}

json_out([
    'ok'                => true,
    'attempt_id'        => $res['attempt_id'],
    'status'            => $res['status'],
    'remaining_seconds' => $res['remaining_seconds'],
    'saved_manila'      => $res['saved_manila'],
    'saved_revisions'   => (object) $res['saved_revisions'],
]);

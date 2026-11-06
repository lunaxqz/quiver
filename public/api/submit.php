<?php
declare(strict_types=1);

/**
 * Student final submit endpoint (Day 14).
 *
 * Accepts POST (JSON body or form fields):
 *   - attempt_id: int
 *   - answers: optional final map/list of answers to flush before finalizing
 *   - reason: 'student' | 'timeout' (defaults to 'student')
 *   - _csrf: string (or X-CSRF-Token header)
 *
 * Enforces:
 *   - Student authentication & CSRF verification
 *   - Idempotent submit & automatic objective scoring + blank-written auto-zeroing via AttemptService::submit()
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

$reason = (string) ($_POST['reason'] ?? ($jsonBody['reason'] ?? 'student'));
if (!in_array($reason, ['student', 'timeout'], true)) {
    $reason = 'student';
}

$res = AttemptService::submit($attemptId, (int) $user['id'], $answers, $reason);

if ($res['result'] === 'not_found') {
    json_out(['error' => 'Not found'], 404);
}

if ($res['result'] === 'voided') {
    json_out([
        'ok'         => false,
        'error'      => 'Attempt has been voided',
        'attempt_id' => $res['attempt_id'],
        'status'     => 'voided',
    ], 409);
}

$accept = (string) ($_SERVER['HTTP_ACCEPT'] ?? '');
$wantsJson = str_contains($accept, 'application/json') || $jsonBody !== [];

if (!$wantsJson) {
    redirect('/student/receipt.php?id=' . $res['attempt_id']);
}

json_out([
    'ok'               => true,
    'result'           => $res['result'],
    'attempt_id'       => $res['attempt_id'],
    'status'           => $res['status'],
    'submit_reason'    => $res['submit_reason'],
    'submitted_at'     => $res['submitted_at'],
    'submitted_manila' => $res['submitted_manila'],
    'reference'        => $res['reference'],
    'redirect_url'     => '/student/receipt.php?id=' . $res['attempt_id'],
]);

<?php
declare(strict_types=1);

/**
 * Teacher Live View & Time Extension API endpoint (Day 18).
 *
 * GET /api/live.php?quiz_id=N
 *   - Polled every 15 seconds by `/assets/js/live.js`.
 *   - Enforces teacher authentication and ownership.
 *   - Returns live hero stats, attention banner, participant states, and recent audit events.
 *
 * POST /api/live.php
 *   - Extends student time or voids an active sitting.
 *   - Requires CSRF token and teacher ownership.
 *   - Actions:
 *       1. 'extend_time'  (attempt_id, minutes, note)
 *       2. 'void_attempt' (attempt_id, reason)
 */

require_once __DIR__ . '/../../app/bootstrap.php';

$user = current_user();
if ($user === null || !in_array($user['role'] ?? '', ['teacher', 'admin'], true)) {
    json_out(['error' => 'Not found'], 404);
}

$teacherId = (int) $user['id'];
$isAdmin   = ($user['role'] ?? '') === 'admin';
$method    = $_SERVER['REQUEST_METHOD'] ?? 'GET';

if ($method === 'GET') {
    $quizId = (int) ($_GET['quiz_id'] ?? $_GET['id'] ?? 0);
    if ($quizId <= 0) {
        json_out(['error' => 'Missing quiz ID'], 400);
    }

    $data = LiveService::getLiveView($quizId, $teacherId, $isAdmin);
    if ($data === null) {
        json_out(['error' => 'Quiz not found'], 404);
    }

    json_out([
        'ok'   => true,
        'data' => $data,
    ]);
}

if ($method === 'POST') {
    // Parse JSON body or form encoded
    $rawBody  = file_get_contents('php://input');
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

    $action    = (string) ($_POST['action'] ?? ($jsonBody['action'] ?? ''));
    $attemptId = (int) ($_POST['attempt_id'] ?? ($jsonBody['attempt_id'] ?? 0));

    if ($attemptId <= 0) {
        json_out(['error' => 'Missing attempt ID'], 400);
    }

    if ($action === 'extend_time') {
        $minutes = (int) ($_POST['minutes'] ?? ($jsonBody['minutes'] ?? 0));
        $note    = isset($_POST['note']) ? (string) $_POST['note'] : ($jsonBody['note'] ?? null);

        $res = LiveService::extendTime($attemptId, $teacherId, $isAdmin, $minutes, $note);
        if ($res['result'] === 'not_found') {
            json_out(['error' => 'Attempt not found'], 404);
        }
        if ($res['result'] !== 'ok') {
            json_out(['error' => $res['message'] ?? 'Could not extend time'], 422);
        }

        json_out([
            'ok'      => true,
            'message' => $res['message'],
            'payload' => $res,
        ]);
    }

    if ($action === 'void_attempt') {
        $reason = trim((string) ($_POST['reason'] ?? ($jsonBody['reason'] ?? '')));
        if ($reason === '') {
            json_out(['error' => 'A reason is required to void an attempt'], 422);
        }

        $res = LiveService::voidAttempt($attemptId, $teacherId, $isAdmin, $reason);
        if ($res['result'] === 'not_found') {
            json_out(['error' => 'Attempt not found'], 404);
        }
        if ($res['result'] !== 'ok') {
            json_out(['error' => $res['message'] ?? 'Could not void attempt'], 422);
        }

        json_out([
            'ok'      => true,
            'message' => $res['message'],
            'payload' => $res,
        ]);
    }

    json_out(['error' => 'Unsupported action'], 400);
}

header('Allow: GET, POST');
json_out(['error' => 'Method not allowed'], 405);

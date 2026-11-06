<?php
declare(strict_types=1);

/**
 * Teacher grading API endpoint (Day 15).
 *
 * Accepts POST (JSON body or form fields):
 *   - action: 'grade' | 'undo' (defaults to 'grade')
 *   - response_id: int
 *   - points: float (required when action = 'grade')
 *   - feedback: ?string (optional when action = 'grade')
 *   - restore_points: ?float (optional when action = 'undo'; null restores to ungraded/auto_points)
 *   - restore_feedback: ?string (optional when action = 'undo')
 *   - _csrf: string (or X-CSRF-Token header)
 *
 * Enforces:
 *   - Teacher authentication & CSRF verification
 *   - Class/quiz ownership check (returns 404 if not owned by this teacher)
 *   - Points range check (0..max_points)
 *   - Append-only `grade_events` audit log (including undo as a new event)
 *   - Automatic recomputation of `quiz_attempts.score` and `status`
 */

require_once __DIR__ . '/../../app/bootstrap.php';

if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') {
    header('Allow: POST');
    json_out(['error' => 'Method not allowed'], 405);
}

$user = current_user();
if ($user === null || ($user['role'] ?? '') !== 'teacher') {
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

$teacherId  = (int) $user['id'];
$action     = (string) ($_POST['action'] ?? ($jsonBody['action'] ?? 'grade'));
$responseId = (int) ($_POST['response_id'] ?? ($jsonBody['response_id'] ?? 0));

if ($responseId <= 0) {
    json_out(['error' => 'Not found'], 404);
}

if ($action === 'undo') {
    $hasRestorePoints = array_key_exists('restore_points', $_POST) || array_key_exists('restore_points', $jsonBody);
    $rawRestorePts = $_POST['restore_points'] ?? ($jsonBody['restore_points'] ?? null);
    $restorePts = ($rawRestorePts !== null && $rawRestorePts !== '' && is_numeric($rawRestorePts))
        ? (float) $rawRestorePts
        : null;

    $rawRestoreFb = $_POST['restore_feedback'] ?? ($jsonBody['restore_feedback'] ?? null);
    $restoreFb = is_string($rawRestoreFb) ? $rawRestoreFb : null;

    $res = $hasRestorePoints
        ? GradingService::undoResponse($responseId, $teacherId, $restorePts, $restoreFb)
        : GradingService::undoResponse($responseId, $teacherId);

    if ($res['result'] === 'not_found') {
        json_out(['error' => 'Not found'], 404);
    }

    $accept = (string) ($_SERVER['HTTP_ACCEPT'] ?? '');
    $wantsJson = str_contains($accept, 'application/json') || $jsonBody !== [];
    if (!$wantsJson) {
        flash_set('Last mark undone.');
        redirect('/teacher/grading.php?quiz_id=' . $res['quiz_id']);
    }

    json_out([
        'ok'                    => true,
        'action'                => 'undo',
        'response_id'           => $res['response_id'],
        'attempt_id'            => $res['attempt_id'],
        'quiz_id'               => $res['quiz_id'],
        'points_awarded'        => $res['points_awarded'],
        'auto_points'           => $res['auto_points'],
        'is_overridden'         => $res['is_overridden'],
        'is_pending'            => $res['is_pending'],
        'feedback'              => $res['feedback'],
        'graded_at_manila'      => $res['graded_at_manila'],
        'attempt_score'         => $res['attempt_score'],
        'attempt_status'        => $res['attempt_status'],
        'pending_written_count' => $res['pending_written_count'],
        'grade_event_id'        => $res['grade_event_id'],
    ]);
}

// Default action: 'grade'
$rawPoints = $_POST['points'] ?? ($jsonBody['points'] ?? null);
if ($rawPoints === null || $rawPoints === '' || !is_numeric($rawPoints)) {
    json_out(['ok' => false, 'error' => 'Enter a valid points value.'], 422);
}

$points   = (float) $rawPoints;
$feedback = $_POST['feedback'] ?? ($jsonBody['feedback'] ?? null);
$feedback = is_string($feedback) ? $feedback : null;

$res = GradingService::gradeResponse($responseId, $teacherId, $points, $feedback);

if ($res['result'] === 'not_found') {
    json_out(['error' => 'Not found'], 404);
}

if ($res['result'] === 'invalid_points') {
    json_out(['ok' => false, 'error' => $res['error'] ?? 'Invalid points value.'], 422);
}

$accept = (string) ($_SERVER['HTTP_ACCEPT'] ?? '');
$wantsJson = str_contains($accept, 'application/json') || $jsonBody !== [];
if (!$wantsJson) {
    flash_set('Mark saved.');
    redirect('/teacher/grading.php?quiz_id=' . $res['quiz_id']);
}

json_out([
    'ok'                    => true,
    'action'                => 'grade',
    'response_id'           => $res['response_id'],
    'attempt_id'            => $res['attempt_id'],
    'quiz_id'               => $res['quiz_id'],
    'points_awarded'        => $res['points_awarded'],
    'auto_points'           => $res['auto_points'],
    'is_overridden'         => $res['is_overridden'],
    'is_pending'            => false,
    'feedback'              => $res['feedback'],
    'graded_at_manila'      => $res['graded_at_manila'],
    'attempt_score'         => $res['attempt_score'],
    'attempt_status'        => $res['attempt_status'],
    'pending_written_count' => $res['pending_written_count'],
    'grade_event_id'        => $res['grade_event_id'],
    'previous_state'        => $res['previous_state'],
]);

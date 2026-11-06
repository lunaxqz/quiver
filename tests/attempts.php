<?php
declare(strict_types=1);

/**
 * Day 11: starting attempts and the answer-leakage test.
 * Loaded by tests/run.php, which provides check(). Everything made here is removed at the end.
 */

$tag = 'att_' . bin2hex(random_bytes(3));
$ids = ['users' => [], 'classes' => [], 'quizzes' => []];
$pdo = db();

$mkUser = static function (string $suffix, string $role) use ($tag, &$ids): int {
    $id = AuthService::createUser($tag . '_' . $suffix, 'Test ' . $suffix, 'Password-' . bin2hex(random_bytes(4)), $role);
    $ids['users'][] = $id;
    return $id;
};

/** Insert a published quiz directly (the publish flow is not on this branch yet). Times are minutes from now. */
$mkQuiz = static function (int $classId, int $teacherId, int $opensIn, int $closesIn, ?int $duration, int $max) use ($pdo, &$ids): int {
    $now = utc_now();
    $at  = static fn(int $m): string => gmdate('Y-m-d H:i:s', time() + $m * 60);
    $pdo->prepare(
        "INSERT INTO quizzes (class_id, created_by, title, instructions, status, opens_at, closes_at, duration_min, max_attempts,
                              created_at, updated_at, published_at)
         VALUES (?, ?, 'Leak test quiz', 'Choose the best answer.', 'published', ?, ?, ?, ?, ?, ?, ?)"
    )->execute([$classId, $teacherId, $at($opensIn), $at($closesIn), $duration, $max, $now, $now, $now]);
    $id = (int) $pdo->lastInsertId();
    $ids['quizzes'][] = $id;
    return $id;
};

$addQuestion = static function (int $quizId, int $pos, string $type, string $prompt, ?string $rubric, array $options) use ($pdo): int {
    $pdo->prepare('INSERT INTO quiz_questions (quiz_id, position, type, prompt, points, rubric) VALUES (?, ?, ?, ?, 2.00, ?)')
        ->execute([$quizId, $pos, $type, $prompt, $rubric]);
    $qid = (int) $pdo->lastInsertId();
    foreach ($options as $i => [$label, $correct]) {
        $pdo->prepare('INSERT INTO quiz_question_options (quiz_question_id, label, is_correct, position) VALUES (?, ?, ?, ?)')
            ->execute([$qid, $label, $correct ? 1 : 0, $i + 1]);
    }
    return $qid;
};

try {
    $teacher  = $mkUser('t', 'teacher');
    $teacherB = $mkUser('tb', 'teacher');
    $student  = $mkUser('s1', 'student');
    $outsider = $mkUser('s2', 'student');
    $racer    = $mkUser('s3', 'student');

    $classId = ClassService::create($teacher, 'Attempt class', 'AT1', 'B', '2026-27 First semester');
    $ids['classes'][] = $classId;
    ClassService::addByUsername($classId, $tag . '_s1');
    ClassService::addByUsername($classId, $tag . '_s3');

    // ---------------------------------------------------------------- the deadline rule
    check('deadline is now + duration when that comes first',
        AttemptService::deadlineFor('2026-11-11 04:00:00', '2026-11-11 06:00:00', 45) === '2026-11-11 04:45:00');
    check('deadline is the closing time when that comes first',
        AttemptService::deadlineFor('2026-11-11 04:30:00', '2026-11-11 04:55:00', 45) === '2026-11-11 04:55:00');
    check('a quiz with no duration ends at its closing time',
        AttemptService::deadlineFor('2026-11-11 04:00:00', '2026-11-11 06:00:00', null) === '2026-11-11 06:00:00');

    // ---------------------------------------------------------------- the open quiz
    $open = $mkQuiz($classId, $teacher, -60, 60, 30, 1);
    $addQuestion($open, 1, 'single', 'Which device forwards packets between networks?', null,
        [['Hub', false], ['Router', true], ['Switch', false]]);
    $addQuestion($open, 2, 'short', 'What does DNS stand for?', null,
        [['SHORT_KEY_SECRET_Domain Name System', true]]);
    $addQuestion($open, 3, 'essay', 'Explain subnetting.', 'RUBRIC_SECRET mentions masks', []);
    $addQuestion($open, 4, 'true_false', 'A switch works at layer 2.', null, [['True', true], ['False', false]]);

    check('an outsider cannot see the start screen', AttemptService::startInfo($outsider, $open) === null);
    check('an outsider cannot start the quiz', AttemptService::start($outsider, $open)['result'] === 'not_found');

    $info = AttemptService::startInfo($student, $open);
    check('the start screen counts 4 questions and 8 points', $info !== null && $info['question_count'] === 4 && $info['total_points'] === 8.0);

    $first = AttemptService::start($student, $open);
    check('a member starts attempt 1 of an open quiz', $first['result'] === 'started' && $first['attempt_id'] !== null);
    $again = AttemptService::start($student, $open);
    check('pressing Begin again resumes the same attempt', $again['result'] === 'resumed' && $again['attempt_id'] === $first['attempt_id']);

    $row = $pdo->query('SELECT attempt_no, started_at, deadline_at FROM quiz_attempts WHERE id = ' . (int) $first['attempt_id'])->fetch();
    $mins = (strtotime($row['deadline_at'] . ' UTC') - strtotime($row['started_at'] . ' UTC')) / 60;
    check('the stored deadline is 30 minutes after the start', (int) $row['attempt_no'] === 1 && (int) round($mins) === 30);

    // ---------------------------------------------------------------- LEAKAGE TEST
    check('an outsider cannot load someone else\'s attempt', AttemptService::payload((int) $first['attempt_id'], $outsider) === null);
    $payload = AttemptService::payload((int) $first['attempt_id'], $student);
    $json = (string) json_encode($payload);
    check('LEAK: the payload never contains is_correct', !str_contains($json, 'is_correct') && !str_contains($json, 'correct'));
    check('LEAK: short-answer accepted answers are not sent', !str_contains($json, 'SHORT_KEY_SECRET'));
    check('LEAK: the essay rubric is not sent', !str_contains($json, 'RUBRIC_SECRET') && !str_contains($json, 'rubric'));
    check('LEAK: no scores or provenance are sent', !str_contains($json, 'score') && !str_contains($json, 'source_question_id'));

    $qKeys = ['id', 'position', 'type', 'prompt', 'points', 'options'];
    $oKeys = ['id', 'label', 'position'];
    $shapeOk = true;
    foreach ($payload['questions'] as $q) {
        $shapeOk = $shapeOk && array_keys($q) === $qKeys;
        foreach ($q['options'] as $o) {
            $shapeOk = $shapeOk && array_keys($o) === $oKeys;
        }
    }
    check('LEAK: every question and option has exactly the allowed fields', $shapeOk);
    check('choice questions keep their options; short and essay send none',
        count($payload['questions'][0]['options']) === 3 && $payload['questions'][1]['options'] === []
        && $payload['questions'][2]['options'] === [] && count($payload['questions'][3]['options']) === 2);
    check('the browser gets remaining seconds from the server', $payload['attempt']['remaining_seconds'] > 1700 && $payload['attempt']['remaining_seconds'] <= 1800);
    check('the browser gets Manila deadline and empty responses map on a fresh attempt',
        $payload['attempt']['deadline_manila'] !== '' && is_object($payload['responses']) && count((array) $payload['responses']) === 0);

    // Simulate an elapsed-time reload: move deadline_at to 12 minutes from now and confirm payload recomputes remaining_seconds.
    $twelveMinLater = gmdate('Y-m-d H:i:s', time() + 720);
    $pdo->prepare('UPDATE quiz_attempts SET deadline_at = ? WHERE id = ?')->execute([$twelveMinLater, (int) $first['attempt_id']]);
    $reloaded = AttemptService::payload((int) $first['attempt_id'], $student);
    check('reloading mid-quiz recomputes remaining_seconds from the server clock',
        $reloaded !== null && $reloaded['attempt']['remaining_seconds'] > 700 && $reloaded['attempt']['remaining_seconds'] <= 720);

    // Same check on the real page HTML, by rendering attempt.php's JSON exactly as it is printed.
    $html = json_encode($payload, JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_UNESCAPED_UNICODE);
    check('LEAK: the embedded page JSON has no answer keys either', !str_contains((string) $html, 'is_correct') && !str_contains((string) $html, 'SHORT_KEY_SECRET'));

    // ---------------------------------------------------------------- Day 13: autosave, revisions, grace window, heartbeat
    $attId = (int) $first['attempt_id'];
    $qSingleId = (int) $payload['questions'][0]['id'];
    $qShortId  = (int) $payload['questions'][1]['id'];
    $qEssayId  = (int) $payload['questions'][2]['id'];
    $optRouterId = (int) $payload['questions'][0]['options'][1]['id'];
    $optHubId    = (int) $payload['questions'][0]['options'][0]['id'];

    check('an outsider cannot autosave into someone else\'s attempt',
        AttemptService::autosave($attId, $outsider, [$qSingleId => ['option_ids' => [$optRouterId], 'revision' => 1]])['result'] === 'not_found');

    // Save 2 answers (simulating offline queue flushing 2 answered questions on reconnect)
    $saveTime1 = gmdate('Y-m-d H:i:s', time() + 60);
    $save1 = AttemptService::autosave($attId, $student, [
        $qSingleId => ['option_ids' => [$optRouterId], 'revision' => 2],
        $qShortId  => ['answer_text' => 'Domain Name System', 'revision' => 1],
    ], $saveTime1);
    check('autosave succeeds and returns Manila timestamp and saved revisions',
        $save1['result'] === 'ok'
        && $save1['saved_manila'] === to_manila($saveTime1, 'j M Y, H:i:s')
        && ($save1['saved_revisions'][$qSingleId] ?? 0) === 2
        && ($save1['saved_revisions'][$qShortId] ?? 0) === 1);

    // Stale revision check: revision 1 arrives late after revision 2 is already stored
    $stale = AttemptService::autosave($attId, $student, [
        $qSingleId => ['option_ids' => [$optHubId], 'revision' => 1],
    ], gmdate('Y-m-d H:i:s', time() + 65));
    $afterStale = AttemptService::payload($attId, $student);
    $afterResp  = (array) $afterStale['responses'];
    check('stale revision (<= stored) is ignored and never overwrites a newer answer',
        $stale['result'] === 'ok'
        && ($afterResp[$qSingleId]['revision'] ?? 0) === 2
        && ($afterResp[$qSingleId]['option_ids'] ?? []) === [$optRouterId]
        && ($afterResp[$qShortId]['answer_text'] ?? '') === 'Domain Name System');

    // Newer revision overwrites cleanly
    $newer = AttemptService::autosave($attId, $student, [
        $qSingleId => ['option_ids' => [$optHubId], 'revision' => 3],
    ], gmdate('Y-m-d H:i:s', time() + 70));
    $afterNewer = (array) AttemptService::payload($attId, $student)['responses'];
    check('newer revision (> stored) updates the stored option selection',
        $newer['result'] === 'ok'
        && ($afterNewer[$qSingleId]['revision'] ?? 0) === 3
        && ($afterNewer[$qSingleId]['option_ids'] ?? []) === [$optHubId]);

    // Heartbeat with empty answers updates last_seen_at
    $hbTime = gmdate('Y-m-d H:i:s', time() + 90);
    $hb = AttemptService::autosave($attId, $student, [], $hbTime);
    $seenRow = $pdo->query('SELECT last_seen_at FROM quiz_attempts WHERE id = ' . $attId)->fetch();
    check('heartbeat call with empty answers updates last_seen_at',
        $hb['result'] === 'ok' && (string) $seenRow['last_seen_at'] === $hbTime);

    // 15-second grace window: 10s after deadline_at is accepted; 16s after deadline_at is refused
    $deadlineNow = (string) $pdo->query('SELECT deadline_at FROM quiz_attempts WHERE id = ' . $attId)->fetchColumn();
    $plus10s = gmdate('Y-m-d H:i:s', strtotime($deadlineNow . ' UTC') + 10);
    $plus16s = gmdate('Y-m-d H:i:s', strtotime($deadlineNow . ' UTC') + 16);
    $withinGrace = AttemptService::autosave($attId, $student, [
        $qEssayId => ['answer_text' => 'Saved inside 15s grace window.', 'revision' => 1],
    ], $plus10s);
    $pastGrace = AttemptService::autosave($attId, $student, [
        $qEssayId => ['answer_text' => 'Too late.', 'revision' => 2],
    ], $plus16s);
    $afterGrace = (array) AttemptService::payload($attId, $student)['responses'];
    check('autosave within the 15-second grace window succeeds, and past 15s is refused',
        $withinGrace['result'] === 'ok'
        && $pastGrace['result'] === 'closed'
        && ($afterGrace[$qEssayId]['answer_text'] ?? '') === 'Saved inside 15s grace window.');

    // ---------------------------------------------------------------- Day 14: idempotent submit, objective scoring, blank written zeroing, overdue sweep & receipt
    // At this point on $attId:
    //   - Q1 (single, 2 pts): Hub selected (wrong -> 0 pts) — let's switch to Router (correct -> 2 pts) via final submit answers!
    //   - Q2 (short, 2 pts): 'Domain Name System' (non-blank written) -> stays ungraded for teacher; let's clear Q3 essay to blank so we test blank auto-zeroing!
    //   - Q3 (essay, 2 pts): we pass '' (blank) -> auto-zeroed (auto_points = 0, points_awarded = 0)
    //   - Q4 (true_false, 2 pts): unanswered -> auto-scored 0 pts
    $submitNow = gmdate('Y-m-d H:i:s', strtotime($deadlineNow . ' UTC') - 30);
    check('an outsider cannot submit someone else\'s attempt',
        AttemptService::submit($attId, $outsider, [], 'student', $submitNow)['result'] === 'not_found');

    $sub1 = AttemptService::submit($attId, $student, [
        $qSingleId => ['option_ids' => [$optRouterId], 'revision' => 4],
        $qEssayId  => ['answer_text' => '   ', 'revision' => 2],
    ], 'student', $submitNow);
    check('student submit succeeds and returns submitted status (since non-blank short answer awaits teacher grading)',
        $sub1['result'] === 'submitted'
        && $sub1['status'] === 'submitted'
        && $sub1['submit_reason'] === 'student'
        && str_starts_with((string) $sub1['reference'], 'QV-'));

    // Double-submit / idempotent check: calling submit a second time returns already_submitted with the exact same timestamp & reference.
    $sub2 = AttemptService::submit($attId, $student, [], 'student', gmdate('Y-m-d H:i:s', strtotime($submitNow . ' UTC') + 5));
    check('submitting a second time is idempotent and returns already_submitted',
        $sub2['result'] === 'already_submitted'
        && $sub2['status'] === 'submitted'
        && $sub2['submitted_at'] === $sub1['submitted_at']
        && $sub2['reference'] === $sub1['reference']);

    // Verify objective scoring & blank-written auto-zeroing in responses table
    $qTfId = (int) $payload['questions'][3]['id'];
    $respMap = [];
    foreach ($pdo->query('SELECT quiz_question_id, auto_points, points_awarded FROM responses WHERE attempt_id = ' . $attId)->fetchAll() as $rRow) {
        $respMap[(int) $rRow['quiz_question_id']] = $rRow;
    }
    check('correct single-select answer is auto-scored full points (2.00)',
        (float) ($respMap[$qSingleId]['auto_points'] ?? -1) === 2.0
        && (float) ($respMap[$qSingleId]['points_awarded'] ?? -1) === 2.0);
    check('unanswered true/false question is auto-scored 0.00',
        (float) ($respMap[$qTfId]['auto_points'] ?? -1) === 0.0
        && (float) ($respMap[$qTfId]['points_awarded'] ?? -1) === 0.0);
    check('blank written essay is auto-zeroed immediately (0.00)',
        (float) ($respMap[$qEssayId]['auto_points'] ?? -1) === 0.0
        && (float) ($respMap[$qEssayId]['points_awarded'] ?? -1) === 0.0);
    check('non-blank written short answer stays ungraded (points_awarded IS NULL) for teacher grading',
        array_key_exists($qShortId, $respMap) && $respMap[$qShortId]['points_awarded'] === null);

    // Receipt check: outsider gets null; student gets receipt without unreleased score or answer review
    check('an outsider cannot view someone else\'s submission receipt',
        AttemptService::receipt($attId, $outsider) === null);
    $rcpt = AttemptService::receipt($attId, $student);
    check('student receipt shows answered/unanswered counts and hides score & review before release',
        $rcpt !== null
        && $rcpt['answered_count'] === 2
        && $rcpt['unanswered_count'] === 2
        && $rcpt['score_released'] === false
        && $rcpt['score'] === null
        && $rcpt['review_released'] === false
        && $rcpt['review_items'] === []);

    // Overdue sweep check on an objective-only quiz (all-objective -> status becomes 'graded', submitted_at = deadline_at)
    $objQuiz = $mkQuiz($classId, $teacher, -60, 60, 20, 1);
    $qObj1 = $addQuestion($objQuiz, 1, 'single', 'Port for HTTPS?', null, [['80', false], ['443', true]]);
    $qObj2 = $addQuestion($objQuiz, 2, 'multi', 'Pick private IPv4 ranges', null, [['10.0.0.0/8', true], ['192.168.0.0/16', true], ['8.8.8.0/24', false]]);
    $racerStart = AttemptService::start($racer, $objQuiz);
    $racerAttId = (int) $racerStart['attempt_id'];
    $racerPayload = AttemptService::payload($racerAttId, $racer);
    $opt443Id = (int) $racerPayload['questions'][0]['options'][1]['id'];
    $opt10Id  = (int) $racerPayload['questions'][1]['options'][0]['id'];
    $opt192Id = (int) $racerPayload['questions'][1]['options'][1]['id'];
    AttemptService::autosave($racerAttId, $racer, [
        $qObj1 => ['option_ids' => [$opt443Id], 'revision' => 1],
        $qObj2 => ['option_ids' => [$opt10Id, $opt192Id], 'revision' => 1],
    ]);
    // Move racer's deadline to 30 seconds ago (past the 15s grace window) and run overdue sweep
    $pastDeadline = gmdate('Y-m-d H:i:s', time() - 30);
    $pdo->prepare('UPDATE quiz_attempts SET deadline_at = ? WHERE id = ?')->execute([$pastDeadline, $racerAttId]);
    $sweptCount = AttemptService::finalizeOverdue($objQuiz, $teacher);
    $sweptRow = $pdo->query('SELECT status, submit_reason, submitted_at, score FROM quiz_attempts WHERE id = ' . $racerAttId)->fetch();
    check('overdue sweep finalizes abandoned attempt to graded with submit_reason=timeout and submitted_at=deadline_at',
        $sweptCount === 1
        && $sweptRow['status'] === 'graded'
        && $sweptRow['submit_reason'] === 'timeout'
        && $sweptRow['submitted_at'] === $pastDeadline
        && (float) $sweptRow['score'] === 4.0);

    // ---------------------------------------------------------------- Day 15: GradingService, grade_events, undo & auto-grade override
    check('teacher B cannot open teacher A\'s grading workspace (returns null / 404)',
        GradingService::workspace($open, $teacherB) === null);

    $ws = GradingService::workspace($open, $teacher);
    check('teacher A loads grading workspace with 1 pending written response in stack',
        $ws !== null
        && $ws['quiz']['pending_written_count'] === 1
        && count($ws['stack_items']) === 1
        && $ws['stack_items'][0]['question_id'] === $qShortId
        && $ws['stack_items'][0]['is_pending'] === true);

    $shortRespId  = (int) $ws['stack_items'][0]['response_id'];
    $singleRespId = (int) $pdo->query('SELECT id FROM responses WHERE attempt_id = ' . $attId . ' AND quiz_question_id = ' . $qSingleId)->fetchColumn();

    check('teacher B cannot grade teacher A\'s student response (returns not_found)',
        GradingService::gradeResponse($shortRespId, $teacherB, 2.0, 'Hijack')['result'] === 'not_found');

    check('points outside 0..max_points are rejected',
        GradingService::gradeResponse($shortRespId, $teacher, 5.0)['result'] === 'invalid_points'
        && GradingService::gradeResponse($shortRespId, $teacher, -1.0)['result'] === 'invalid_points');

    // Grade the pending short answer with 1.50 / 2.00 points -> attempt has no remaining ungraded written items, so status flips to 'graded' and score becomes 2.00 + 1.50 = 3.50
    $g1 = GradingService::gradeResponse($shortRespId, $teacher, 1.50, 'Good answer, minor detail missing.');
    $evCount1 = (int) $pdo->query('SELECT COUNT(*) FROM grade_events WHERE response_id = ' . $shortRespId)->fetchColumn();
    check('grading pending written response updates attempt status to graded, recomputes score (3.50), and inserts grade_events row',
        $g1['result'] === 'ok'
        && $g1['points_awarded'] === 1.50
        && $g1['attempt_status'] === 'graded'
        && $g1['attempt_score'] === 3.50
        && $g1['pending_written_count'] === 0
        && $evCount1 === 1);

    // Undo the mark on the short answer (restoring to null / pending) -> inserts a 2nd grade_events row (never deletes!) and reverts attempt status to 'submitted'
    check('teacher B cannot undo teacher A\'s mark',
        GradingService::undoResponse($shortRespId, $teacherB, null, null)['result'] === 'not_found');

    $u1 = GradingService::undoResponse($shortRespId, $teacher, null, null);
    $evCount2 = (int) $pdo->query('SELECT COUNT(*) FROM grade_events WHERE response_id = ' . $shortRespId)->fetchColumn();
    check('undoing mark appends a 2nd grade_events row (never deletes) and reverts response to pending and attempt status to submitted',
        $u1['result'] === 'ok'
        && $u1['points_awarded'] === null
        && $u1['is_pending'] === true
        && $u1['attempt_status'] === 'submitted'
        && $u1['attempt_score'] === 2.00
        && $u1['pending_written_count'] === 1
        && $evCount2 === 2);

    // Re-grade short answer with full 2.00 points, then override auto-graded single-choice question from 2.00 to 1.00
    GradingService::gradeResponse($shortRespId, $teacher, 2.00, 'Full credit on second look.');
    $overrideRes = GradingService::gradeResponse($singleRespId, $teacher, 1.00, 'Partial credit override.');
    check('overriding an auto-graded objective item preserves auto_points (2.00), sets points_awarded (1.00), and recomputes attempt score (3.00)',
        $overrideRes['result'] === 'ok'
        && $overrideRes['auto_points'] === 2.00
        && $overrideRes['points_awarded'] === 1.00
        && $overrideRes['is_overridden'] === true
        && $overrideRes['attempt_score'] === 3.00);

    // ---------------------------------------------------------------- Day 16: ReportService, independent release controls, retake score policy & safe CSV export
    check('teacher B cannot view or export teacher A\'s quiz report (returns null)',
        ReportService::reportForQuiz($open, $teacherB) === null
        && ReportService::exportCsv($open, $teacherB) === null
        && ReportService::updateRelease($open, $teacherB, 'release_scores')['result'] === 'not_found');

    // Before release: student sees neither score nor answer review
    $rcptBefore = AttemptService::receipt($attId, $student);
    check('before release, student receipt hides both score and answer review',
        $rcptBefore !== null
        && $rcptBefore['score_released'] === false
        && $rcptBefore['score'] === null
        && $rcptBefore['review_released'] === false
        && $rcptBefore['review_items'] === []);

    // Release scores ONLY (answer review stays private)
    $relScoreRes = ReportService::updateRelease($open, $teacher, 'release_scores');
    $rcptScoreOnly = AttemptService::receipt($attId, $student);
    check('releasing scores reveals student score (3.00 / 8.00) while keeping answer review private',
        $relScoreRes['result'] === 'ok'
        && $rcptScoreOnly !== null
        && $rcptScoreOnly['score_released'] === true
        && $rcptScoreOnly['score'] === 3.00
        && $rcptScoreOnly['percentage'] === 38
        && $rcptScoreOnly['review_released'] === false
        && $rcptScoreOnly['review_items'] === []);

    // Release answer review separately
    $relRevRes = ReportService::updateRelease($open, $teacher, 'release_review');
    $rcptBoth = AttemptService::receipt($attId, $student);
    check('releasing answer review reveals questions, student answers, correct keys, and teacher feedback',
        $relRevRes['result'] === 'ok'
        && $rcptBoth !== null
        && $rcptBoth['score_released'] === true
        && $rcptBoth['review_released'] === true
        && count($rcptBoth['review_items']) === 4
        && $rcptBoth['review_items'][0]['feedback'] === 'Partial credit override.');

    // Hold scores again (while answer review stays released) -> independent toggle works in both directions
    ReportService::updateRelease($open, $teacher, 'hold_scores');
    $rcptRevOnly = AttemptService::receipt($attId, $student);
    check('holding scores hides the total score again while leaving answer review released',
        $rcptRevOnly !== null
        && $rcptRevOnly['score_released'] === false
        && $rcptRevOnly['score'] === null
        && $rcptRevOnly['review_released'] === true);
    ReportService::updateRelease($open, $teacher, 'release_scores');

    // Retake score policy check (highest vs latest) + class statistics (mean, median, range)
    $policyQuiz = $mkQuiz($classId, $teacher, -60, 60, 20, 3);
    $pq1 = $addQuestion($policyQuiz, 1, 'single', 'Q1', null, [['A', true], ['B', false]]);
    $pq2 = $addQuestion($policyQuiz, 2, 'single', 'Q2', null, [['C', true], ['D', false]]);
    // Student 1 ($student): Attempt 1 scores 4.00, Attempt 2 scores 2.00
    $s1a1 = (int) AttemptService::start($student, $policyQuiz)['attempt_id'];
    $p1Payload = AttemptService::payload($s1a1, $student);
    $optA = (int) $p1Payload['questions'][0]['options'][0]['id'];
    $optB = (int) $p1Payload['questions'][0]['options'][1]['id'];
    $optC = (int) $p1Payload['questions'][1]['options'][0]['id'];
    AttemptService::submit($s1a1, $student, [
        $pq1 => ['option_ids' => [$optA], 'revision' => 1],
        $pq2 => ['option_ids' => [$optC], 'revision' => 1],
    ]);
    $s1a2 = (int) AttemptService::start($student, $policyQuiz)['attempt_id'];
    AttemptService::submit($s1a2, $student, [
        $pq1 => ['option_ids' => [$optA], 'revision' => 1],
        $pq2 => ['option_ids' => [$optB], 'revision' => 1],
    ]);
    // Student 2 ($racer): Attempt 1 scores 2.00
    $s2a1 = (int) AttemptService::start($racer, $policyQuiz)['attempt_id'];
    AttemptService::submit($s2a1, $racer, [
        $pq1 => ['option_ids' => [$optA], 'revision' => 1],
        $pq2 => ['option_ids' => [$optB], 'revision' => 1],
    ]);
    ReportService::updateRelease($policyQuiz, $teacher, 'release_scores');

    // Default policy is 'highest': student 1 gets 4.00, racer gets 2.00 -> mean = 3.0, median = 3.0, range = 2..4
    $repHighest = ReportService::reportForQuiz($policyQuiz, $teacher);
    $rcptHighest = AttemptService::receipt($s1a2, $student);
    check('highest-score policy picks student\'s 4.00 attempt over later 2.00 attempt in both report and student result',
        $repHighest !== null
        && $repHighest['stats']['mean'] === 3.0
        && $repHighest['stats']['median'] === 3.0
        && $repHighest['stats']['min'] === 2.0
        && $repHighest['stats']['max'] === 4.0
        && $rcptHighest !== null
        && $rcptHighest['score'] === 4.0
        && $rcptHighest['attempt_score'] === 2.0);

    // Switch quiz to 'latest' policy: student 1 now gets 2.00, racer gets 2.00 -> mean = 2.0, median = 2.0, range = 2..2
    $pdo->prepare("UPDATE quizzes SET score_policy = 'latest' WHERE id = ?")->execute([$policyQuiz]);
    $repLatest = ReportService::reportForQuiz($policyQuiz, $teacher);
    $rcptLatest = AttemptService::receipt($s1a2, $student);
    check('latest-score policy picks student\'s latest 2.00 attempt in both report and student result',
        $repLatest !== null
        && $repLatest['stats']['mean'] === 2.0
        && $repLatest['stats']['median'] === 2.0
        && $repLatest['stats']['min'] === 2.0
        && $repLatest['stats']['max'] === 2.0
        && $rcptLatest !== null
        && $rcptLatest['score'] === 2.0);

    // CSV export: verify UTF-8 BOM and formula injection sanitization (=, +, -, @)
    check('sanitizeCsvCell prefixes single quote on =, +, -, @ formula triggers',
        ReportService::sanitizeCsvCell('=cmd|"/c calc"!A0') === "'=cmd|\"/c calc\"!A0"
        && ReportService::sanitizeCsvCell('+SUM(A1:A2)') === "'+SUM(A1:A2)"
        && ReportService::sanitizeCsvCell('-10+20') === "'-10+20"
        && ReportService::sanitizeCsvCell('@AVERAGE(A1)') === "'@AVERAGE(A1)"
        && ReportService::sanitizeCsvCell('  =1+1') === "'  =1+1"
        && ReportService::sanitizeCsvCell('Aguilar, Bea') === 'Aguilar, Bea');

    $pdo->prepare('UPDATE users SET full_name = ? WHERE id = ?')->execute(['=HYPERLINK("http://evil.tld")', $racer]);
    $csvOut = ReportService::exportCsv($policyQuiz, $teacher);
    $pdo->prepare('UPDATE users SET full_name = ? WHERE id = ?')->execute(['Test s3', $racer]);
    check('CSV export starts with UTF-8 BOM and neutralizes formula injection in student records',
        $csvOut !== null
        && str_starts_with($csvOut['content'], "\xEF\xBB\xBF")
        && str_contains($csvOut['content'], "'=HYPERLINK"));

    // ---------------------------------------------------------------- attempt limit
    check('after submitting the only attempt, a new one is refused', AttemptService::start($student, $open)['result'] === 'limit');
    $home = AttemptService::homeForStudent($student);
    check('student home lists the quiz under the class with 1 of 1 used',
        count($home) === 1 && count($home[0]['quizzes']) === 3 && $home[0]['quizzes'][0]['attempts_used'] === 1 && !$home[0]['quizzes'][0]['can_start']);

    // ---------------------------------------------------------------- not open / empty / unpublished
    $later = $mkQuiz($classId, $teacher, 60, 120, 30, 1);
    $addQuestion($later, 1, 'single', 'Q?', null, [['a', true], ['b', false]]);
    check('a scheduled quiz cannot be started yet', AttemptService::start($student, $later)['result'] === 'not_open');

    $closed = $mkQuiz($classId, $teacher, -120, -60, 30, 1);
    $addQuestion($closed, 1, 'single', 'Q?', null, [['a', true], ['b', false]]);
    check('a closed quiz cannot be started', AttemptService::start($student, $closed)['result'] === 'not_open');
    check('a closed quiz with no student attempts is left off student home', count(AttemptService::homeForStudent($student)[0]['quizzes']) === 4);

    $empty = $mkQuiz($classId, $teacher, -60, 60, 30, 1);
    check('a quiz with no questions cannot be started', AttemptService::start($student, $empty)['result'] === 'empty');

    $draft = $mkQuiz($classId, $teacher, -60, 60, 30, 1);
    $pdo->exec("UPDATE quizzes SET status = 'draft' WHERE id = " . $draft);
    check('a draft quiz is invisible to students', AttemptService::startInfo($student, $draft) === null && AttemptService::start($student, $draft)['result'] === 'not_found');

    ClassService::removeStudent($classId, $student);
    $retake = $mkQuiz($classId, $teacher, -60, 60, 30, 3);
    $addQuestion($retake, 1, 'single', 'Q?', null, [['a', true], ['b', false]]);
    check('a removed student cannot start a quiz in that class', AttemptService::start($student, $retake)['result'] === 'not_found');

    // ---------------------------------------------------------------- two tabs press Begin at the same moment
    $helper = __DIR__ . '/helpers/start_attempt.php';
    $procs = [];
    $pipes = [];
    for ($i = 0; $i < 2; $i++) {
        $procs[$i] = proc_open([PHP_BINARY, $helper, (string) $racer, (string) $retake], [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes[$i]);
    }
    $outs = [];
    for ($i = 0; $i < 2; $i++) {
        $outs[$i] = trim((string) stream_get_contents($pipes[$i][1]));
        $err = trim((string) stream_get_contents($pipes[$i][2]));
        fclose($pipes[$i][1]);
        fclose($pipes[$i][2]);
        proc_close($procs[$i]);
        if ($err !== '') {
            echo "        (helper said: $err)\n";
        }
    }
    $results = array_map(static fn($o) => explode(' ', $o)[0] ?? '', $outs);
    sort($results);
    $idsSeen = array_unique(array_map(static fn($o) => explode(' ', $o)[1] ?? '0', $outs));
    $count = (int) $pdo->query('SELECT COUNT(*) FROM quiz_attempts WHERE quiz_id = ' . $retake . ' AND student_id = ' . $racer)->fetchColumn();
    check('two simultaneous Begins create exactly one attempt', $count === 1);
    check('both tabs land on the same attempt (one started, one resumed)', $results === ['resumed', 'started'] && count($idsSeen) === 1);
} finally {
    $in = static fn(array $a): string => $a ? implode(',', array_map('intval', $a)) : '0';
    $u = $in($ids['users']);
    $c = $in($ids['classes']);
    $qz = $in(array_merge($ids['quizzes'], $pdo->query('SELECT id FROM quizzes WHERE class_id IN (' . $c . ')')->fetchAll(PDO::FETCH_COLUMN)));
    $pdo->exec('DELETE FROM grade_events WHERE response_id IN (SELECT id FROM responses WHERE attempt_id IN (SELECT id FROM quiz_attempts WHERE quiz_id IN (' . $qz . ')))');
    $pdo->exec('DELETE FROM response_options WHERE response_id IN (SELECT id FROM responses WHERE attempt_id IN (SELECT id FROM quiz_attempts WHERE quiz_id IN (' . $qz . ')))');
    $pdo->exec('DELETE FROM responses WHERE attempt_id IN (SELECT id FROM quiz_attempts WHERE quiz_id IN (' . $qz . '))');
    $pdo->exec('DELETE FROM quiz_attempts WHERE quiz_id IN (' . $qz . ')');
    $pdo->exec('DELETE FROM quiz_question_options WHERE quiz_question_id IN (SELECT id FROM quiz_questions WHERE quiz_id IN (' . $qz . '))');
    $pdo->exec('DELETE FROM quiz_questions WHERE quiz_id IN (' . $qz . ')');
    $pdo->exec('DELETE FROM quizzes WHERE id IN (' . $qz . ')');
    $pdo->exec('DELETE FROM class_memberships WHERE class_id IN (' . $c . ') OR student_id IN (' . $u . ')');
    $pdo->exec('DELETE FROM classes WHERE id IN (' . $c . ')');
    $pdo->exec('DELETE FROM users WHERE id IN (' . $u . ')');
}

<?php
declare(strict_types=1);

/**
 * Day 18: Live View, Attention Rows, Time Extension & Void Attempt.
 * Loaded by tests/run.php, which provides check(). Everything made here is cleaned up at the end.
 */

$tag = 'live_' . bin2hex(random_bytes(3));
$ids = ['users' => [], 'classes' => [], 'quizzes' => []];
$pdo = db();

$mkUser = static function (string $suffix, string $role) use ($tag, &$ids): int {
    $id = AuthService::createUser($tag . '_' . $suffix, 'Test ' . $suffix, 'Password-' . bin2hex(random_bytes(4)), $role);
    $ids['users'][] = $id;
    return $id;
};

$mkQuiz = static function (int $classId, int $teacherId, string $title, int $opensInMin, int $closesInMin, ?int $durationMin = 30) use ($pdo, &$ids): int {
    $now = utc_now();
    $at  = static fn(int $m): string => gmdate('Y-m-d H:i:s', time() + $m * 60);
    $pdo->prepare(
        "INSERT INTO quizzes (class_id, created_by, title, instructions, status, opens_at, closes_at, duration_min, max_attempts,
                              score_release, review_release, created_at, updated_at, published_at)
         VALUES (?, ?, ?, 'Live view test instructions', 'published', ?, ?, ?, 1, 'manual', 'manual', ?, ?, ?)"
    )->execute([$classId, $teacherId, $title, $at($opensInMin), $at($closesInMin), $durationMin, $now, $now, $now]);
    $id = (int) $pdo->lastInsertId();
    $ids['quizzes'][] = $id;
    return $id;
};

$addQuestion = static function (int $quizId, int $pos, string $type, string $prompt, array $options) use ($pdo): int {
    $pdo->prepare('INSERT INTO quiz_questions (quiz_id, position, type, prompt, points) VALUES (?, ?, ?, ?, 2.00)')
        ->execute([$quizId, $pos, $type, $prompt]);
    $qid = (int) $pdo->lastInsertId();
    foreach ($options as $i => [$label, $correct]) {
        $pdo->prepare('INSERT INTO quiz_question_options (quiz_question_id, label, is_correct, position) VALUES (?, ?, ?, ?)')
            ->execute([$qid, $label, $correct ? 1 : 0, $i + 1]);
    }
    return $qid;
};

try {
    $tLiveA = $mkUser('tla', 'teacher');
    $tLiveB = $mkUser('tlb', 'teacher');
    $sLive1 = $mkUser('sl1', 'student');
    $sLive2 = $mkUser('sl2', 'student');
    $sLive3 = $mkUser('sl3', 'student');
    $sLive4 = $mkUser('sl4', 'student');

    // Create class and enroll students
    $cLive = ClassService::create($tLiveA, 'Live Test Class', 'NET101', 'A', '2026-27 First semester');
    $ids['classes'][] = $cLive;

    ClassService::addByUsername($cLive, $tag . '_sl1');
    ClassService::addByUsername($cLive, $tag . '_sl2');
    ClassService::addByUsername($cLive, $tag . '_sl3');
    ClassService::addByUsername($cLive, $tag . '_sl4');

    // Create an open quiz (opens 10 mins ago, closes in 50 mins, duration 30 mins)
    $qLive = $mkQuiz($cLive, $tLiveA, 'Networking Live Quiz', -10, 50, 30);
    $qQ1   = $addQuestion($qLive, 1, 'single', 'What is IP?', [['Protocol', true], ['Cable', false]]);
    $qQ2   = $addQuestion($qLive, 2, 'single', 'Is TCP reliable?', [['Yes', true], ['No', false]]);

    // 1. Authorization check
    $viewA = LiveService::getLiveView($qLive, $tLiveA);
    check('teacher A can view live view for own quiz', $viewA !== null && $viewA['quiz']['title'] === 'Networking Live Quiz');

    $viewB = LiveService::getLiveView($qLive, $tLiveB);
    check('teacher B receives not found (null) for teacher A quiz', $viewB === null);

    // 2. Setup attempt states
    // Student 1 starts attempt (working, recent heartbeat)
    $start1 = AttemptService::start($sLive1, $qLive);
    $att1Id = (int) $start1['attempt_id'];
    check('student 1 can start attempt', $att1Id > 0);

    // Student 2 starts attempt, but heartbeat is 4 minutes ago (disconnected)
    $start2 = AttemptService::start($sLive2, $qLive);
    $att2Id = (int) $start2['attempt_id'];
    $fourMinAgo = gmdate('Y-m-d H:i:s', time() - 240);
    $pdo->prepare('UPDATE quiz_attempts SET last_seen_at = ?, started_at = ? WHERE id = ?')
        ->execute([$fourMinAgo, $fourMinAgo, $att2Id]);

    // Student 3 starts attempt and submits
    $start3 = AttemptService::start($sLive3, $qLive);
    $att3Id = (int) $start3['attempt_id'];
    AttemptService::submit($att3Id, $sLive3, [$qQ1 => 1]);

    // Student 4 has not started

    // 3. Inspect Live View Attention ordering & Statistics
    $liveData = LiveService::getLiveView($qLive, $tLiveA);
    check('live data returned 4 participants in class roster', count($liveData['participants']) === 4);
    check('hero submitted count is 1', $liveData['hero']['submitted_count'] === 1);
    check('hero working count is 1', $liveData['hero']['working_count'] === 1);
    check('hero disconnected count is 1', $liveData['hero']['disconnected_count'] === 1);
    check('hero not started count is 1', $liveData['hero']['not_started_count'] === 1);
    check('attention banner is active for disconnected student', $liveData['attention_banner']['has_attention'] === true);

    // Verify ordering: attention rank 1 (disconnected) first!
    $firstParticipant = $liveData['participants'][0];
    check('disconnected student is ranked first (attention row first)',
        $firstParticipant['student_id'] === $sLive2 && $firstParticipant['state_code'] === 'disconnected'
    );

    // 4. Time Extension tests
    $oldDeadlineUtc = (string) $pdo->query("SELECT deadline_at FROM quiz_attempts WHERE id = {$att1Id}")->fetchColumn();

    // Teacher B tries to extend Student 1's time (unauthorized)
    $extB = LiveService::extendTime($att1Id, $tLiveB, false, 10, 'Unauthorized extension');
    check('teacher B cannot extend time on teacher A attempt', $extB['result'] === 'not_found');

    // Teacher A extends Student 1's time by 10 minutes
    $extA = LiveService::extendTime($att1Id, $tLiveA, false, 10, 'Student needed extra time for Wi-Fi');
    check('teacher A can extend time for active student', $extA['result'] === 'ok' && $extA['capped'] === false);

    $newDeadlineUtc = (string) $pdo->query("SELECT deadline_at FROM quiz_attempts WHERE id = {$att1Id}")->fetchColumn();
    $diffSec = strtotime($newDeadlineUtc . ' UTC') - strtotime($oldDeadlineUtc . ' UTC');
    check('deadline extended by 600 seconds (10 min)', $diffSec === 600);

    // Verify attempt_events audit record for time extension
    $evt1 = $pdo->prepare("SELECT kind, old_deadline_at, new_deadline_at, note, created_by FROM attempt_events WHERE attempt_id = ? AND kind = 'time_extended'");
    $evt1->execute([$att1Id]);
    $evRow1 = $evt1->fetch();
    check('attempt_events logged time_extended audit row',
        $evRow1 !== false &&
        $evRow1['kind'] === 'time_extended' &&
        (int) $evRow1['created_by'] === $tLiveA &&
        str_contains((string) $evRow1['note'], 'Wi-Fi')
    );

    // Time extension capping at quiz close
    // Quiz closes in 50 minutes. Adding 120 minutes must be capped at quiz.closes_at!
    $quizClosesAt = (string) $pdo->query("SELECT closes_at FROM quizzes WHERE id = {$qLive}")->fetchColumn();
    $extCap = LiveService::extendTime($att1Id, $tLiveA, false, 120, 'Very large extension');
    check('excessive extension is capped at quiz close time',
        $extCap['result'] === 'ok' &&
        $extCap['capped'] === true &&
        $extCap['new_deadline_at'] === $quizClosesAt
    );

    // Cannot extend time on already-submitted attempt
    $extSub = LiveService::extendTime($att3Id, $tLiveA, false, 10);
    check('cannot extend time on submitted attempt', $extSub['result'] === 'not_in_progress');

    // 5. Void Attempt tests
    // Teacher B tries to void Student 2's attempt (unauthorized)
    $voidB = LiveService::voidAttempt($att2Id, $tLiveB, false, 'Unauthorized void');
    check('teacher B cannot void teacher A student attempt', $voidB['result'] === 'not_found');

    // Teacher A voids Student 2's attempt
    $voidA = LiveService::voidAttempt($att2Id, $tLiveA, false, 'Student laptop crashed during sitting');
    check('teacher A can void attempt with reason', $voidA['result'] === 'ok');

    $att2Status = (string) $pdo->query("SELECT status FROM quiz_attempts WHERE id = {$att2Id}")->fetchColumn();
    $att2Reason = (string) $pdo->query("SELECT submit_reason FROM quiz_attempts WHERE id = {$att2Id}")->fetchColumn();
    check('attempt status is voided and submit_reason is teacher', $att2Status === 'voided' && $att2Reason === 'teacher');

    // Verify attempt_events audit record for void
    $evt2 = $pdo->prepare("SELECT kind, note, created_by FROM attempt_events WHERE attempt_id = ? AND kind = 'voided'");
    $evt2->execute([$att2Id]);
    $evRow2 = $evt2->fetch();
    check('attempt_events logged voided audit row',
        $evRow2 !== false &&
        $evRow2['kind'] === 'voided' &&
        (int) $evRow2['created_by'] === $tLiveA &&
        str_contains((string) $evRow2['note'], 'crashed')
    );

    // Cannot void an already voided attempt
    $voidAgain = LiveService::voidAttempt($att2Id, $tLiveA, false, 'Try voiding again');
    check('cannot void an already voided attempt', $voidAgain['result'] === 'already_voided');

    // Verify student 2 can now start a new attempt because voided attempts do not count against max_attempts allowance
    $start2Again = AttemptService::start($sLive2, $qLive);
    check('student can start a new attempt after previous attempt is voided',
        $start2Again['result'] === 'started' && (int) $start2Again['attempt_id'] > 0 && (int) $start2Again['attempt_id'] !== $att2Id
    );

} finally {
    // Clean up all created test data
    foreach ($ids['quizzes'] as $qid) {
        $pdo->prepare('DELETE ae FROM attempt_events ae JOIN quiz_attempts a ON a.id = ae.attempt_id WHERE a.quiz_id = ?')->execute([$qid]);
        $pdo->prepare('DELETE ro FROM response_options ro JOIN responses r ON r.id = ro.response_id JOIN quiz_attempts a ON a.id = r.attempt_id WHERE a.quiz_id = ?')->execute([$qid]);
        $pdo->prepare('DELETE r FROM responses r JOIN quiz_attempts a ON a.id = r.attempt_id WHERE a.quiz_id = ?')->execute([$qid]);
        $pdo->prepare('DELETE FROM quiz_attempts WHERE quiz_id = ?')->execute([$qid]);
        $pdo->prepare('DELETE qqo FROM quiz_question_options qqo JOIN quiz_questions qq ON qq.id = qqo.quiz_question_id WHERE qq.quiz_id = ?')->execute([$qid]);
        $pdo->prepare('DELETE FROM quiz_questions WHERE quiz_id = ?')->execute([$qid]);
        $pdo->prepare('DELETE FROM quizzes WHERE id = ?')->execute([$qid]);
    }
    foreach ($ids['classes'] as $cid) {
        $pdo->prepare('DELETE FROM class_memberships WHERE class_id = ?')->execute([$cid]);
        $pdo->prepare('DELETE FROM classes WHERE id = ?')->execute([$cid]);
    }
    foreach ($ids['users'] as $uid) {
        $pdo->prepare('DELETE FROM users WHERE id = ?')->execute([$uid]);
    }
}

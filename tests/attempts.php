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

    // ---------------------------------------------------------------- attempt limit
    $pdo->exec("UPDATE quiz_attempts SET status = 'submitted', submit_reason = 'student', submitted_at = '" . utc_now() . "' WHERE id = " . (int) $first['attempt_id']);
    check('after submitting the only attempt, a new one is refused', AttemptService::start($student, $open)['result'] === 'limit');
    $home = AttemptService::homeForStudent($student);
    check('student home lists the quiz under the class with 1 of 1 used',
        count($home) === 1 && count($home[0]['quizzes']) === 1 && $home[0]['quizzes'][0]['attempts_used'] === 1 && !$home[0]['quizzes'][0]['can_start']);

    // ---------------------------------------------------------------- not open / empty / unpublished
    $later = $mkQuiz($classId, $teacher, 60, 120, 30, 1);
    $addQuestion($later, 1, 'single', 'Q?', null, [['a', true], ['b', false]]);
    check('a scheduled quiz cannot be started yet', AttemptService::start($student, $later)['result'] === 'not_open');

    $closed = $mkQuiz($classId, $teacher, -120, -60, 30, 1);
    $addQuestion($closed, 1, 'single', 'Q?', null, [['a', true], ['b', false]]);
    check('a closed quiz cannot be started', AttemptService::start($student, $closed)['result'] === 'not_open');
    check('a closed quiz is left off student home', count(AttemptService::homeForStudent($student)[0]['quizzes']) === 2);

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

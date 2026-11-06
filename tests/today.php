<?php
declare(strict_types=1);

/**
 * Day 17: TodayService & rank_today (all 6 queries + teacher scoping + max 5 cap).
 * Loaded by tests/run.php, which provides check(). Everything made here is removed at the end.
 */

$tag = 'tod_' . bin2hex(random_bytes(3));
$ids = ['users' => [], 'classes' => [], 'quizzes' => []];
$pdo = db();

$mkUser = static function (string $suffix, string $role) use ($tag, &$ids): int {
    $id = AuthService::createUser($tag . '_' . $suffix, 'Test ' . $suffix, 'Password-' . bin2hex(random_bytes(4)), $role);
    $ids['users'][] = $id;
    return $id;
};

$mkQuiz = static function (int $classId, int $teacherId, string $title, int $opensIn, int $closesIn, ?int $duration = 30) use ($pdo, &$ids): int {
    $now = utc_now();
    $at  = static fn(int $m): string => gmdate('Y-m-d H:i:s', time() + $m * 60);
    $pdo->prepare(
        "INSERT INTO quizzes (class_id, created_by, title, instructions, status, opens_at, closes_at, duration_min, max_attempts,
                              score_release, review_release, created_at, updated_at, published_at)
         VALUES (?, ?, ?, 'Choose the best answer.', 'published', ?, ?, ?, 1, 'manual', 'manual', ?, ?, ?)"
    )->execute([$classId, $teacherId, $title, $at($opensIn), $at($closesIn), $duration, $now, $now, $now]);
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
    $tToday  = $mkUser('ttoday', 'teacher');
    $tTodayB = $mkUser('ttodayb', 'teacher');
    $sToday1 = $mkUser('st1', 'student');
    $sToday2 = $mkUser('st2', 'student');

    $cToday = ClassService::create($tToday, 'Today Class', 'IT321', 'B', '2026-27 First semester');
    $ids['classes'][] = $cToday;
    ClassService::addByUsername($cToday, $tag . '_st1');
    ClassService::addByUsername($cToday, $tag . '_st2');

    // Calm empty state when teacher has no actionable items
    $calmB = TodayService::overviewForTeacher($tTodayB);
    check('TodayService returns empty today_items for a teacher with no classes',
        $calmB['today_items'] === [] && $calmB['hero_item'] === null && $calmB['summary']['classes_count'] === 0);

    // Create all 6 item categories for $tToday:
    // 1) Closing soon: open quiz closing in 40 minutes
    $qzClosing = $mkQuiz($cToday, $tToday, 'Networking Quiz', -20, 40, 60);
    $addQuestion($qzClosing, 1, 'single', 'Closing soon Q1', [['A', true], ['B', false]]);

    // 2) Disconnected: $sToday1 starts $qzClosing and their last_seen_at was 4 minutes ago
    $discStart = AttemptService::start($sToday1, $qzClosing);
    $discAttId = (int) $discStart['attempt_id'];
    $fourMinAgo = gmdate('Y-m-d H:i:s', time() - 240);
    $pdo->prepare('UPDATE quiz_attempts SET last_seen_at = ? WHERE id = ?')->execute([$fourMinAgo, $discAttId]);

    // Meanwhile $sToday2 submits $qzClosing so we have 1 submitted, 1 disconnected in the hero grid
    $s2Start = AttemptService::start($sToday2, $qzClosing);
    AttemptService::submit((int) $s2Start['attempt_id'], $sToday2, []);

    // 3) Needs grading: quiz with an ungraded non-blank written response (closes in 180m so it is NOT closing_soon)
    $qzGrading = $mkQuiz($cToday, $tToday, 'Databases Midterm', -120, 180, 30);
    $qWrittenId = $addQuestion($qzGrading, 1, 'essay', 'Explain ACID properties.', []);
    $gradStart = AttemptService::start($sToday1, $qzGrading);
    AttemptService::submit((int) $gradStart['attempt_id'], $sToday1, [
        $qWrittenId => ['answer_text' => 'Atomicity, Consistency, Isolation, Durability.', 'revision' => 1],
    ]);

    // 4) Ready to release: quiz where all submitted attempts are graded, but scores/reviews are still private (manual)
    $qzReady = $mkQuiz($cToday, $tToday, 'Web Dev Quiz 2', -120, 180, 30);
    $addQuestion($qzReady, 1, 'single', 'Ready Q1', [['Yes', true], ['No', false]]);
    $readyStart = AttemptService::start($sToday1, $qzReady);
    AttemptService::submit((int) $readyStart['attempt_id'], $sToday1, []);

    // 5) Opens soon: published scheduled quiz opening in 6 hours
    $qzOpensSoon = $mkQuiz($cToday, $tToday, 'Systems Analysis Quiz', 360, 420, 45);
    $addQuestion($qzOpensSoon, 1, 'single', 'Opens soon Q1', [['A', true], ['B', false]]);

    // 6) Incomplete draft: draft quiz created for $cToday
    $qzDraftToday = QuizService::createDraft($tToday, $cToday, 'Incomplete Draft Quiz');
    $ids['quizzes'][] = $qzDraftToday;

    // Fetch with limit=5 (default): should return the top 5 in exact priority order (dropping #6 incomplete_draft)
    $ov5 = TodayService::overviewForTeacher($tToday);
    $types5 = array_column($ov5['today_items'], 'type');
    check('TodayService returns max 5 items in strict priority order (closing_soon, disconnected, needs_grading, ready_to_release, opens_soon)',
        count($ov5['today_items']) === 5
        && $types5 === ['closing_soon', 'disconnected', 'needs_grading', 'ready_to_release', 'opens_soon']
        && $ov5['hero_item'] !== null
        && $ov5['hero_item']['type'] === 'closing_soon'
        && count($ov5['also_today']) === 4);

    // Fetch with limit=6: confirms the 6th query (incomplete_draft) is also returned in 6th place
    $ov6 = TodayService::overviewForTeacher($tToday, null, 6);
    $types6 = array_column($ov6['today_items'], 'type');
    check('TodayService 6th query surfaces incomplete_draft right after opens_soon when limit allows or higher items clear',
        count($ov6['today_items']) === 6
        && $types6[5] === 'incomplete_draft');

    // Verify Teacher B cannot see any of Teacher A's Today items
    $ovBAfter = TodayService::overviewForTeacher($tTodayB);
    check('Teacher B sees none of Teacher A\'s Today rail items or summary counts',
        $ovBAfter['today_items'] === []
        && $ovBAfter['this_week'] === []
        && $ovBAfter['summary']['classes_count'] === 0);
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

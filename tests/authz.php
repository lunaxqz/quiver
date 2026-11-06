<?php
declare(strict_types=1);

/**
 * Authorization and system rules integration check.
 * Run with: php tests/run.php
 */

require_once __DIR__ . '/../app/bootstrap.php';

if (!function_exists('check')) {
    function check(string $label, bool $ok): void
    {
        echo ($ok ? '  [PASS] ' : '  [FAIL] ') . $label . "\n";
        if (!$ok) {
            throw new RuntimeException("Check failed: {$label}");
        }
    }
}

$pdo = db();
$ids = ['users' => [], 'classes' => [], 'quizzes' => []];

try {
    // Setup test users
    $now = utc_now();
    $tag = 'authz_' . bin2hex(random_bytes(3));
    $mk = static function (string $suffix, string $role) use ($tag, &$ids): array {
        $id = AuthService::createUser($tag . '_' . $suffix, 'Test ' . $suffix, 'Password-' . bin2hex(random_bytes(4)), $role);
        $ids['users'][] = $id;
        return ['id' => $id];
    };

    $teacherA = $mk('ta', 'teacher');
    $teacherB = $mk('tb', 'teacher');
    $student1 = $mk('s1', 'student');

    // Setup classes
    $classA = ClassService::create($teacherA['id'], 'Networking', 'IT301', 'Sec A', '2026-2027');
    $ids['classes'][] = $classA;

    $classB = ClassService::create($teacherB['id'], 'Databases', 'CS201', 'Sec B', '2026-2027');
    $ids['classes'][] = $classB;

    // --- Quizzes & Drafts ---
    $quizA = QuizService::createDraft($teacherA['id'], $classA, 'Draft Quiz A');
    $ids['quizzes'][] = $quizA;
    check('teacher A can create draft quiz', $quizA > 0);

    $threw = false;
    try {
        QuizService::createDraft($teacherB['id'], $classA, 'Hijack Quiz');
    } catch (Throwable $e) {
        $threw = true;
    }
    check('teacher B cannot create a quiz in teacher A\'s class', $threw);

    $listB = QuizService::listForTeacher($teacherB['id']);
    check('teacher B\'s quiz list does not include teacher A\'s quiz', count($listB['items']) === 0);

    // Settings update & Timezone test: 08:00 Manila = 00:00 UTC
    QuizService::saveSettings($quizA, $teacherA['id'], [
        'title' => 'Updated Quiz A',
        'class_id' => $classA,
        'instructions' => 'Read carefully.',
        'opens_at_manila' => '2026-11-09T08:00',
        'closes_at_manila' => '2026-11-09T09:00',
        'duration_min' => 50,
        'max_attempts' => 2,
        'score_policy' => 'latest',
        'hold_score' => true,
        'hold_review' => true,
    ]);

    $saved = QuizService::find($quizA, $teacherA['id']);
    check('teacher A can update draft quiz settings', $saved['title'] === 'Updated Quiz A' && (int) $saved['duration_min'] === 50 && (int) $saved['max_attempts'] === 2 && $saved['score_policy'] === 'latest');
    check('dates are stored in UTC: 08:00 Manila is stored as 00:00 UTC', $saved['opens_at'] === '2026-11-09 00:00:00');
    check('window is displayed in Manila time matching Figma', $saved['window_formatted'] === 'Mon 09 Nov · 08:00–09:00');

    // Archive and restore
    QuizService::setArchived($quizA, $teacherA['id'], true);
    check('archived quiz leaves active draft list', QuizService::countForTeacher($teacherA['id'], 'all') === 0 && QuizService::countForTeacher($teacherA['id'], 'archived') === 1);
    QuizService::setArchived($quizA, $teacherA['id'], false);
    check('restored quiz returns to active list', QuizService::countForTeacher($teacherA['id'], 'all') === 1);

    // --- Day 10: Copy-on-add, Blockers, Publish & Freeze, Duplication ---

    // 1. Author bank questions
    $insQ = $pdo->prepare('INSERT INTO questions (teacher_id, type, prompt, points, rubric, created_at, updated_at) VALUES (?, ?, ?, ?, ?, ?, ?)');
    $insQ->execute([$teacherA['id'], 'single', 'What is default gateway?', 2.00, 'Check routing concept', $now, $now]);
    $bankQ1 = (int) $pdo->lastInsertId();

    $insOpt = $pdo->prepare('INSERT INTO question_options (question_id, label, is_correct, position) VALUES (?, ?, ?, ?)');
    $insOpt->execute([$bankQ1, 'Router IP address', 1, 1]);
    $insOpt->execute([$bankQ1, 'Switch MAC address', 0, 2]);

    $insQ->execute([$teacherA['id'], 'true_false', 'TCP is connection-oriented.', 2.00, null, $now, $now]);
    $bankQ2 = (int) $pdo->lastInsertId();
    $insOpt->execute([$bankQ2, 'True', 1, 1]);
    $insOpt->execute([$bankQ2, 'False', 0, 2]);

    // 2. Add bank questions to draft
    $addedCount = QuizService::addBankQuestions($quizA, $teacherA['id'], [$bankQ1, $bankQ2]);
    check('copy-on-add successfully copies bank questions into quiz_questions', $addedCount === 2);

    $copiedQs = QuizService::getQuestions($quizA);
    check('quiz questions count matches added questions', count($copiedQs) === 2);
    check('first copied question retains options and correct key', count($copiedQs[0]['options']) === 2 && (int) $copiedQs[0]['options'][0]['is_correct'] === 1);

    // 3. Mutate bank question and verify quiz copy remains isolated
    $pdo->prepare('UPDATE questions SET prompt = "ALTERED BANK PROMPT", points = 99.00 WHERE id = ?')->execute([$bankQ1]);
    $freshCopied = QuizService::getQuestions($quizA);
    check('mutating bank question does NOT alter quiz copy', $freshCopied[0]['prompt'] === 'What is default gateway?' && (float) $freshCopied[0]['points'] === 2.00);

    // 4. Inline points editing & Reordering
    QuizService::updateQuestionPoints($quizA, $teacherA['id'], (int) $freshCopied[0]['id'], 3.50);
    $freshCopied = QuizService::getQuestions($quizA);
    check('inline points editing updates quiz question copy points', (float) $freshCopied[0]['points'] === 3.50);

    QuizService::moveQuestion($quizA, $teacherA['id'], (int) $freshCopied[0]['id'], 'down');
    $reordered = QuizService::getQuestions($quizA);
    check('moving question down swaps positions correctly', (int) $reordered[0]['id'] === (int) $freshCopied[1]['id']);

    // 5. Blockers and Publish
    $blockers = QuizService::getBlockers($quizA, $teacherA['id']);
    check('fully configured quiz draft has 0 blocking issues', empty($blockers));

    $pubResult = QuizService::publish($quizA, $teacherA['id']);
    check('publishing quiz succeeds when blockers list is empty', $pubResult['success'] === true);

    $publishedQuiz = QuizService::find($quizA, $teacherA['id']);
    check('published quiz status is published and published_at is stamped', $publishedQuiz['status'] === 'published' && $publishedQuiz['published_at'] !== null);

    // 6. Freeze checks: every write path refuses changes to published quiz
    $settingsThrew = false;
    try {
        QuizService::saveSettings($quizA, $teacherA['id'], ['title' => 'Hacked Title']);
    } catch (Throwable $e) {
        $settingsThrew = true;
    }
    check('saving settings on published quiz is refused (frozen)', $settingsThrew);

    $addThrew = false;
    try {
        QuizService::addBankQuestions($quizA, $teacherA['id'], [$bankQ1]);
    } catch (Throwable $e) {
        $addThrew = true;
    }
    check('adding questions to published quiz is refused (frozen)', $addThrew);

    $ptsThrew = false;
    try {
        QuizService::updateQuestionPoints($quizA, $teacherA['id'], (int) $freshCopied[0]['id'], 10.0);
    } catch (Throwable $e) {
        $ptsThrew = true;
    }
    check('updating points on published quiz is refused (frozen)', $ptsThrew);

    // 7. Duplicate as new version
    $v2Id = QuizService::duplicateAsVersion($quizA, $teacherA['id']);
    $ids['quizzes'][] = $v2Id;
    check('duplicate as new version creates a new quiz', $v2Id > 0 && $v2Id !== $quizA);

    $v2 = QuizService::find($v2Id, $teacherA['id']);
    check('duplicated quiz is in draft status with incremented version', $v2['status'] === 'draft' && $v2['version'] === 2);
    check('duplicated quiz inherits copies of questions', count(QuizService::getQuestions($v2Id)) === 2);

    echo "\nAll authorization & Day 10 quiz builder tests passed!\n";
} finally {
    // Safe reverse order cleanup
    $pdo = db();
    $in  = static fn(array $a): string => $a ? implode(',', array_map('intval', $a)) : '0';
    $u = $in($ids['users']);

    foreach ($ids['users'] as $uid) {
        $pdo->exec("DELETE FROM login_attempts WHERE identifier = 'student:" . (int) $uid . "'");
    }

    $cs = $pdo->query('SELECT id FROM classes WHERE teacher_id IN (' . $u . ')')->fetchAll(PDO::FETCH_COLUMN);
    $c  = $in($cs);

    $quizIds = $pdo->query('SELECT id FROM quizzes WHERE created_by IN (' . $u . ') OR class_id IN (' . $c . ')')->fetchAll(PDO::FETCH_COLUMN);
    $qz = $in($quizIds);

    if ($qz !== '0') {
        $qqIds = $pdo->query('SELECT id FROM quiz_questions WHERE quiz_id IN (' . $qz . ')')->fetchAll(PDO::FETCH_COLUMN);
        $qq = $in($qqIds);
        if ($qq !== '0') {
            $pdo->exec('DELETE FROM quiz_question_options WHERE quiz_question_id IN (' . $qq . ')');
            $pdo->exec('DELETE FROM quiz_questions WHERE quiz_id IN (' . $qz . ')');
        }
        $pdo->exec('UPDATE quizzes SET previous_version_id = NULL WHERE id IN (' . $qz . ')');
        $pdo->exec('DELETE FROM quizzes WHERE id IN (' . $qz . ')');
    }

    $qs = $pdo->query('SELECT id FROM questions WHERE teacher_id IN (' . $u . ')')->fetchAll(PDO::FETCH_COLUMN);
    $q  = $in($qs);
    if ($q !== '0') {
        $pdo->exec('DELETE FROM question_options WHERE question_id IN (' . $q . ')');
        $pdo->exec('DELETE FROM question_tags WHERE question_id IN (' . $q . ')');
        $pdo->exec('DELETE FROM questions WHERE id IN (' . $q . ')');
    }

    if ($c !== '0') {
        $pdo->exec('DELETE FROM class_memberships WHERE class_id IN (' . $c . ') OR student_id IN (' . $u . ')');
        $pdo->exec('DELETE FROM classes WHERE id IN (' . $c . ')');
    }

    if ($u !== '0') {
        $pdo->exec('DELETE FROM users WHERE id IN (' . $u . ')');
    }
}

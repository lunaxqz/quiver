<?php
declare(strict_types=1);

/**
 * Permission checks for everything built so far (Days 2 to 8).
 * Loaded by tests/run.php, which provides check(). Every account and row made here is removed at the end.
 */

$tag = 'authz_' . bin2hex(random_bytes(3));
$ids = ['users' => [], 'classes' => [], 'questions' => []];

$mk = static function (string $suffix, string $role) use ($tag, &$ids): array {
    $id = AuthService::createUser($tag . '_' . $suffix, 'Test ' . $suffix, 'Password-' . bin2hex(random_bytes(4)), $role);
    $ids['users'][] = $id;
    return ['id' => $id, 'role' => $role, 'username' => $tag . '_' . $suffix];
};

try {
    $admin    = $mk('admin', 'admin');
    $teacherA = $mk('ta', 'teacher');
    $teacherB = $mk('tb', 'teacher');
    $student1 = $mk('s1', 'student');
    $student2 = $mk('s2', 'student');
    $student3 = $mk('s3', 'student');

    // ---------------------------------------------------------------- classes
    $classA = ClassService::create($teacherA['id'], 'Class of A', 'AZ1', 'A', '2026–27 · First semester');
    $classB = ClassService::create($teacherB['id'], 'Class of B', 'BZ1', 'B', '2026–27 · First semester');
    $ids['classes'] = [$classA, $classB];

    check('teacher A can open their own class', ClassService::findOwned($classA, $teacherA) !== null);
    check('teacher B cannot open teacher A\'s class', ClassService::findOwned($classA, $teacherB) === null);
    check('teacher A cannot open teacher B\'s class', ClassService::findOwned($classB, $teacherA) === null);
    check('administrator can open any class', ClassService::findOwned($classA, $admin) !== null && ClassService::findOwned($classB, $admin) !== null);
    check('a missing class id is "not found" for everyone', ClassService::findOwned(0, $teacherA) === null);

    $listB = array_map(static fn($c) => (int) $c['id'], ClassService::listForTeacher($teacherB['id']));
    check('teacher B\'s class list contains only their own class', $listB === [$classB]);

    // ---------------------------------------------------------------- joining
    $codeA = (string) ClassService::findOwned($classA, $teacherA)['join_code'];
    check('join codes use only the unambiguous alphabet', (bool) preg_match('/^[ABCDEFGHJKLMNPQRSTUVWXYZ23456789]{6}$/', $codeA));
    check('a student joins with a valid code (hyphen and case do not matter)',
        EnrollmentService::tryJoin($student1['id'], strtolower(substr($codeA, 0, 3) . '-' . substr($codeA, 3))) === 'joined');
    check('the joined student is on the roster', ClassService::rosterCount($classA) === 1);

    ClassService::setCodeEnabled($classA, false);
    check('a disabled code does not work', EnrollmentService::tryJoin($student2['id'], $codeA) === 'invalid');
    ClassService::setCodeEnabled($classA, true);

    ClassService::regenerateCode($classA);
    check('a regenerated code invalidates the old one', EnrollmentService::tryJoin($student2['id'], $codeA) === 'invalid');
    $codeA = (string) ClassService::findOwned($classA, $teacherA)['join_code'];
    check('enrolled students keep access after the code changes', ClassService::rosterCount($classA) === 1);

    // Rate limit: student 3 makes 5 wrong guesses, then even the right code is refused.
    for ($i = 0; $i < EnrollmentService::MAX_FAILURES; $i++) {
        EnrollmentService::tryJoin($student3['id'], 'WRONG' . $i);
    }
    check('the 6th attempt after 5 wrong codes is blocked, even with the right code',
        EnrollmentService::tryJoin($student3['id'], $codeA) === 'limited');
    check('a blocked student is not enrolled', ClassService::rosterCount($classA) === 1);

    // ---------------------------------------------------------------- roster
    check('adding a teacher\'s username as a student is refused', ClassService::addByUsername($classA, $teacherB['username']) === 'not_found');
    check('adding a student by username works', ClassService::addByUsername($classA, $student2['username']) === 'added');
    check('adding the same student twice is reported', ClassService::addByUsername($classA, $student2['username']) === 'already');
    check('a temporary password is refused for a student not in the class', ClassService::issueTemporaryPassword($classB, $student1['id']) === null);
    check('a temporary password works for a student in the class', ClassService::issueTemporaryPassword($classA, $student1['id']) !== null);
    ClassService::removeStudent($classA, $student2['id']);
    check('a removed student leaves the roster', ClassService::rosterCount($classA) === 1);

    // ---------------------------------------------------------------- archive
    ClassService::archive($classB);
    check('an archived class refuses new joins', EnrollmentService::tryJoin($student2['id'], (string) ClassService::findOwned($classB, $admin)['join_code']) === 'invalid');
    check('an archived class leaves the teacher\'s list', ClassService::listForTeacher($teacherB['id']) === []);
    check('an archived class is still kept (records retained)', ClassService::findOwned($classB, $teacherB) !== null);

    // ---------------------------------------------------------------- question bank
    $base = ['tf' => '', 'accepted' => [], 'rubric' => '', 'tags' => ['authz'], 'options' => []];
    $opts = static fn(array $labels, array $correct): array => array_map(
        static fn($l, $i) => ['label' => $l, 'correct' => in_array($i, $correct, true)], $labels, array_keys($labels)
    );
    $qA = QuestionService::save($teacherA['id'], null, array_merge($base, ['type' => 'single', 'prompt' => 'Question of A?', 'points' => '1.00', 'options' => $opts(['x', 'y'], [0])]));
    $ids['questions'][] = $qA;

    check('teacher A can open their own question', QuestionService::find($qA, $teacherA['id']) !== null);
    check('teacher B cannot open teacher A\'s question', QuestionService::find($qA, $teacherB['id']) === null);
    check('teacher B\'s bank does not list teacher A\'s question', QuestionService::count($teacherB['id']) === 0);
    check('teacher B\'s search does not find teacher A\'s question', QuestionService::count($teacherB['id'], 'Question of A') === 0);

    $threw = false;
    try {
        QuestionService::save($teacherB['id'], $qA, array_merge($base, ['type' => 'single', 'prompt' => 'Hijacked', 'points' => '1.00', 'options' => $opts(['p', 'q'], [0])]));
    } catch (Throwable $e) {
        $threw = true;
    }
    $after = QuestionService::find($qA, $teacherA['id']);
    check('teacher B cannot overwrite teacher A\'s question', $threw && $after !== null && $after['prompt'] === 'Question of A?');

    QuestionService::setArchived($qA, $teacherB['id'], true);
    check('teacher B cannot archive teacher A\'s question', QuestionService::find($qA, $teacherA['id'])['archived_at'] === null);
    QuestionService::setArchived($qA, $teacherA['id'], true);
    check('teacher A can archive their own question', QuestionService::count($teacherA['id']) === 0 && QuestionService::count($teacherA['id'], '', '', '', true) === 1);
    QuestionService::setArchived($qA, $teacherA['id'], false);

    // All five types save and load back correctly.
    $short = QuestionService::save($teacherA['id'], null, array_merge($base, ['type' => 'short', 'prompt' => 'Short?', 'points' => '1.00', 'accepted' => ['Domain Name System', 'DNS']]));
    $essay = QuestionService::save($teacherA['id'], null, array_merge($base, ['type' => 'essay', 'prompt' => 'Essay?', 'points' => '5.00', 'rubric' => 'Mentions normal forms.']));
    $multi = QuestionService::save($teacherA['id'], null, array_merge($base, ['type' => 'multi', 'prompt' => 'Multi?', 'points' => '2.00', 'options' => $opts(['a', 'b', 'c'], [0, 2])]));
    $tf    = QuestionService::save($teacherA['id'], null, array_merge($base, ['type' => 'true_false', 'prompt' => 'TF?', 'points' => '1.00', 'tf' => 'false']));
    array_push($ids['questions'], $short, $essay, $multi, $tf);
    check('short answer keeps its accepted answers', count(QuestionService::find($short, $teacherA['id'])['options']) === 2);
    check('essay keeps its rubric', QuestionService::find($essay, $teacherA['id'])['rubric'] === 'Mentions normal forms.');
    check('multi-select keeps two correct keys', count(array_filter(QuestionService::find($multi, $teacherA['id'])['options'], static fn($o) => (int) $o['is_correct'] === 1)) === 2);
    check('true/false stores exactly two options', count(QuestionService::find($tf, $teacherA['id'])['options']) === 2);

    // Validation
    check('single choice with no correct key is rejected', isset(QuestionService::validate(array_merge($base, ['type' => 'single', 'prompt' => 'P', 'points' => '1', 'options' => $opts(['a', 'b'], [])]))['correct']));
    check('short answer with no accepted answer is rejected', isset(QuestionService::validate(array_merge($base, ['type' => 'short', 'prompt' => 'P', 'points' => '1']))['accepted']));
    check('a valid essay passes validation', QuestionService::validate(array_merge($base, ['type' => 'essay', 'prompt' => 'P', 'points' => '5'])) === []);
    check('points above 100 are rejected', isset(QuestionService::validate(array_merge($base, ['type' => 'essay', 'prompt' => 'P', 'points' => '101']))['points']));
} finally {
    // Remove everything this file created, children before parents.
    $pdo = db();
    $in  = static fn(array $a): string => $a ? implode(',', array_map('intval', $a)) : '0';
    $u = $in($ids['users']);
    foreach ($ids['users'] as $uid) {
        $pdo->exec("DELETE FROM login_attempts WHERE identifier = 'student:" . (int) $uid . "'");
    }
    $qs = $pdo->query('SELECT id FROM questions WHERE teacher_id IN (' . $u . ')')->fetchAll(PDO::FETCH_COLUMN);
    $q  = $in($qs);
    $pdo->exec('DELETE FROM question_options WHERE question_id IN (' . $q . ')');
    $pdo->exec('DELETE FROM question_tags WHERE question_id IN (' . $q . ')');
    $pdo->exec('DELETE FROM questions WHERE id IN (' . $q . ')');
    $cs = $pdo->query('SELECT id FROM classes WHERE teacher_id IN (' . $u . ')')->fetchAll(PDO::FETCH_COLUMN);
    $c  = $in($cs);
    $pdo->exec('DELETE FROM class_memberships WHERE class_id IN (' . $c . ') OR student_id IN (' . $u . ')');
    $pdo->exec('DELETE FROM classes WHERE id IN (' . $c . ')');
    $pdo->exec('DELETE FROM users WHERE id IN (' . $u . ')');
}

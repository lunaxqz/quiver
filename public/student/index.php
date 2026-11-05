<?php
declare(strict_types=1);

require_once __DIR__ . '/../../app/bootstrap.php';
require_once __DIR__ . '/../../app/helpers/layout.php';

$user = require_role('student');
$joinError = '';
$joinValue = '';

if (($_SERVER['REQUEST_METHOD'] ?? '') === 'POST') {
    csrf_verify();
    $joinValue = post_str('join_code');
    if (mb_strlen($joinValue) > 12) {
        $joinError = 'That code is too long. Check it with your teacher.';
    } elseif ($joinValue === '') {
        $joinError = 'Enter the class code from your teacher.';
    } else {
        $result = EnrollmentService::tryJoin((int) $user['id'], $joinValue);
        if ($result === 'joined') {
            flash_set('You have joined your class.');
            redirect('/student/index.php');
        }
        $joinError = $result === 'limited'
            ? 'Too many wrong codes. Wait 15 minutes, then try again, or ask your teacher.'
            : 'That code did not work. Check it with your teacher.';
    }
}

$flash   = flash_get();
$classes = ClassService::listForStudent((int) $user['id']);

student_start('Your classes');
if ($flash !== null) {
    echo '<div class="alert alert--' . ($flash['type'] === 'error' ? 'error' : 'notice') . '" role="status">' . e($flash['message']) . '</div>';
}

if (!$classes) {
    echo state_empty('No classes yet', 'Your classes and open quizzes will appear here. Ask your teacher for a class code.');
} else {
    echo '<div class="sheet"><div><h2>Classes</h2><ul>';
    foreach ($classes as $c) {
        $label = trim(($c['course_code'] ?? '') . ($c['section'] ? ' · Section ' . $c['section'] : ''), ' ·');
        echo '<li>' . e($c['name']) . ($label !== '' ? ' · ' . e($label) : '') . ' · ' . e($c['owner_name']) . '</li>';
    }
    echo '</ul></div></div>';
}
?>
<h2 class="section-title">Join a class</h2>
<form method="post" action="/student/index.php">
  <?= csrf_field() ?>
  <?= field('join_code', 'Class join code', 'text', ['value' => $joinValue, 'error' => $joinError, 'hint' => 'Letters and numbers from your teacher. A hyphen is fine.', 'maxlength' => 12, 'required' => true, 'nocaps' => true]) ?>
  <button class="btn btn--primary" type="submit">Join class</button>
</form>
<?php student_end();

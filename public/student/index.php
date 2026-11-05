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

$home = AttemptService::homeForStudent((int) $user['id']);

student_start('Your classes', '', false, ['/assets/css/student.css']);

if (!$home) {
    echo state_empty('No classes yet', 'Your classes and open quizzes will appear here. Ask your teacher for a class code.');
} else {
    foreach ($home as $group) {
        $c = $group['class'];
        $label = implode(' · ', array_filter([
            (string) ($c['course_code'] ?? ''),
            ($c['section'] ?? '') !== '' ? 'Section ' . $c['section'] : '',
            (string) $c['owner_name'],
        ]));
        echo '<section class="q-card h-class"><div class="h-class__head"><h2>' . e($c['name']) . '</h2>'
           . '<span class="q-meta">' . e($label) . '</span></div>';

        if (!$group['quizzes']) {
            echo '<p class="h-none">No open quizzes right now.</p>';
        } else {
            echo '<ul class="h-quizzes">';
            foreach ($group['quizzes'] as $q) {
                $max = (int) $q['max_attempts'];
                if ($q['running_id'] !== null) {
                    $mark = status_mark('attention', 'In progress');
                    $meta = 'Your attempt ends ' . to_manila($q['running_deadline'], 'j M, H:i') . ' Asia/Manila';
                    $btn  = '<a class="btn btn--primary btn--pill" href="/student/quiz.php?id=' . (int) $q['id'] . '">Resume &rarr;</a>';
                } elseif ($q['phase'] === 'scheduled') {
                    $mark = status_mark('scheduled', 'Scheduled');
                    $meta = 'Opens ' . to_manila($q['opens_at'], 'j M, H:i') . ' · closes ' . to_manila($q['closes_at'], 'j M, H:i') . ' Asia/Manila';
                    $btn  = '<a class="btn btn--pill" href="/student/quiz.php?id=' . (int) $q['id'] . '">Details</a>';
                } elseif ($q['can_start']) {
                    $mark = status_mark('active', 'Open');
                    $meta = 'Closes ' . to_manila($q['closes_at'], 'j M, H:i') . ' Asia/Manila'
                          . ($q['duration_min'] !== null ? ' · ' . (int) $q['duration_min'] . ' minutes' : '')
                          . ' · ' . (int) $q['attempts_used'] . ' of ' . $max . ' attempts used';
                    $btn  = '<a class="btn btn--primary btn--pill" href="/student/quiz.php?id=' . (int) $q['id'] . '">Start &rarr;</a>';
                } else {
                    $mark = status_mark('complete', 'Submitted');
                    $meta = 'All ' . $max . ' ' . ($max === 1 ? 'attempt' : 'attempts') . ' used';
                    $btn  = '';
                }
                echo '<li class="h-quiz"><div><div class="h-quiz__title">' . e($q['title']) . '</div>'
                   . '<div class="h-quiz__meta">' . $mark . ' · ' . e($meta) . '</div></div>' . $btn . '</li>';
            }
            echo '</ul>';
        }
        echo '</section>';
    }
}
?>
<h2 class="section-title">Join a class</h2>
<form method="post" action="/student/index.php">
  <?= csrf_field() ?>
  <?= field('join_code', 'Class join code', 'text', ['value' => $joinValue, 'error' => $joinError, 'hint' => 'Letters and numbers from your teacher. A hyphen is fine.', 'maxlength' => 12, 'required' => true, 'nocaps' => true]) ?>
  <button class="btn btn--primary" type="submit">Join class</button>
</form>
<?php student_end();

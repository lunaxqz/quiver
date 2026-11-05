<?php
declare(strict_types=1);

/**
 * Student quiz start screen (Figma: Student quiz start).
 * GET shows the rules and the exact end time. POST "Begin quiz" calls AttemptService::start().
 */

require_once __DIR__ . '/../../app/bootstrap.php';
require_once __DIR__ . '/../../app/helpers/layout.php';

$user   = require_role('student');
$quizId = (int) ($_GET['id'] ?? $_POST['quiz_id'] ?? 0);

if (($_SERVER['REQUEST_METHOD'] ?? '') === 'POST') {
    csrf_verify();
    $r = AttemptService::start((int) $user['id'], $quizId);
    if ($r['attempt_id'] !== null) {
        redirect('/student/attempt.php?id=' . $r['attempt_id']);
    }
    $messages = [
        'not_found' => 'That quiz is not available.',
        'not_open'  => 'This quiz is not open right now.',
        'limit'     => 'You have used all your attempts for this quiz.',
        'empty'     => 'This quiz has no questions yet. Tell your teacher.',
    ];
    flash_set($messages[$r['result']] ?? 'The quiz could not be started.', 'error');
    redirect($r['result'] === 'not_found' ? '/student/index.php' : '/student/quiz.php?id=' . $quizId);
}

$q = AttemptService::startInfo((int) $user['id'], $quizId);
if ($q === null) {
    http_response_code(404);
    student_start('Quiz not found');
    echo state_empty('Quiz not found', 'This quiz does not exist, or it is not in one of your classes.');
    echo '<p><a class="btn" href="/student/index.php">Back to your classes</a></p>';
    student_end();
    exit;
}

$classLabel = trim(($q['course_code'] ?? '') . (($q['section'] ?? '') !== '' ? ', Section ' . $q['section'] : ''), ', ');
if ($classLabel === '') {
    $classLabel = (string) $q['class_name'];
}
$max      = (int) $q['max_attempts'];
$used     = (int) $q['attempts_used'];
$duration = $q['duration_min'] !== null ? (int) $q['duration_min'] : null;
$points   = rtrim(rtrim(number_format((float) $q['total_points'], 2, '.', ''), '0'), '.');
$deadline = to_manila($q['closes_at'], 'j M Y, H:i:s') . ' Asia/Manila (UTC+8)';

if ($q['running_id'] !== null) {
    $attemptState = 'In progress';
} elseif ($used === 0) {
    $attemptState = 'Not yet started';
} else {
    $attemptState = $used . ' of ' . $max . ' used';
}

$phaseMark = match ($q['phase']) {
    'open'      => status_mark('active', 'Open for your class'),
    'scheduled' => status_mark('scheduled', 'Opens ' . to_manila($q['opens_at'], 'j M Y, H:i') . ' Asia/Manila'),
    default     => status_mark('complete', 'Closed'),
};

$results = match ($q['score_release']) {
    'on_submission' => 'You see your score as soon as you submit.',
    'scheduled'     => $q['release_at'] !== null
        ? 'Scores are released on ' . to_manila($q['release_at'], 'j M Y, H:i') . ' Asia/Manila.'
        : 'Your teacher releases scores on a set date.',
    default         => 'Your teacher releases scores and answer review separately.',
};

student_start((string) $q['title'], $classLabel, true, ['/assets/css/student.css']);
?>
<div class="q-head">
  <h1><?= e($q['title']) ?></h1>
  <p class="q-meta"><?= e(implode(' · ', array_filter([$classLabel, (string) $q['teacher_name'], to_manila($q['opens_at'], 'l, j F Y')]))) ?></p>
</div>

<dl class="q-band">
  <div><dt>Duration</dt><dd><?= $duration !== null ? e($duration . ' minutes') : 'Until close' ?></dd></div>
  <div><dt><?= e($points . ' points total') ?></dt><dd><?= e($q['question_count'] . ' ' . ($q['question_count'] === 1 ? 'question' : 'questions')) ?></dd></div>
  <div><dt><?= e($attemptState) ?></dt><dd><?= e($max . ' ' . ($max === 1 ? 'attempt' : 'attempts')) ?></dd></div>
</dl>

<section class="q-card" aria-labelledby="before">
  <h2 id="before">Before you begin</h2>
  <div class="q-statusrow">
    <?= $phaseMark ?>
    <span class="q-mono"><?= e(to_manila(utc_now(), 'j M Y · H:i:s') . ' · Asia/Manila') ?></span>
  </div>

  <?php if (trim((string) $q['instructions']) !== ''): ?>
    <p class="q-instructions"><?= e($q['instructions']) ?></p>
  <?php endif; ?>

  <dl class="q-rules">
    <div>
      <dt>Exact deadline</dt>
      <dd><?= e($deadline) ?>.
        <?= $duration !== null
            ? e('Your attempt ends after ' . $duration . ' minutes or at this deadline, whichever comes first.')
            : 'Your attempt ends at this deadline.' ?></dd>
    </div>
    <div>
      <dt>Attempt allowance</dt>
      <dd><?= $max === 1 ? 'One attempt only.' : e($max . ' attempts. You have used ' . $used . '.') ?> Beginning starts the timer; leaving does not pause it.</dd>
    </div>
    <div>
      <dt>Final-save grace</dt>
      <dd>A 15-second grace window accepts final queued saves after your deadline. It is not extra answering time. If you do not submit, your saved work is auto-submitted.</dd>
    </div>
    <div>
      <dt>Your results</dt>
      <dd><?= e($results) ?> You will only see your own work.</dd>
    </div>
  </dl>

  <div class="q-actions">
    <?php if ($q['running_id'] !== null): ?>
      <p>Your attempt is running. Time left keeps counting while you are away.</p>
    <?php elseif ($q['can_start']): ?>
      <p>Make sure you have a stable connection. Your answers are saved as you go; check the saved timestamp while working.</p>
    <?php elseif ($q['phase'] === 'scheduled'): ?>
      <p class="q-blocked">This quiz is not open yet. Come back at the opening time.</p>
    <?php elseif ($q['phase'] === 'closed'): ?>
      <p class="q-blocked">This quiz has closed.</p>
    <?php else: ?>
      <p class="q-blocked">You have used all your attempts for this quiz.</p>
    <?php endif; ?>

    <?php if ($q['can_start']): ?>
      <form method="post" action="/student/quiz.php">
        <?= csrf_field() ?>
        <input type="hidden" name="quiz_id" value="<?= (int) $q['id'] ?>">
        <button class="btn btn--primary btn--pill" type="submit"><?= $q['running_id'] !== null ? 'Resume quiz' : 'Begin quiz' ?> &rarr;</button>
      </form>
    <?php endif; ?>
  </div>
</section>

<p class="q-foot">Your attempt is private. <?= e($q['teacher_name']) ?> can assess your work; classmates cannot view it.</p>
<?php student_end();

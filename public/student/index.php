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

/* Tally student journey progress and find the single most urgent quiz to surface first */
$classCount     = count($home);
$totalQuizzes   = 0;
$doneCount      = 0;
$runningCount   = 0;
$openCount      = 0;
$priorityItem   = null;

foreach ($home as $group) {
    $cls = $group['class'];
    foreach ($group['quizzes'] as $qz) {
        $totalQuizzes++;
        if ($qz['running_id'] !== null) {
            $runningCount++;
            if ($priorityItem === null || $priorityItem['kind'] !== 'running') {
                $priorityItem = ['kind' => 'running', 'class' => $cls, 'quiz' => $qz];
            }
        } elseif ($qz['phase'] === 'scheduled') {
            // upcoming
        } elseif ($qz['can_start']) {
            $openCount++;
            if ($priorityItem === null) {
                $priorityItem = ['kind' => 'open', 'class' => $cls, 'quiz' => $qz];
            }
        } else {
            $doneCount++;
        }
    }
}

$actionableCount = $runningCount + $openCount;
$journeyPct = $classCount === 0
    ? 15
    : ($totalQuizzes === 0 ? 50 : (int) round(35 + 65 * ($doneCount / $totalQuizzes)));

student_start('Your classes & quizzes', '', false, ['/assets/css/student.css']);
?>

<!-- 1. "Your Journey" Progress Tracker -->
<section class="journey-card" aria-labelledby="student-journey-heading">
  <div class="journey-card__head">
    <div>
      <h2 class="journey-card__title" id="student-journey-heading">Your learning journey</h2>
      <p class="journey-card__sub">
        <?php if ($classCount === 0): ?>
          Start by entering your teacher's class code below to unlock your class quizzes.
        <?php elseif ($actionableCount > 0): ?>
          You have <?= $actionableCount ?> quiz<?= $actionableCount === 1 ? '' : 'zes' ?> ready right now across <?= $classCount ?> class<?= $classCount === 1 ? '' : 'es' ?>.
        <?php else: ?>
          You're all caught up across your <?= $classCount ?> class<?= $classCount === 1 ? '' : 'es' ?>!
        <?php endif; ?>
      </p>
    </div>
    <span class="journey-card__pct">
      <?= $totalQuizzes > 0 ? ($doneCount . ' of ' . $totalQuizzes . ' done') : ($classCount > 0 ? 'Classes ready' : 'Step 1 of 3') ?>
    </span>
  </div>
  <div class="progress-track" aria-hidden="true">
    <div class="progress-fill" data-fill-pct="<?= $journeyPct ?>"></div>
  </div>
  <div class="journey-steps">
    <div class="journey-step <?= $classCount > 0 ? 'journey-step--done' : 'journey-step--now' ?>">
      <span class="journey-step__num" aria-hidden="true"><?= $classCount > 0 ? '✓' : '1' ?></span>
      <div>
        <div class="journey-step__title">1. Join your classes</div>
        <div class="journey-step__meta"><?= $classCount > 0 ? $classCount . ' joined' : 'Enter code below' ?></div>
      </div>
    </div>
    <div class="journey-step <?= $classCount > 0 && $actionableCount > 0 ? 'journey-step--now' : ($classCount > 0 && $totalQuizzes > 0 && $actionableCount === 0 ? 'journey-step--done' : '') ?>">
      <span class="journey-step__num" aria-hidden="true"><?= $classCount > 0 && $totalQuizzes > 0 && $actionableCount === 0 ? '✓' : '2' ?></span>
      <div>
        <div class="journey-step__title">2. Take open quizzes</div>
        <div class="journey-step__meta">
          <?php if ($runningCount > 0): ?>
            <?= $runningCount ?> in progress now
          <?php elseif ($openCount > 0): ?>
            <?= $openCount ?> open to start
          <?php else: ?>
            None due right now
          <?php endif; ?>
        </div>
      </div>
    </div>
    <div class="journey-step <?= $doneCount > 0 && $actionableCount === 0 ? 'journey-step--now' : ($doneCount > 0 ? 'journey-step--done' : '') ?>">
      <span class="journey-step__num" aria-hidden="true"><?= $doneCount > 0 ? '✓' : '3' ?></span>
      <div>
        <div class="journey-step__title">3. View your results</div>
        <div class="journey-step__meta"><?= $doneCount > 0 ? $doneCount . ' handed in' : 'Appears after quizzes' ?></div>
      </div>
    </div>
  </div>
</section>

<?php if (!$home): ?>
  <!-- When a student has no classes yet, put "Join your first class" FIRST -->
  <section class="join-card" id="join-class" aria-labelledby="join-first-title">
    <div class="join-card__grid">
      <div>
        <h2 id="join-first-title">Step 1: Join your first class</h2>
        <p class="join-card__sub">Enter the short class code from your teacher to see your quizzes here.</p>
        <form method="post" action="/student/index.php#join-class">
          <?= csrf_field() ?>
          <?= field('join_code', 'Class code from your teacher', 'text', [
              'value' => $joinValue,
              'error' => $joinError,
              'placeholder' => 'e.g. NET-4829',
              'hint' => 'Letters and numbers (a hyphen is fine).',
              'maxlength' => 12,
              'required' => true,
              'nocaps' => true,
          ]) ?>
          <button class="btn btn--primary btn--pill" type="submit">
            <span>Join my class</span>
            <span class="btn__arrow" aria-hidden="true">&rarr;</span>
          </button>
        </form>
      </div>
      <div class="join-help-well">
        <strong>Don't have a class code yet?</strong>
        Your teacher shares it in class or can add your username (<code><?= e((string) ($user['username'] ?? '')) ?></code>) directly to their class list.
      </div>
    </div>
  </section>

<?php else: ?>

  <!-- 2. Most Common / Urgent Task First: Resume Running Quiz or Start Next Open Quiz -->
  <?php if ($priorityItem !== null):
      $pc = $priorityItem['class'];
      $pq = $priorityItem['quiz'];
      $pCode = trim(($pc['course_code'] ?? '') . (($pc['section'] ?? '') !== '' ? ' · Section ' . $pc['section'] : ''), ' ·');
      $pClassPill = ($pCode !== '' ? $pCode . ' — ' : '') . $pc['name'];
      $isRunning = $priorityItem['kind'] === 'running';
  ?>
    <section class="priority-quiz<?= $isRunning ? ' priority-quiz--running' : '' ?>" aria-labelledby="priority-quiz-title">
      <div>
        <div class="priority-quiz__eyebrow"><?= $isRunning ? 'Do this first · Timer is running' : 'Up next · Open for you now' ?></div>
        <div class="priority-quiz__class-pill"><?= e($pClassPill) ?></div>
        <div class="priority-quiz__head">
          <h2 id="priority-quiz-title"><?= e($pq['title']) ?></h2>
          <?= $isRunning ? status_mark('attention', 'In progress') : status_mark('active', 'Open now') ?>
        </div>
        <p class="priority-quiz__meta">
          <?php if ($isRunning): ?>
            Finishes <?= e(friendly_manila($pq['running_deadline'])) ?> · Your saved answers are waiting.
          <?php else: ?>
            Due <?= e(friendly_manila($pq['closes_at'])) ?>
            <?= $pq['duration_min'] !== null ? ' · ' . (int) $pq['duration_min'] . ' mins once started' : '' ?>
          <?php endif; ?>
        </p>
      </div>
      <a class="btn btn--primary btn--pill" href="/student/quiz.php?id=<?= (int) $pq['id'] ?>">
        <span><?= $isRunning ? 'Continue quiz' : 'Start quiz' ?></span>
        <span class="btn__arrow" aria-hidden="true">&rarr;</span>
      </a>
    </section>
  <?php endif; ?>

  <!-- 3. All Classes & Quizzes with Status Legend and High-Contrast Class Banners -->
  <div class="stu-section-bar">
    <h2>All your classes</h2>
    <p class="tz-note">All dates and times are shown in local Philippine Time.</p>
  </div>

  <?= status_legend([
      ['attention', 'In progress'],
      ['active',    'Open now'],
      ['scheduled', 'Not open yet'],
      ['complete',  'Done'],
  ], 'Quiz statuses:') ?>

  <?php foreach ($home as $group):
      $c = $group['class'];
      $codeBadge = implode(' · ', array_filter([
          (string) ($c['course_code'] ?? ''),
          ($c['section'] ?? '') !== '' ? 'Section ' . $c['section'] : '',
      ]));
      $quizTotalForClass = count($group['quizzes']);
  ?>
    <section class="h-class" aria-label="<?= e($c['name']) ?>">
      <div class="class-banner">
        <div class="class-banner__main">
          <div class="class-banner__badges">
            <?php if ($codeBadge !== ''): ?>
              <span class="class-code-pill"><?= e($codeBadge) ?></span>
            <?php endif; ?>
            <span class="class-teacher-pill">with <?= e((string) $c['owner_name']) ?></span>
          </div>
          <h3 class="class-banner__title"><?= e($c['name']) ?></h3>
        </div>
        <span class="class-banner__count">
          <?= $quizTotalForClass === 0 ? 'No open quizzes' : ($quizTotalForClass . ' quiz' . ($quizTotalForClass === 1 ? '' : 'zes')) ?>
        </span>
      </div>

      <?php if (!$group['quizzes']): ?>
        <p class="h-none">Nothing due in <strong><?= e($c['name']) ?></strong> right now. Enjoy the breather!</p>
      <?php else: ?>
        <ul class="well-list">
          <?php foreach ($group['quizzes'] as $q):
              $max = (int) $q['max_attempts'];
              if ($q['running_id'] !== null) {
                  $mark = status_mark('attention', 'In progress');
                  $meta = 'Finishes ' . friendly_manila($q['running_deadline']);
                  $btn  = '<a class="btn btn--primary btn--sm" href="/student/quiz.php?id=' . (int) $q['id'] . '"><span>Continue quiz</span><span class="btn__arrow" aria-hidden="true">&rarr;</span></a>';
              } elseif ($q['phase'] === 'scheduled') {
                  $mark = status_mark('scheduled', 'Not open yet');
                  $meta = 'Opens ' . friendly_manila($q['opens_at']) . ' · Closes ' . friendly_manila($q['closes_at']);
                  $btn  = '<a class="btn btn--sm btn--quiet" href="/student/quiz.php?id=' . (int) $q['id'] . '">Read instructions</a>';
              } elseif ($q['can_start']) {
                  $mark = status_mark('active', 'Open now');
                  $triesLeft = max(0, $max - (int) $q['attempts_used']);
                  $triesPhrase = $max === 1 ? '1 try allowed' : ($triesLeft . ' of ' . $max . ' tries left');
                  $meta = 'Due ' . friendly_manila($q['closes_at'])
                        . ($q['duration_min'] !== null ? ' · ' . (int) $q['duration_min'] . ' mins' : '')
                        . ' · ' . $triesPhrase;
                  $btn  = '<a class="btn btn--primary btn--sm" href="/student/quiz.php?id=' . (int) $q['id'] . '"><span>Start quiz</span><span class="btn__arrow" aria-hidden="true">&rarr;</span></a>';
              } else {
                  $mark = status_mark('complete', 'Done');
                  $meta = $max === 1 ? 'Handed in' : ('All ' . $max . ' tries used');
                  $btn  = $q['last_completed_id'] !== null
                      ? '<a class="btn btn--sm" href="/student/receipt.php?id=' . (int) $q['last_completed_id'] . '"><span>See my result</span><span class="btn__arrow" aria-hidden="true">&rarr;</span></a>'
                      : '';
              }
          ?>
            <li class="well-row">
              <div class="well-row__main">
                <div class="well-row__top">
                  <span class="well-row__title"><?= e($q['title']) ?></span>
                  <?= $mark ?>
                </div>
                <div class="well-row__meta"><?= e($meta) ?></div>
              </div>
              <?php if ($btn !== ''): ?>
                <div class="well-row__actions"><?= $btn ?></div>
              <?php endif; ?>
            </li>
          <?php endforeach; ?>
        </ul>
      <?php endif; ?>
    </section>
  <?php endforeach; ?>

  <!-- 4. Join Another Class Card + Stuck Help -->
  <section class="join-card" id="join-class" aria-labelledby="join-more-title">
    <div class="join-card__grid">
      <div>
        <h2 id="join-more-title">Join another class</h2>
        <p class="join-card__sub">Have a code from another teacher? Enter it here to add that class.</p>
        <form method="post" action="/student/index.php#join-class">
          <?= csrf_field() ?>
          <?= field('join_code', 'Class code', 'text', [
              'value' => $joinValue,
              'error' => $joinError,
              'placeholder' => 'e.g. DB-204',
              'hint' => 'Letters and numbers from your teacher (hyphens are fine).',
              'maxlength' => 12,
              'required' => true,
              'nocaps' => true,
          ]) ?>
          <button class="btn btn--primary btn--pill" type="submit">
            <span>Join class</span>
            <span class="btn__arrow" aria-hidden="true">&rarr;</span>
          </button>
        </form>
      </div>
      <div class="join-help-well">
        <strong>Code not working or missing a class?</strong>
        Double-check the letters with your teacher, or ask them to add your username (<code><?= e((string) ($user['username'] ?? '')) ?></code>) directly from their class page.
      </div>
    </div>
  </section>
<?php endif; ?>

<?php student_end();

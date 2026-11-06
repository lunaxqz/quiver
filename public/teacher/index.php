<?php
declare(strict_types=1);

require_once __DIR__ . '/../../app/bootstrap.php';
require_once __DIR__ . '/../../app/helpers/layout.php';

$user      = require_role('teacher');
$teacherId = (int) $user['id'];

$overview   = TodayService::overviewForTeacher($teacherId);
$todayItems = $overview['today_items'];
$heroItem   = $overview['hero_item'];
$alsoToday  = $overview['also_today'];
$summary    = $overview['summary'];
$thisWeek   = $overview['this_week'];

$todayCount   = count($todayItems);
$classesCount = (int) ($summary['classes_count'] ?? 0);
$studentsCnt  = (int) ($summary['students_count'] ?? 0);
$quizzesCnt   = (int) ($summary['active_quizzes_count'] ?? 0);

if ($classesCount === 0) {
    $subtitle = 'Welcome! Follow the numbered steps below to set up your first class and quiz.';
} elseif ($todayCount === 0) {
    $subtitle = 'Nothing needs your attention right now. All your classes are on track.';
} elseif ($todayCount === 1) {
    $subtitle = 'One thing needs your attention today. Everything else is on track.';
} else {
    $subtitle = TodayService::numberWord($todayCount, true) . ' things need your attention today. Everything else is on track.';
}

$formatMmSs = static function (int $seconds): string {
    $sec = max(0, $seconds);
    $m   = intdiv($sec, 60);
    $s   = $sec % 60;
    return sprintf('%02d:%02d', $m, $s);
};

/* Primary header action puts the teacher's most common task right at the top */
$headerBtn = $classesCount === 0
    ? '<a class="btn btn--primary btn--pill" href="/teacher/classes.php"><span>+ Set up a class</span><span class="btn__arrow" aria-hidden="true">&rarr;</span></a>'
    : '<a class="btn btn--primary btn--pill" href="/teacher/quizzes.php"><span>+ Schedule a quiz</span><span class="btn__arrow" aria-hidden="true">&rarr;</span></a>';

/* Compute Teacher Journey Progress */
$journeyPct = 20;
if ($classesCount > 0 && $quizzesCnt === 0) {
    $journeyPct = 50;
} elseif ($classesCount > 0 && $quizzesCnt > 0 && $todayCount > 0) {
    $journeyPct = 75;
} elseif ($classesCount > 0 && $quizzesCnt > 0 && $todayCount === 0) {
    $journeyPct = 100;
}

teacher_start(
    'Today\'s overview',
    'overview',
    'teacher',
    ['/assets/css/overview.css'],
    e($subtitle),
    $headerBtn
);
?>

<!-- 0. "Your Teaching Journey" Progress Tracker -->
<section class="journey-card" aria-labelledby="teacher-journey-heading">
  <div class="journey-card__head">
    <div>
      <h2 class="journey-card__title" id="teacher-journey-heading">Your teaching journey</h2>
      <p class="journey-card__sub">Work from left to right whenever you run a quiz for your students.</p>
    </div>
    <span class="journey-card__pct">
      <?= $classesCount === 0 ? 'Step 1 of 4' : ($quizzesCnt === 0 ? 'Step 2–3 next' : ($todayCount > 0 ? $todayCount . ' to review today' : 'All caught up')) ?>
    </span>
  </div>
  <div class="progress-track" aria-hidden="true">
    <div class="progress-fill" data-fill-pct="<?= $journeyPct ?>"></div>
  </div>
  <div class="journey-steps">
    <a class="journey-step <?= $classesCount > 0 ? 'journey-step--done' : 'journey-step--now' ?>" href="/teacher/classes.php">
      <span class="journey-step__num" aria-hidden="true"><?= $classesCount > 0 ? '✓' : '1' ?></span>
      <div>
        <div class="journey-step__title">1. Set up classes</div>
        <div class="journey-step__meta"><?= $classesCount > 0 ? ($classesCount . ' class' . ($classesCount === 1 ? '' : 'es') . ' · ' . $studentsCnt . ' students') : 'Start here' ?></div>
      </div>
    </a>
    <a class="journey-step <?= $classesCount > 0 && $quizzesCnt > 0 ? 'journey-step--done' : ($classesCount > 0 ? 'journey-step--now' : '') ?>" href="/teacher/bank.php">
      <span class="journey-step__num" aria-hidden="true"><?= $classesCount > 0 && $quizzesCnt > 0 ? '✓' : '2' ?></span>
      <div>
        <div class="journey-step__title">2. Write questions</div>
        <div class="journey-step__meta">Question bank</div>
      </div>
    </a>
    <a class="journey-step <?= $quizzesCnt > 0 ? 'journey-step--done' : '' ?>" href="/teacher/quizzes.php">
      <span class="journey-step__num" aria-hidden="true"><?= $quizzesCnt > 0 ? '✓' : '3' ?></span>
      <div>
        <div class="journey-step__title">3. Schedule quizzes</div>
        <div class="journey-step__meta"><?= $quizzesCnt > 0 ? ($quizzesCnt . ' published') : 'Pick dates & rules' ?></div>
      </div>
    </a>
    <a class="journey-step <?= $todayCount > 0 && $classesCount > 0 ? 'journey-step--now' : ($quizzesCnt > 0 && $todayCount === 0 ? 'journey-step--done' : '') ?>" href="/teacher/grading.php">
      <span class="journey-step__num" aria-hidden="true"><?= $quizzesCnt > 0 && $todayCount === 0 ? '✓' : '4' ?></span>
      <div>
        <div class="journey-step__title">4. Grade &amp; share</div>
        <div class="journey-step__meta"><?= $todayCount > 0 ? 'Check today\'s list' : 'Scores & reports' ?></div>
      </div>
    </a>
  </div>
</section>

<div id="overview-root" data-hero-remaining="<?= $heroItem !== null ? (int) $heroItem['remaining_seconds'] : 0 ?>">

  <!-- 1. Urgent Task First: Live Quiz Closing Soon Card -->
  <div id="today-hero-mount"<?= $heroItem === null ? ' hidden' : '' ?>>
    <?php if ($heroItem !== null):
        $subCnt  = (int) ($heroItem['submitted_count'] ?? 0);
        $workCnt = (int) ($heroItem['working_count'] ?? 0);
        $discCnt = (int) ($heroItem['disconnected_count'] ?? 0);
    ?>
      <section class="today-hero" aria-label="Quiz closing soon">
        <div class="today-hero__left">
          <?= status_mark('attention', 'Closing soon') ?>
          <h2 class="today-hero__headline"><?= e((string) $heroItem['headline']) ?></h2>
          <p class="today-hero__meta"><?= e((string) $heroItem['submeta']) ?></p>
          <a class="btn btn--primary btn--pill" href="<?= e((string) $heroItem['action_href']) ?>">
            <span><?= e((string) $heroItem['action_label']) ?></span>
            <span class="btn__arrow" aria-hidden="true">&rarr;</span>
          </a>
        </div>
        <div class="today-hero__right">
          <div class="today-hero__timer-label">Time left before quiz closes</div>
          <div class="today-hero__timer" id="today-hero-timer"><?= e($formatMmSs((int) $heroItem['remaining_seconds'])) ?></div>
          <div class="today-hero__counts" aria-label="Live student progress">
            <?= status_mark('complete', $subCnt . ' handed in') ?>
            <?= status_mark('attention', $workCnt . ' working') ?>
            <?php if ($discCnt > 0): ?>
              <?= status_mark('problem', $discCnt . ' offline') ?>
            <?php endif; ?>
          </div>
        </div>
      </section>
    <?php endif; ?>
  </div>

  <!-- 2. Today's To-Do List (or Calm Empty State) -->
  <div id="today-list-mount">
    <?php if ($todayCount === 0): ?>
      <?php if ($classesCount === 0): ?>
        <?= state_empty(
            'Ready for your first class',
            'Start with Step 1: create a class and share the short join code with your students.',
            'Set up your first class',
            '/teacher/classes.php'
        ) ?>
      <?php else: ?>
        <section class="today-calm-card" aria-label="Today status">
          <div class="today-calm-card__left">
            <?= status_mark('complete', 'All clear') ?>
            <div>
              <p class="today-calm-card__title">Nothing needs you right now</p>
              <p class="today-calm-card__sub">All open quizzes, written grading, and score releases are up to date.</p>
            </div>
          </div>
          <a class="btn btn--pill" href="/teacher/quizzes.php">View quizzes</a>
        </section>
      <?php endif; ?>
    <?php elseif (!empty($alsoToday)): ?>
      <section class="today-card" aria-label="<?= $heroItem !== null ? 'Also today' : 'Needs your attention today' ?>">
        <div class="today-card__head"><?= $heroItem !== null ? 'Also on your list today' : 'Needs your attention today' ?></div>
        <ul class="today-list">
          <?php foreach ($alsoToday as $item): ?>
            <li class="today-row" data-today-type="<?= e((string) $item['type']) ?>">
              <div class="today-row__mark">
                <?= status_mark((string) $item['state'], (string) $item['badge_label']) ?>
              </div>
              <div class="today-row__detail"><?= e((string) $item['detail']) ?></div>
              <div class="today-row__action">
                <a class="btn btn--sm" href="<?= e((string) $item['action_href']) ?>">
                  <span><?= e((string) $item['action_label']) ?></span>
                  <span class="btn__arrow" aria-hidden="true">&rarr;</span>
                </a>
              </div>
            </li>
          <?php endforeach; ?>
        </ul>
      </section>
    <?php endif; ?>
  </div>

  <!-- 3. Summary Counts Line -->
  <?php if ($classesCount > 0): ?>
    <p class="overview-summary-line" id="overview-summary-line">
      <?= $classesCount ?> active class<?= $classesCount === 1 ? '' : 'es' ?>
      · <?= $studentsCnt ?> enrolled student<?= $studentsCnt === 1 ? '' : 's' ?>
      · <?= $quizzesCnt ?> published quiz<?= $quizzesCnt === 1 ? '' : 'zes' ?>
    </p>
  <?php endif; ?>

  <!-- 4. This Week's Quizzes with Status Legend + Single Action per Row -->
  <?php if (!empty($thisWeek)): ?>
    <section class="week-card" id="this-week-mount" aria-labelledby="this-week-heading">
      <div class="week-card__head">
        <div>
          <h2 id="this-week-heading">Recent &amp; upcoming quizzes</h2>
          <p class="week-card__tz">All times are shown in local Philippine Time.</p>
        </div>
        <a class="btn btn--sm" href="/teacher/quizzes.php">All quizzes</a>
      </div>

      <?= status_legend([
          ['active',    'Open now'],
          ['attention', 'Needs grading'],
          ['complete',  'Graded'],
          ['scheduled', 'Not open yet'],
      ], 'Quiz statuses:') ?>

      <div class="table-wrap">
        <table class="tbl--week">
          <thead>
            <tr>
              <th scope="col">Quiz &amp; class</th>
              <th scope="col">Status</th>
              <th scope="col">Schedule</th>
              <th scope="col">Handed in</th>
              <th scope="col"><span class="visually-hidden">Next action</span></th>
            </tr>
          </thead>
          <tbody>
            <?php foreach ($thisWeek as $w):
                $plainLabel = match ((string) $w['status_label']) {
                    'Open'      => 'Open now',
                    'Scheduled' => 'Not open yet',
                    default     => (string) $w['status_label'],
                };
                $actLabel = match ((string) $w['status_label']) {
                    'Open'          => 'Live view',
                    'Needs grading' => 'Grade answers',
                    'Graded'        => 'Share scores',
                    default         => 'Preview',
                };
                $isPrimaryAct = in_array((string) $w['status_label'], ['Open', 'Needs grading'], true);
            ?>
              <tr>
                <td>
                  <a class="week-quiz-title" href="<?= e((string) $w['href']) ?>"><?= e((string) $w['title']) ?></a>
                  <span class="week-quiz-meta"><?= e((string) $w['class_meta']) ?></span>
                </td>
                <td><?= status_mark((string) $w['status_state'], $plainLabel) ?></td>
                <td><?= e((string) $w['window_label']) ?></td>
                <td>
                  <div class="week-sub-wrap">
                    <span><?= (int) $w['submitted_count'] ?> / <?= (int) $w['roster_count'] ?></span>
                    <span class="week-bar" aria-hidden="true">
                      <span class="week-bar__fill progress-fill" data-fill-pct="<?= (int) $w['progress_pct'] ?>"></span>
                    </span>
                  </div>
                </td>
                <td>
                  <a class="btn btn--sm<?= $isPrimaryAct ? ' btn--primary' : '' ?>" href="<?= e((string) $w['href']) ?>">
                    <span><?= e($actLabel) ?></span>
                    <span class="btn__arrow" aria-hidden="true">&rarr;</span>
                  </a>
                </td>
              </tr>
            <?php endforeach; ?>
          </tbody>
        </table>
      </div>
    </section>
  <?php endif; ?>

</div>

<script src="/assets/js/overview.js" defer></script>
<?php teacher_end();

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

if ($classesCount === 0) {
    $subtitle = 'Nothing needs you right now. Everything is on track.';
} elseif ($todayCount === 0) {
    $subtitle = 'Nothing needs you right now. Everything else is on track.';
} elseif ($todayCount === 1) {
    $subtitle = 'One thing needs you today. Everything else is on track.';
} else {
    $subtitle = TodayService::numberWord($todayCount, true) . ' things need you today. Everything else is on track.';
}

$formatMmSs = static function (int $seconds): string {
    $sec = max(0, $seconds);
    $m   = intdiv($sec, 60);
    $s   = $sec % 60;
    return sprintf('%02d:%02d', $m, $s);
};

teacher_start(
    'Teacher overview',
    'overview',
    'teacher',
    ['/assets/css/overview.css'],
    e($subtitle)
);
?>

<div id="overview-root" data-hero-remaining="<?= $heroItem !== null ? (int) $heroItem['remaining_seconds'] : 0 ?>">

  <!-- 1. Closing Soon Dark Hero Card (when top-ranked item is closing_soon) -->
  <div id="today-hero-mount"<?= $heroItem === null ? ' hidden' : '' ?>>
    <?php if ($heroItem !== null): ?>
      <section class="today-hero" aria-label="Closing soon">
        <div class="today-hero__left">
          <div class="today-hero__badge">
            <span class="today-hero__ring" aria-hidden="true"></span>
            <span>Closing soon</span>
          </div>
          <h2 class="today-hero__headline"><?= e((string) $heroItem['headline']) ?></h2>
          <p class="today-hero__meta"><?= e((string) $heroItem['submeta']) ?></p>
          <a class="today-hero__btn" href="<?= e((string) $heroItem['action_href']) ?>"><?= e((string) $heroItem['action_label']) ?></a>
        </div>
        <div class="today-hero__right">
          <div class="today-hero__timer-label">Time remaining</div>
          <div class="today-hero__timer" id="today-hero-timer"><?= e($formatMmSs((int) $heroItem['remaining_seconds'])) ?></div>
          <div class="today-hero__grid" aria-hidden="true">
            <?php foreach (($heroItem['cells'] ?? []) as $cellState): ?>
              <span class="hero-cell hero-cell--<?= e((string) $cellState) ?>"></span>
            <?php endforeach; ?>
          </div>
          <div class="today-hero__legend">Solid: submitted · Outlined: working · Dashed: disconnected</div>
        </div>
      </section>
    <?php endif; ?>
  </div>

  <!-- 2. Also Today / Today Rail List (or Calm Empty State) -->
  <div id="today-list-mount">
    <?php if ($todayCount === 0): ?>
      <?php if ($classesCount === 0): ?>
        <?= state_empty(
            'Nothing needs you right now',
            'Create a class to get started. Your Today rail will surface quizzes closing soon, grading, and release items here.',
            'Create a class',
            '/teacher/classes.php'
        ) ?>
      <?php else: ?>
        <section class="today-calm-card" aria-label="Today status">
          <div class="today-calm-card__left">
            <?= status_mark('complete', '') ?>
            <div>
              <p class="today-calm-card__title">Nothing needs you right now</p>
              <p class="today-calm-card__sub">All active quizzes, grading queues, and result releases are up to date.</p>
            </div>
          </div>
          <a class="today-row__btn" href="/teacher/quizzes.php">View quizzes</a>
        </section>
      <?php endif; ?>
    <?php elseif (!empty($alsoToday)): ?>
      <section class="today-card" aria-label="<?= $heroItem !== null ? 'Also today' : 'Today' ?>">
        <div class="today-card__head"><?= $heroItem !== null ? 'Also today' : 'Today' ?></div>
        <ul class="today-list">
          <?php foreach ($alsoToday as $item): ?>
            <li class="today-row" data-today-type="<?= e((string) $item['type']) ?>">
              <div class="today-row__mark">
                <?= status_mark((string) $item['state'], (string) $item['badge_label']) ?>
              </div>
              <div class="today-row__detail"><?= e((string) $item['detail']) ?></div>
              <div class="today-row__action">
                <a class="today-row__btn" href="<?= e((string) $item['action_href']) ?>"><?= e((string) $item['action_label']) ?></a>
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
      <?= (int) $summary['classes_count'] ?> class<?= (int) $summary['classes_count'] === 1 ? '' : 'es' ?>
      · <?= (int) $summary['students_count'] ?> student<?= (int) $summary['students_count'] === 1 ? '' : 's' ?>
      · <?= (int) $summary['active_quizzes_count'] ?> active quiz<?= (int) $summary['active_quizzes_count'] === 1 ? '' : 'zes' ?>
    </p>
  <?php endif; ?>

  <!-- 4. This Week Section -->
  <?php if (!empty($thisWeek)): ?>
    <section class="week-card" id="this-week-mount" aria-labelledby="this-week-heading">
      <div class="week-card__head">
        <h2 id="this-week-heading">This week</h2>
        <a class="today-row__btn" href="/teacher/quizzes.php">All quizzes</a>
      </div>
      <table class="tbl--week">
        <thead>
          <tr>
            <th scope="col">Quiz</th>
            <th scope="col">Status</th>
            <th scope="col">Window</th>
            <th scope="col">Submitted</th>
          </tr>
        </thead>
        <tbody>
          <?php foreach ($thisWeek as $w): ?>
            <tr>
              <td>
                <a class="week-quiz-title" href="<?= e((string) $w['href']) ?>"><?= e((string) $w['title']) ?></a>
                <span class="week-quiz-meta"><?= e((string) $w['class_meta']) ?></span>
              </td>
              <td><?= status_mark((string) $w['status_state'], (string) $w['status_label']) ?></td>
              <td><?= e((string) $w['window_label']) ?></td>
              <td>
                <div class="week-sub-wrap">
                  <span><?= (int) $w['submitted_count'] ?> / <?= (int) $w['roster_count'] ?></span>
                  <span class="week-bar" aria-hidden="true">
                    <span class="week-bar__fill" style="width: <?= (int) $w['progress_pct'] ?>%"></span>
                  </span>
                </div>
              </td>
            </tr>
          <?php endforeach; ?>
        </tbody>
      </table>
    </section>
  <?php endif; ?>

</div>

<script src="/assets/js/overview.js" defer></script>
<?php teacher_end();

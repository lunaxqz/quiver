<?php
declare(strict_types=1);

/**
 * The attempt screen (Figma: Student attempt · Day 12).
 *
 * One question per screen, serif prompt, option rows >=48px inside a fieldset,
 * progress dots, number-key shortcuts, and a server-owned countdown timer with
 * live-region announcements at 10, 5, and 1 minutes.
 *
 * The browser only ever receives AttemptService::payload(): no is_correct, no rubric, no answer keys.
 */

require_once __DIR__ . '/../../app/bootstrap.php';
require_once __DIR__ . '/../../app/helpers/layout.php';

$user = require_role('student');
$data = AttemptService::payload((int) ($_GET['id'] ?? 0), (int) $user['id']);

if ($data === null) {
    http_response_code(404);
    student_start('Attempt not found');
    echo state_empty('Attempt not found', 'This attempt does not exist or is not yours.');
    echo '<p><a class="btn" href="/student/index.php">Back to your classes</a></p>';
    student_end();
    exit;
}

$a   = $data['attempt'];
$q   = $data['quiz'];
$qs  = $data['questions'];
$tot = count($qs);

$remMin = intdiv((int) $a['remaining_seconds'], 60);
$remSec = (int) $a['remaining_seconds'] % 60;
$remFormatted = sprintf('%02d : %02d', $remMin, $remSec);
$isFinalMin   = (int) $a['remaining_seconds'] > 0 && (int) $a['remaining_seconds'] <= 60;

student_start($q['title'], (string) ($q['class_label'] ?? ''), true, ['/assets/css/student.css', '/assets/css/attempt.css']);
?>
<div class="att" data-attempt-id="<?= (int) $a['id'] ?>" data-csrf="<?= e(csrf_token()) ?>">
  <div class="q-head">
    <h1><?= e($q['title']) ?></h1>
    <p class="q-meta">Attempt <?= (int) $a['attempt_no'] ?> of <?= (int) $a['max_attempts'] ?> · Work at your own pace within the time limit.</p>
  </div>

  <div class="att-bar">
    <div class="att-progress">
      <p class="att-progress__summary" id="att-summary">
        Question 1 of <?= $tot ?> · 0 answered, <?= $tot ?> unanswered
      </p>
      <nav class="att-dots" id="att-dots" aria-label="Questions">
        <?php foreach ($qs as $idx => $item): ?>
          <button type="button"
                  class="att-dot<?= $idx === 0 ? ' is-current' : '' ?>"
                  data-index="<?= (int) $idx ?>"
                  aria-label="Question <?= (int) ($idx + 1) ?>, unanswered"
                  <?= $idx === 0 ? 'aria-current="step"' : '' ?>><?= (int) ($idx + 1) ?></button>
        <?php endforeach; ?>
      </nav>
      <p class="att-dots__legend">Filled: answered · Outline: unanswered · Emphasised: current</p>
    </div>

    <div class="att-timer<?= $isFinalMin ? ' is-final' : '' ?>" id="att-timer-box">
      <span class="att-timer__label" id="att-timer-label"><?= $isFinalMin ? 'FINAL MINUTE' : 'TIME LEFT' ?></span>
      <div class="att-timer__digits" id="att-timer-digits" aria-hidden="true"><?= e($remFormatted) ?></div>
      <span class="att-timer__ends">Ends <?= e($a['deadline_manila']) ?> · Asia/Manila</span>
    </div>
  </div>

  <!-- Screen-reader live region: announces only at 10 min, 5 min, 1 min, and time up -->
  <div class="visually-hidden" id="att-timer-live" role="status" aria-live="polite" aria-atomic="true"></div>

  <section class="att-card" id="att-card" aria-live="off">
    <div class="att-card__top">
      <span class="att-type" id="att-type">SINGLE-SELECT · CHOOSE ONE</span>
      <span class="att-points" id="att-points"></span>
    </div>

    <fieldset class="att-fieldset" id="att-fieldset">
      <legend class="att-prompt" id="att-prompt"></legend>
      <div class="att-options" id="att-options"></div>
    </fieldset>

    <div class="att-saved" id="att-saved" role="status" aria-live="polite">
      <?php if ($a['saved_manila'] !== ''): ?>
        <span class="att-saved__check" aria-hidden="true">✓</span>
        <span id="att-saved-text">Saved · <?= e($a['saved_manila']) ?> Asia/Manila</span>
      <?php else: ?>
        <span class="att-saved__check" aria-hidden="true">✓</span>
        <span id="att-saved-text">Not yet saved</span>
      <?php endif; ?>
    </div>
  </section>

  <nav class="att-nav" aria-label="Question navigation">
    <button type="button" class="btn btn--pill att-nav__prev" id="att-prev" disabled>&lsaquo; Previous</button>
    <button type="button" class="btn btn--pill att-nav__review" id="att-review">Review answers</button>
    <button type="button" class="btn btn--primary btn--pill att-nav__next" id="att-next">Next question &rarr;</button>
  </nav>

  <p class="att-foot">You may change your selection before submitting. The timer continues if you leave or lose your connection.</p>
</div>

<script type="application/json" id="attempt-data"><?= json_encode($data, JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_UNESCAPED_UNICODE) ?></script>
<script src="/assets/js/attempt.js" defer></script>
<?php student_end();

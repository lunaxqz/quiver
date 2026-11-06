<?php
declare(strict_types=1);

/**
 * Student submission receipt and released result screen (Figma: Student receipt and released result · Day 14, Day 16).
 *
 * Rules enforced here:
 *  - Only the owning student may view this attempt (`AttemptService::receipt()` returns null -> 404).
 *  - If the attempt is still `in_progress` and time remains, redirects back to `/student/attempt.php?id=...`.
 *  - Shows the submission receipt card with timestamp in Asia/Manila (UTC+8), reference code, and answered/unanswered counts.
 *  - Shows the released score card ONLY when `score_released` is true (`score_released_at <= now`).
 *  - Shows the answer review breakdown ONLY when `review_released` is true (`review_released_at <= now`).
 */

require_once __DIR__ . '/../../app/bootstrap.php';
require_once __DIR__ . '/../../app/helpers/layout.php';

$user = require_role('student');
$attemptId = (int) ($_GET['id'] ?? 0);

$r = $attemptId > 0 ? AttemptService::receipt($attemptId, (int) $user['id']) : null;
if ($r === null) {
    http_response_code(404);
    student_start('Receipt not found');
    echo state_empty('Receipt not found', 'This submission receipt does not exist or is not yours.');
    echo '<p><a class="btn" href="/student/index.php">Back to your classes</a></p>';
    student_end();
    exit;
}

if ($r['status'] === 'in_progress') {
    redirect('/student/attempt.php?id=' . (int) $r['attempt_id']);
}

$title       = (string) $r['quiz_title'];
$classLabel  = (string) $r['class_label'];
$attemptNo   = (int) $r['attempt_no'];
$maxAttempts = (int) $r['max_attempts'];
$answered    = (int) $r['answered_count'];
$unanswered  = (int) $r['unanswered_count'];

// Build release notice text for the receipt card
if ($r['score_released'] && $r['review_released']) {
    $releaseNote = 'Your score and answer review are available below.';
} elseif ($r['score_released']) {
    $releaseNote = 'Your score is available below. ' . $r['teacher_name'] . ' releases answer review separately.';
} else {
    if ($r['score_release_mode'] === 'scheduled' && $r['release_at_manila'] !== '') {
        $releaseNote = 'At submission: scores and answer review were not released. '
            . $r['teacher_name'] . ' expects to release scores by ' . $r['release_at_manila'] . ' Asia/Manila. Answer review follows separately.';
    } else {
        $releaseNote = 'At submission: scores and answer review were not released. '
            . $r['teacher_name'] . ' will release your score once grading is complete. Answer review follows separately.';
    }
}

$fmtNum = static function (float $v): string {
    return rtrim(rtrim(number_format($v, 2, '.', ''), '0'), '.');
};

student_start($title, $classLabel, true, ['/assets/css/student.css', '/assets/css/attempt.css']);
?>
<div class="att rcpt-wrap">
  <div class="q-head">
    <h1>Your work has been received.</h1>
    <p class="q-meta"><?= e($title) ?> · <?= e($classLabel) ?> · Attempt <?= $attemptNo ?> of <?= $maxAttempts ?></p>
  </div>

  <section class="att-card rcpt-card" aria-labelledby="rcpt-heading">
    <div class="rcpt-card__top">
      <?= status_mark($r['status'] === 'voided' ? 'problem' : 'complete', $r['status'] === 'voided' ? 'Voided' : 'Submitted') ?>
      <span class="rcpt-eyebrow">SUBMISSION RECEIPT</span>
    </div>

    <h2 class="rcpt-title" id="rcpt-heading">You can close this page. Your attempt is final.</h2>

    <div class="rcpt-grid">
      <div>
        <span class="rcpt-label">RECEIVED AT</span>
        <div class="rcpt-value"><?= e($r['submitted_manila']) ?></div>
        <span class="rcpt-sub">Asia/Manila · UTC+8<?= $r['submit_reason'] === 'timeout' ? ' · Auto-submitted at deadline' : '' ?></span>
      </div>
      <div>
        <span class="rcpt-label">REFERENCE</span>
        <div class="rcpt-value"><?= e($r['reference']) ?></div>
        <span class="rcpt-sub"><?= $answered ?> answered · <?= $unanswered ?> unanswered</span>
      </div>
    </div>

    <p class="rcpt-note"><?= e($releaseNote) ?></p>

    <div class="rcpt-foot">
      <span>Keep this reference if you need help with your submission.</span>
      <a class="btn btn--pill rcpt-back" href="/student/index.php">Back to my quizzes</a>
    </div>
  </section>

  <?php if ($r['score_released']): ?>
    <div class="rcpt-divider">
      <span>RELEASED RESULT · YOUR SCORE</span>
    </div>

    <section class="att-card rcpt-card rcpt-score-card" aria-labelledby="score-heading">
      <div class="rcpt-card__top">
        <?= status_mark('complete', 'Score released') ?>
        <?php if ($r['score_released_manila'] !== ''): ?>
          <span class="rcpt-released-at"><?= e($r['score_released_manila']) ?> · Asia/Manila</span>
        <?php endif; ?>
      </div>

      <div class="rcpt-score-row">
        <div class="rcpt-score-figure">
          <div class="rcpt-score-num">
            <?= e($fmtNum((float) $r['score'])) ?> <span class="rcpt-score-slash">/</span> <?= e($fmtNum((float) $r['total_points'])) ?>
          </div>
          <span class="rcpt-sub"><?= (int) $r['percentage'] ?>% · Your final score</span>
        </div>

        <div class="rcpt-score-meta">
          <h3 class="rcpt-quiz-name" id="score-heading"><?= e($title) ?></h3>
          <p class="rcpt-sub">
            <?= $maxAttempts === 1 ? 'One attempt' : $maxAttempts . ' attempts' ?>
            · <?= $r['score_policy'] === 'latest' ? 'Latest-score policy' : 'Highest-score policy' ?>
            · Only your own result is visible here.
          </p>
          <div class="rcpt-review-badge">
            <?php if ($r['review_released']): ?>
              <?= status_mark('complete', 'Answer review released') ?>
            <?php else: ?>
              <?= status_mark('neutral', 'Answer review not released') ?>
            <?php endif; ?>
          </div>
        </div>
      </div>

      <?php if (!$r['review_released']): ?>
        <p class="rcpt-note">
          Your score is available, but questions, submitted answers, correct answers and feedback remain private until <?= e($r['teacher_name']) ?> releases answer review.
        </p>
        <div>
          <button type="button" class="btn btn--pill rcpt-btn-disabled" disabled>View answer review · Not yet available</button>
        </div>
      <?php else: ?>
        <div class="rcpt-review-list" id="answer-review">
          <?php foreach ($r['review_items'] as $item): ?>
            <article class="rcpt-review-item">
              <div class="rcpt-review-item__head">
                <strong>Question <?= (int) $item['position'] ?></strong>
                <span class="q-mono"><?= e($fmtNum((float) $item['points_awarded'])) ?> / <?= e($fmtNum((float) $item['max_points'])) ?> pts</span>
              </div>
              <p class="rcpt-review-item__prompt"><?= e($item['prompt']) ?></p>
              <p class="rcpt-review-item__ans">
                <span class="rcpt-label-inline">Your answer:</span>
                <?= $item['student_answer'] !== '' ? e($item['student_answer']) : '<em>No answer submitted (0 pts)</em>' ?>
              </p>
              <?php if ($item['correct_answer'] !== ''): ?>
                <p class="rcpt-review-item__key">
                  <span class="rcpt-label-inline">Accepted / correct:</span>
                  <?= e($item['correct_answer']) ?>
                </p>
              <?php endif; ?>
              <?php if ($item['feedback'] !== ''): ?>
                <p class="rcpt-review-item__fb">
                  <span class="rcpt-label-inline">Teacher feedback:</span>
                  <?= e($item['feedback']) ?>
                </p>
              <?php endif; ?>
            </article>
          <?php endforeach; ?>
        </div>
      <?php endif; ?>
    </section>
  <?php endif; ?>
</div>
<?php student_end();

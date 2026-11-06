<?php
declare(strict_types=1);

/**
 * Teacher grading workspace (Day 15) — matches Figma "QUIVER Databases grading.png".
 *
 * Features:
 *  - Quiz switcher (or automatic selection of the first quiz needing grading)
 *  - Mode tabs: "Question by question" (triage stack across students) and "Whole paper" (all questions per student attempt, including auto-grade override)
 *  - Blind mode toggle (default ON: shows "ANONYMOUS RESPONSE · PAPER K7"; when OFF: shows student's full name & username)
 *  - Keyboard-driven grading shortcuts:
 *      - ArrowLeft / ArrowRight, Alt + ArrowLeft / ArrowRight, j / k : Previous / Next response
 *      - f : Full points
 *      - h : Half points
 *      - 0 or z : Zero points
 *      - p : Focus Points awarded input
 *      - n : Focus Feedback to student textarea
 *      - u : Undo last mark (records a new grade_events row)
 *      - Ctrl + Enter : Save & next
 *      - ? : Keyboard shortcuts help dialog
 */

require_once __DIR__ . '/../../app/bootstrap.php';
require_once __DIR__ . '/../../app/helpers/layout.php';

$user      = require_role('teacher');
$teacherId = (int) $user['id'];

$requestedQuizId = (int) ($_GET['quiz_id'] ?? $_GET['id'] ?? 0);
$quizzes         = GradingService::listQuizzesForGrading($teacherId);

if ($requestedQuizId > 0) {
    $workspace = GradingService::workspace($requestedQuizId, $teacherId);
    if ($workspace === null) {
        // Return 404 (never 403) when a teacher tries to open another teacher's quiz or a nonexistent quiz.
        abort_404();
    }
    $activeQuizId = $requestedQuizId;
} elseif (!empty($quizzes)) {
    $activeQuizId = (int) $quizzes[0]['id'];
    $workspace    = GradingService::workspace($activeQuizId, $teacherId);
} else {
    $activeQuizId = 0;
    $workspace    = null;
}

if ($workspace === null) {
    teacher_start(
        'Grading',
        'grading',
        'teacher',
        ['/assets/css/classes.css', '/assets/css/grading.css'],
        'No published quizzes with student submissions yet.'
    );
    echo state_empty(
        'Nothing to grade yet',
        'Once students submit a published quiz, their written responses and papers will appear here for grading.',
        'Go to quizzes',
        '/teacher/quizzes.php'
    );
    teacher_end();
    exit;
}

$quizMeta       = $workspace['quiz'];
$pendingCount   = (int) $quizMeta['pending_written_count'];
$submittedCount = (int) $quizMeta['submitted_count'];
$rosterCount    = (int) $quizMeta['roster_count'];

$subtitle = 'Grading · ' . e($quizMeta['class_label'])
    . ' · <span id="hdr-submitted-count">' . $submittedCount . ' of ' . $rosterCount . '</span> submitted'
    . ' · <span id="hdr-pending-count">' . $pendingCount . ' written response' . ($pendingCount === 1 ? '' : 's') . ' pending</span>';

$statusMarkHtml = '<div class="grading-head-right" id="hdr-status-mark">'
    . ($pendingCount > 0
        ? status_mark('attention', 'Needs grading')
        : status_mark('complete', 'All graded'))
    . '</div>';

teacher_start(
    (string) $quizMeta['title'],
    'grading',
    'teacher',
    ['/assets/css/classes.css', '/assets/css/grading.css'],
    $subtitle,
    $statusMarkHtml
);

$jsonFlags = JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_UNESCAPED_UNICODE;
?>

<?php if (count($quizzes) > 1): ?>
  <form class="grading-quiz-switcher" method="get" action="/teacher/grading.php">
    <label class="grading-quiz-switcher__label" for="quiz_switcher_select">Quiz:</label>
    <select class="input grading-quiz-switcher__select" id="quiz_switcher_select" name="quiz_id" data-autosubmit-quiz>
      <?php foreach ($quizzes as $qz):
          $qId = (int) $qz['id'];
          $qPending = (int) $qz['pending_written_count'];
          $qClass = trim(($qz['course_code'] ?? '') . (($qz['section'] ?? '') !== '' ? ', ' . $qz['section'] : ''), ', ');
      ?>
        <option value="<?= $qId ?>"<?= $qId === $activeQuizId ? ' selected' : '' ?>>
          <?= e($qz['title']) ?> (<?= e($qClass !== '' ? $qClass : $qz['class_name']) ?> · <?= $qPending > 0 ? $qPending . ' pending' : 'all graded' ?>)
        </option>
      <?php endforeach; ?>
    </select>
    <noscript><button class="btn btn--pill" type="submit">Switch quiz</button></noscript>
  </form>
<?php endif; ?>

<!-- Mode tabs & Blind mode pill -->
<div class="grading-subbar">
  <div class="grading-tabs" role="tablist" aria-label="Grading mode">
    <button
      type="button"
      class="grading-tab is-active"
      id="tab-stack"
      role="tab"
      aria-selected="true"
      data-mode="stack"
    >Question by question</button>
    <button
      type="button"
      class="grading-tab"
      id="tab-paper"
      role="tab"
      aria-selected="false"
      data-mode="paper"
    >Whole paper</button>
  </div>

  <div class="grading-subbar__actions">
    <button
      type="button"
      class="btn-blind-pill is-on"
      id="btn-blind-toggle"
      aria-pressed="true"
      title="Toggle student identity visibility"
    >
      <span class="btn-blind-pill__dot" aria-hidden="true"></span>
      <span id="blind-toggle-label">Blind mode on</span>
    </button>
    <button
      type="button"
      class="btn-help-pill"
      id="btn-shortcuts-help"
      aria-label="Keyboard shortcuts (?)"
      title="Keyboard shortcuts (?)"
    >?</button>
  </div>
</div>

<!-- Toolbar: Question / Paper context on left, Previous / Next / Undo on right -->
<div class="grading-toolbar">
  <div class="grading-toolbar__meta">
    <div class="grading-toolbar__title" id="toolbar-context-title">
      <?php if (!empty($workspace['stack_items'])):
          $firstItem = $workspace['stack_items'][0];
      ?>
        Question <?= (int) $firstItem['question_pos'] ?> · <?= e($firstItem['type_label']) ?> · <?= (float) $firstItem['max_points'] ?> points
      <?php else: ?>
        No written responses waiting in stack
      <?php endif; ?>
    </div>
    <div class="grading-toolbar__sub" id="toolbar-context-sub">
      <?php if (!empty($workspace['stack_items'])): ?>
        Response 1 of <?= count($workspace['stack_items']) ?><?= $pendingCount > 0 ? ' (' . $pendingCount . ' pending)' : '' ?> · Oldest first
      <?php else: ?>
        Switch to Whole paper to review or override any question across submitted attempts.
      <?php endif; ?>
    </div>

    <!-- Optional question filter in Question-by-question mode & paper selector in Whole-paper mode -->
    <div class="grading-toolbar__selectors">
      <label class="visually-hidden" for="select-stack-question">Jump to question</label>
      <select class="input input--sm" id="select-stack-question" hidden>
        <option value="0">All written questions</option>
      </select>

      <label class="visually-hidden" for="select-whole-paper">Select student paper</label>
      <select class="input input--sm" id="select-whole-paper" hidden></select>
    </div>
  </div>

  <div class="grading-toolbar__nav">
    <button type="button" class="btn-pill-nav" id="btn-prev-item" disabled>‹ Previous</button>
    <button type="button" class="btn-pill-nav" id="btn-next-item">Next ›</button>
    <button type="button" class="btn-pill-nav" id="btn-undo-mark" disabled title="Undo last mark (U)">Undo last mark</button>
  </div>
</div>

<!-- Live region for screen readers & status announcements -->
<div class="visually-hidden" aria-live="polite" id="grading-live-region"></div>

<!-- Empty state when there are no submitted attempts at all -->
<div id="grading-empty-state" class="card grading-empty-card" <?= !empty($workspace['papers']) ? 'hidden' : '' ?>>
  <?= state_empty(
      'No submitted attempts yet',
      'Students have not submitted any attempts for this quiz yet. Check back after submissions arrive.',
      'Back to quizzes',
      '/teacher/quizzes.php'
  ) ?>
</div>

<!-- Empty state when Question-by-question stack has 0 written responses (e.g. objective-only quiz) -->
<div id="grading-no-written-state" class="card grading-empty-card" hidden>
  <div class="state">
    <h3>No written responses to grade</h3>
    <p>This quiz has no non-blank written responses. All objective questions and blank written answers were scored automatically. Use Whole paper mode to review attempts or override auto-graded items.</p>
    <button type="button" class="btn btn--primary btn--pill" id="btn-switch-to-paper">Open whole paper mode</button>
  </div>
</div>

<!-- Main 2-column grading layout matching Figma "QUIVER Databases grading.png" -->
<div class="grading-grid" id="grading-main-grid" <?= empty($workspace['papers']) ? 'hidden' : '' ?>>
  <!-- Left Column: Prompt Card + Student Response Card (or Whole-Paper Item List + Active Card) -->
  <div class="grading-col-left">
    <!-- Whole-paper question strip (visible only in Whole paper mode) -->
    <div class="paper-question-strip card" id="paper-question-strip" hidden>
      <div class="paper-question-strip__head">
        <span class="paper-question-strip__title" id="paper-strip-student">PAPER K1</span>
        <span class="paper-question-strip__score" id="paper-strip-score">Total: 0 / <?= (float) $quizMeta['total_points'] ?> pts</span>
      </div>
      <div class="paper-question-strip__pills" id="paper-question-pills" role="list"></div>
    </div>

    <!-- Prompt Card -->
    <section class="card grading-prompt-card" aria-labelledby="lbl-prompt-eyebrow">
      <div class="grading-eyebrow" id="lbl-prompt-eyebrow">PROMPT</div>
      <div class="grading-prompt-text" id="card-prompt-text"></div>
    </section>

    <!-- Response Card -->
    <section class="card grading-response-card" aria-labelledby="card-response-eyebrow">
      <div class="grading-response-head">
        <span class="grading-eyebrow" id="card-response-eyebrow">ANONYMOUS RESPONSE · PAPER K1</span>
        <span class="grading-type-tag" id="card-response-type">Essay</span>
      </div>

      <div class="grading-response-body" id="card-response-body"></div>

      <div class="grading-auto-banner" id="card-auto-banner" hidden></div>

      <p class="grading-response-foot" id="card-response-foot">
        Identity stays hidden while blind mode is on. Marks and feedback are not visible to students until the appropriate release.
      </p>
    </section>

    <p class="grading-shortcuts-hint">
      Shortcuts · <kbd>F</kbd> full &nbsp; <kbd>H</kbd> half &nbsp; <kbd>0</kbd>/<kbd>Z</kbd> zero &nbsp; <kbd>P</kbd> points &nbsp; <kbd>N</kbd> note &nbsp; <kbd>U</kbd> undo &nbsp; <kbd>←</kbd>/<kbd>→</kbd> or <kbd>J</kbd>/<kbd>K</kbd> previous / next &nbsp; <kbd>Ctrl</kbd> + <kbd>Enter</kbd> save &amp; next &nbsp; <kbd>?</kbd> help
    </p>
  </div>

  <!-- Right Column: Key & Rubric Card + Scoring & Feedback Form Card -->
  <div class="grading-col-right">
    <!-- Key & Rubric Card -->
    <section class="card grading-rubric-card" aria-labelledby="rubric-heading">
      <h2 class="grading-rubric-title" id="rubric-heading">Key &amp; rubric</h2>
      <div class="grading-rubric-body" id="card-rubric-body"></div>
    </section>

    <!-- Scoring Form Card -->
    <section class="card grading-score-card" aria-label="Award points and feedback">
      <form id="grading-form" method="post" action="/api/grade.php" novalidate>
        <?= csrf_field() ?>
        <input type="hidden" name="action" value="grade">
        <input type="hidden" name="response_id" id="form-response-id" value="">

        <!-- Quick Award Pills: Full, Half, Zero -->
        <div class="grading-quick-pills" role="group" aria-label="Quick score presets">
          <button type="button" class="btn-quick-score" id="btn-score-full" data-preset="full">Full · 10</button>
          <button type="button" class="btn-quick-score" id="btn-score-half" data-preset="half">Half · 5</button>
          <button type="button" class="btn-quick-score" id="btn-score-zero" data-preset="zero">Zero · 0</button>
        </div>

        <div class="field grading-field">
          <label class="field__label" for="input-points-awarded" id="lbl-points-awarded">Points awarded · out of 10</label>
          <input
            type="number"
            step="0.25"
            min="0"
            max="10"
            class="input grading-points-input"
            id="input-points-awarded"
            name="points"
            required
          >
          <p class="grading-field-hint" id="hint-points-range">Use any value from 0 to 10.</p>
          <p class="field__error" id="err-points-awarded" hidden></p>
        </div>

        <div class="field grading-field">
          <label class="field__label" for="input-feedback">Feedback to student</label>
          <textarea
            class="input input--area grading-feedback-input"
            id="input-feedback"
            name="feedback"
            rows="3"
            maxlength="5000"
            placeholder="Optional note or explanation for the student..."
          ></textarea>
        </div>

        <div class="grading-save-state" id="grading-save-state">Draft mark · Not yet saved</div>

        <div class="grading-submit-row">
          <button type="submit" class="btn-save-next" id="btn-save-next">Save &amp; next →</button>
        </div>
      </form>
    </section>
  </div>
</div>

<!-- Keyboard Shortcuts Help Modal (?) -->
<div class="modal-overlay is-hidden" id="shortcuts-modal" role="dialog" aria-modal="true" aria-labelledby="shortcuts-modal-title" hidden>
  <div class="modal-card">
    <div class="modal-head-row">
      <div>
        <h2 id="shortcuts-modal-title">Keyboard shortcuts</h2>
        <p>Grade quickly without lifting your hands from the keyboard. Single-key shortcuts work whenever you are not typing inside a text field.</p>
      </div>
      <button type="button" class="modal-close-btn" id="btn-close-shortcuts" aria-label="Close dialog">×</button>
    </div>

    <div class="shortcuts-list">
      <div class="shortcuts-row"><dt><kbd>←</kbd> / <kbd>→</kbd> or <kbd>K</kbd> / <kbd>J</kbd></dt><dd>Previous / Next response</dd></div>
      <div class="shortcuts-row"><dt><kbd>Alt</kbd> + <kbd>←</kbd> / <kbd>→</kbd></dt><dd>Previous / Next response (even while inside an input)</dd></div>
      <div class="shortcuts-row"><dt><kbd>F</kbd></dt><dd>Award full points, save &amp; advance</dd></div>
      <div class="shortcuts-row"><dt><kbd>H</kbd></dt><dd>Award half points, save &amp; advance</dd></div>
      <div class="shortcuts-row"><dt><kbd>0</kbd> or <kbd>Z</kbd></dt><dd>Award zero points, save &amp; advance</dd></div>
      <div class="shortcuts-row"><dt><kbd>P</kbd></dt><dd>Focus "Points awarded" field</dd></div>
      <div class="shortcuts-row"><dt><kbd>N</kbd></dt><dd>Focus "Feedback to student" note field</dd></div>
      <div class="shortcuts-row"><dt><kbd>U</kbd></dt><dd>Undo last saved mark (records a new audit event)</dd></div>
      <div class="shortcuts-row"><dt><kbd>Ctrl</kbd> + <kbd>Enter</kbd></dt><dd>Save current mark &amp; advance to next</dd></div>
      <div class="shortcuts-row"><dt><kbd>?</kbd></dt><dd>Toggle this keyboard shortcuts reference</dd></div>
    </div>

    <div class="modal-actions">
      <button type="button" class="btn btn--primary btn--pill" id="btn-dismiss-shortcuts">Got it</button>
    </div>
  </div>
</div>

<script id="grading-initial-data" type="application/json"><?= json_encode([
    'csrf_token' => csrf_token(),
    'workspace'  => $workspace,
], $jsonFlags) ?></script>
<script src="/assets/js/grading.js" defer></script>
<?php teacher_end();

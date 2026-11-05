<?php
declare(strict_types=1);

require_once __DIR__ . '/../../app/bootstrap.php';
require_once __DIR__ . '/../../app/helpers/layout.php';

$user      = require_role('teacher');
$teacherId = (int) $user['id'];

$id = (int) ($_GET['id'] ?? $_POST['id'] ?? 0);
if ($id <= 0) {
    abort_404();
}

$quiz = QuizService::find($id, $teacherId);
if (!$quiz) {
    abort_404();
}

$isDraft   = $quiz['status'] === 'draft';
$classes   = QuizService::activeClassesForTeacher($teacherId);
$questions = QuizService::getQuestions($id);
$blockers  = QuizService::getBlockers($id, $teacherId);

// Handle POST actions
if (($_SERVER['REQUEST_METHOD'] ?? '') === 'POST') {
    csrf_verify();
    $action = post_str('action') ?: 'save_settings';

    // Duplicate action is allowed for any status
    if ($action === 'duplicate') {
        $newId = QuizService::duplicateAsVersion($id, $teacherId);
        flash_set('Quiz duplicated as new draft version.');
        redirect('/teacher/quiz.php?id=' . $newId);
    }

    // All mutating actions require draft status
    if (!$isDraft) {
        flash_set('Published quizzes are frozen and cannot be edited. Duplicate as a new version to make changes.', 'error');
        redirect('/teacher/quiz.php?id=' . $id);
    }

    if ($action === 'publish') {
        $result = QuizService::publish($id, $teacherId);
        if ($result['success']) {
            flash_set('Quiz published and frozen. Attempts will open according to schedule.');
        } else {
            flash_set('Cannot publish: ' . implode(' ', $result['blockers']), 'error');
        }
        redirect('/teacher/quiz.php?id=' . $id);
    }

    if ($action === 'add_from_bank') {
        $selectedIds = $_POST['bank_question_ids'] ?? [];
        if (!is_array($selectedIds) || empty($selectedIds)) {
            flash_set('No questions were selected to add.', 'error');
        } else {
            $count = QuizService::addBankQuestions($id, $teacherId, $selectedIds);
            flash_set("Added {$count} question(s) from Question Bank.");
        }
        redirect('/teacher/quiz.php?id=' . $id);
    }

    if ($action === 'update_points') {
        $qId = (int) post_str('quiz_question_id');
        $pts = (float) post_str('points');
        QuizService::updateQuestionPoints($id, $teacherId, $qId, $pts);

        if (!empty($_SERVER['HTTP_X_REQUESTED_WITH']) || (isset($_SERVER['HTTP_ACCEPT']) && str_contains($_SERVER['HTTP_ACCEPT'], 'application/json'))) {
            json_out(['success' => true]);
        }
        redirect('/teacher/quiz.php?id=' . $id);
    }

    if ($action === 'move_question') {
        $qId = (int) post_str('quiz_question_id');
        $dir = post_str('direction');
        if (in_array($dir, ['up', 'down'], true)) {
            QuizService::moveQuestion($id, $teacherId, $qId, $dir);
        }
        redirect('/teacher/quiz.php?id=' . $id);
    }

    if ($action === 'reorder') {
        $orderedIds = json_decode(post_str('ordered_ids'), true);
        if (is_array($orderedIds)) {
            QuizService::reorderQuestions($id, $teacherId, $orderedIds);
        }
        if (!empty($_SERVER['HTTP_X_REQUESTED_WITH']) || (isset($_SERVER['HTTP_ACCEPT']) && str_contains($_SERVER['HTTP_ACCEPT'], 'application/json'))) {
            json_out(['success' => true]);
        }
        redirect('/teacher/quiz.php?id=' . $id);
    }

    if ($action === 'remove_question') {
        $qId = (int) post_str('quiz_question_id');
        QuizService::removeQuestion($id, $teacherId, $qId);
        flash_set('Question removed from quiz draft.');
        redirect('/teacher/quiz.php?id=' . $id);
    }

    if ($action === 'edit_question_copy') {
        $qId    = (int) post_str('quiz_question_id');
        $prompt = post_str('prompt');
        $pts    = (float) post_str('points');
        $rubric = post_str('rubric');
        $opts   = $_POST['options'] ?? null;

        QuizService::updateQuestionCopy($id, $teacherId, $qId, [
            'prompt'  => $prompt,
            'points'  => $pts,
            'rubric'  => $rubric,
            'options' => is_array($opts) ? $opts : null,
        ]);
        flash_set('Question copy updated.');
        redirect('/teacher/quiz.php?id=' . $id);
    }

    // Default: save draft settings
    $form = [
        'title'            => post_str('title'),
        'class_id'        => (int) post_str('class_id'),
        'instructions'     => post_str('instructions'),
        'opens_at_manila'  => post_str('opens_at'),
        'closes_at_manila' => post_str('closes_at'),
        'duration_min'     => (int) post_str('duration_min'),
        'max_attempts'     => (int) post_str('max_attempts'),
        'score_policy'     => post_str('score_policy'),
        'hold_score'       => isset($_POST['hold_score']),
        'hold_review'      => isset($_POST['hold_review']),
    ];

    $errors = QuizService::validateSettings($form, $teacherId);
    if (!$errors) {
        QuizService::saveSettings($id, $teacherId, $form);
        flash_set('Quiz draft saved.');
        redirect('/teacher/quiz.php?id=' . $id);
    }
}

// Prepare display values
$form = [
    'title'              => $quiz['title'],
    'class_id'          => $quiz['class_id'],
    'instructions'       => $quiz['instructions'] ?? "Follow each question's instructions.",
    'opens_at_manila'    => to_manila_input($quiz['opens_at']),
    'closes_at_manila'   => to_manila_input($quiz['closes_at']),
    'duration_min'       => $quiz['duration_min'] ?? 45,
    'max_attempts'       => $quiz['max_attempts'] ?? 1,
    'score_policy'       => $quiz['score_policy'] ?? 'highest',
    'hold_score'         => ($quiz['score_release'] ?? 'manual') === 'manual',
    'hold_review'        => ($quiz['review_release'] ?? 'manual') === 'manual',
];

// Available bank questions for "+ From bank" modal
$bankQuestions = $isDraft ? QuizService::getAvailableBankQuestions($teacherId, $id) : [];

// Subtitle / breadcrumb matching Figma:
// Draft · IT301, Section B · New quiz · Last saved 29 Oct 2026, 12:08
$classLabel = ($quiz['course_code'] ? $quiz['course_code'] . ', ' : '') . $quiz['section'];
$lastSaved  = to_manila($quiz['updated_at'], 'j M Y, H:i');
$metaParts  = [
    ucfirst($quiz['status']),
    $classLabel,
    $quiz['previous_version_id'] ? 'v' . $quiz['version'] : 'New quiz',
    'Last saved ' . $lastSaved,
];
$sub = '<a href="/teacher/quizzes.php" class="crumb-link">Quizzes</a> · ' . e(implode(' · ', array_filter($metaParts)));

// Header action button: Back to quizzes button is always visible
$backBtn = '<a class="btn btn--pill" href="/teacher/quizzes.php">‹ Back to quizzes</a>';
$headerAction = '<div class="header-actions">'
    . $backBtn
    . ($isDraft
        ? ' <button class="btn btn--pill" type="submit" form="quiz-form">Save draft</button>'
        : ' <form class="inline-form" method="post" action="/teacher/quiz.php">' . csrf_field() . '<input type="hidden" name="id" value="' . $id . '"><input type="hidden" name="action" value="duplicate"><button class="btn btn--pill" type="submit">Duplicate as new version</button></form>')
    . '</div>';

teacher_start(
    $quiz['title'], 'quizzes', 'teacher',
    ['/assets/css/classes.css', '/assets/css/bank.css', '/assets/css/quizzes.css'],
    $sub,
    $headerAction
);
?>

<?php if (!$isDraft): ?>
  <div class="alert alert--notice" role="status">
    <strong>Published quiz is frozen.</strong> Quiz content and settings cannot be edited directly. To adjust this quiz, duplicate it as a new version.
  </div>
<?php endif; ?>

<?= error_summary($errors ?? []) ?>

<form method="post" action="/teacher/quiz.php" id="quiz-form" data-quiz-id="<?= (int) $quiz['id'] ?>" data-is-draft="<?= $isDraft ? '1' : '0' ?>" novalidate>
  <?= csrf_field() ?>
  <input type="hidden" name="id" value="<?= (int) $quiz['id'] ?>">
  <input type="hidden" name="action" value="save_settings">

  <div class="builder-grid">
    <!-- Left Pane: 4 Setting Groups -->
    <div class="builder-left">
      <!-- 1. BASICS -->
      <section class="section-group" aria-labelledby="sec-basics">
        <h3 class="section-title" id="sec-basics">▾ Basics</h3>
        <?= field('title', 'Quiz title', 'text', ['value' => $form['title'], 'error' => $errors['title'] ?? '', 'required' => true, 'disabled' => !$isDraft]) ?>

        <div class="field">
          <label class="field__label" for="class_id">Class</label>
          <select class="input" id="class_id" name="class_id"<?= !$isDraft ? ' disabled' : '' ?>>
            <?php foreach ($classes as $c): ?>
              <option value="<?= (int) $c['id'] ?>"<?= (int) $c['id'] === (int) $form['class_id'] ? ' selected' : '' ?>>
                <?= e($c['course_code'] ? $c['course_code'] . ' · ' . $c['name'] : $c['name']) ?> (<?= e($c['section']) ?>)
              </option>
            <?php endforeach; ?>
          </select>
          <?php if (isset($errors['class_id'])): ?><p class="field__error"><?= e($errors['class_id']) ?></p><?php endif; ?>
        </div>

        <div class="field">
          <label class="field__label" for="instructions">Instructions</label>
          <textarea class="input input--area" id="instructions" name="instructions" rows="2" maxlength="5000"<?= !$isDraft ? ' disabled' : '' ?>><?= e($form['instructions']) ?></textarea>
          <p class="builder-subtext">Instructions: Follow each question's instructions.</p>
        </div>
      </section>

      <!-- 2. SCHEDULE -->
      <section class="section-group" aria-labelledby="sec-schedule">
        <h3 class="section-title" id="sec-schedule">▾ Schedule</h3>
        <div class="schedule-row">
          <?= field('opens_at', 'Opens', 'datetime-local', ['value' => $form['opens_at_manila'], 'error' => $errors['opens_at'] ?? '', 'disabled' => !$isDraft]) ?>
          <?= field('closes_at', 'Closes', 'datetime-local', ['value' => $form['closes_at_manila'], 'error' => $errors['closes_at'] ?? '', 'disabled' => !$isDraft]) ?>
          <?= field('duration_min', 'Duration (minutes)', 'number', ['value' => (string) $form['duration_min'], 'error' => $errors['duration_min'] ?? '', 'min' => 1, 'max' => 600, 'disabled' => !$isDraft]) ?>
        </div>
        <p class="builder-subtext">Asia/Manila · UTC+8. Attempts end at the earlier of the timer or the closing time.</p>
      </section>

      <!-- 3. ATTEMPTS -->
      <section class="section-group" aria-labelledby="sec-attempts">
        <h3 class="section-title" id="sec-attempts">▾ Attempts</h3>
        <div class="attempts-row">
          <?= field('max_attempts', 'Allowed attempts', 'number', ['value' => (string) $form['max_attempts'], 'min' => 1, 'max' => 10, 'error' => $errors['max_attempts'] ?? '', 'disabled' => !$isDraft]) ?>
          <div class="field">
            <label class="field__label" for="score_policy">Score policy</label>
            <select class="input" id="score_policy" name="score_policy"<?= !$isDraft ? ' disabled' : '' ?>>
              <option value="highest"<?= $form['score_policy'] === 'highest' ? ' selected' : '' ?>>Highest</option>
              <option value="latest"<?= $form['score_policy'] === 'latest' ? ' selected' : '' ?>>Latest</option>
            </select>
          </div>
        </div>
        <p class="builder-subtext">Default: one attempt. Choose Highest or Latest when allowing more attempts. The selected policy is retained even with one attempt.</p>
      </section>

      <!-- 4. RESULTS -->
      <section class="section-group" aria-labelledby="sec-results">
        <h3 class="section-title" id="sec-results">▾ Results</h3>
        <label class="check-label">
          <input type="checkbox" name="hold_score" value="1"<?= $form['hold_score'] ? ' checked' : '' ?><?= !$isDraft ? ' disabled' : '' ?>>
          <span>Hold scores for teacher release</span>
        </label>
        <label class="check-label">
          <input type="checkbox" name="hold_review" value="1"<?= $form['hold_review'] ? ' checked' : '' ?><?= !$isDraft ? ' disabled' : '' ?>>
          <span>Hold answer review separately</span>
        </label>
        <p class="builder-subtext">Neither scores nor answer review appears until you release it. Review includes answer details and feedback, separate from the score.</p>
      </section>
    </div>

    <!-- Right Pane: Questions & Ready to Publish -->
    <div class="builder-right">
      <!-- Questions List Card -->
      <section class="questions-card">
        <div class="questions-head">
          <div>
            <h2>Questions</h2>
            <div class="questions-meta" id="questions-meta"><?= count($questions) ?> questions · <span id="total-points-display"><?= (float) $quiz['total_points'] ?></span> points total</div>
          </div>
          <?php if ($isDraft): ?>
            <button class="btn btn--pill btn--sm" type="button" id="btn-open-bank" data-open-modal="modal-bank">+ From bank</button>
          <?php endif; ?>
        </div>

        <?php if (empty($questions)): ?>
          <div class="questions-empty">
            <p><strong>No questions added yet</strong></p>
            <p class="muted-sm">Questions are added from the Question Bank and frozen upon publishing.</p>
          </div>
        <?php else: ?>
          <ul class="questions-list" id="questions-list">
            <?php foreach ($questions as $q): ?>
              <li class="q-row" data-id="<?= (int) $q['id'] ?>" draggable="<?= $isDraft ? 'true' : 'false' ?>">
                <span class="q-drag-handle" title="<?= $isDraft ? 'Drag to reorder' : '' ?>">:::</span>
                <span class="q-num"><?= sprintf('%02d', (int) $q['position']) ?></span>
                <span class="q-prompt" title="<?= e($q['prompt']) ?>"><?= e($q['prompt']) ?></span>
                <div class="q-actions">
                  <input
                    type="number"
                    step="0.25"
                    min="0"
                    class="q-points-input"
                    data-qid="<?= (int) $q['id'] ?>"
                    value="<?= (float) $q['points'] ?>"
                    title="Points for question <?= (int) $q['position'] ?>"
                    <?= !$isDraft ? 'disabled' : '' ?>
                  >
                  <button
                    type="submit"
                    form="move-form-<?= (int) $q['id'] ?>-up"
                    class="btn-icon-sq"
                    data-move-dir="up"
                    title="Move up"
                    <?= (int) $q['position'] <= 1 || !$isDraft ? 'disabled' : '' ?>
                  >↑</button>
                  <button
                    type="submit"
                    form="move-form-<?= (int) $q['id'] ?>-down"
                    class="btn-icon-sq"
                    data-move-dir="down"
                    title="Move down"
                    <?= (int) $q['position'] >= count($questions) || !$isDraft ? 'disabled' : '' ?>
                  >↓</button>
                  <button
                    type="button"
                    class="btn-link-action btn-edit-trigger"
                    data-q='<?= htmlspecialchars(json_encode($q), ENT_QUOTES, 'UTF-8') ?>'
                    <?= !$isDraft ? 'disabled' : '' ?>
                  >Edit</button>
                  <button
                    type="submit"
                    form="remove-form-<?= (int) $q['id'] ?>"
                    class="btn-remove-action"
                    title="Remove question"
                    <?= !$isDraft ? 'disabled' : '' ?>
                  >×</button>
                </div>
              </li>
            <?php endforeach; ?>
          </ul>
        <?php endif; ?>

        <p class="questions-footer-note">Drag a handle or use ↑ / ↓ to reorder. Question copies can be edited here without changing the question bank.</p>
      </section>

      <!-- Ready to Publish / Frozen Card -->
      <section class="publish-panel">
        <?php if ($isDraft): ?>
          <?php if (empty($blockers)): ?>
            <div class="publish-panel__status">
              <span class="publish-panel__ring publish-panel__ring--complete">✓</span>
              <div>
                <h4 class="publish-panel__title">Ready to publish</h4>
                <p class="publish-panel__desc">Title, class, schedule and all <?= count($questions) ?> answer keys are complete. Points total <?= (float) $quiz['total_points'] ?>; there are no blocking issues.</p>
                <p class="publish-panel__note">Publishing freezes quiz content and creates a scheduled quiz for <?= e($quiz['class_name'] . ($quiz['section'] ? ', ' . $quiz['section'] : '')) ?>. Check the dates and keys now; use a new version for later content changes.</p>
              </div>
            </div>
            <div class="publish-panel__footer">
              <span>Next: <?= e($quiz['window_formatted'] !== '—' ? $quiz['window_formatted'] : 'Unscheduled') ?></span>
              <button class="btn--publish-solid" type="submit" form="publish-quiz-form">Publish quiz</button>
            </div>
          <?php else: ?>
            <div class="publish-panel__status">
              <span class="publish-panel__ring publish-panel__ring--attention">!</span>
              <div>
                <h4 class="publish-panel__title">Not ready to publish</h4>
                <ul class="blocker-list">
                  <?php foreach ($blockers as $b): ?>
                    <li><?= e($b) ?></li>
                  <?php endforeach; ?>
                </ul>
                <p class="publish-panel__note">Resolve all blocking issues above to enable publishing. Published quizzes are frozen and cannot be changed.</p>
              </div>
            </div>
            <div class="publish-panel__footer">
              <span>Window: <?= e($quiz['window_formatted'] !== '—' ? $quiz['window_formatted'] : 'Unscheduled') ?></span>
              <button class="btn--publish-solid is-disabled" type="button" disabled title="Resolve blockers first">Publish quiz</button>
            </div>
          <?php endif; ?>
        <?php else: ?>
          <div class="publish-panel__status">
            <span class="publish-panel__ring publish-panel__ring--complete">✓</span>
            <div>
              <h4 class="publish-panel__title">Published and frozen</h4>
              <p class="publish-panel__desc">This quiz was published on <?= to_manila($quiz['published_at'], 'j M Y, H:i') ?>. All settings and question copies are frozen.</p>
              <p class="publish-panel__note">Attempts and answers are evaluated against this frozen copy. To modify questions or settings, duplicate as a new version.</p>
            </div>
          </div>
          <div class="publish-panel__footer">
            <span>Status: <?= ucfirst($quiz['status']) ?> · <?= e($quiz['phase']) ?></span>
            <button class="btn btn--pill" type="submit" form="duplicate-quiz-form">Duplicate as new version</button>
          </div>
        <?php endif; ?>
      </section>
    </div>
  </div>
</form>

<!-- Auxiliary Forms for POST Actions -->
<?php if ($isDraft): ?>
  <!-- Publish form -->
  <form method="post" action="/teacher/quiz.php" id="publish-quiz-form">
    <?= csrf_field() ?>
    <input type="hidden" name="id" value="<?= (int) $quiz['id'] ?>">
    <input type="hidden" name="action" value="publish">
  </form>

  <!-- Reorder & move forms -->
  <?php foreach ($questions as $q): ?>
    <form method="post" action="/teacher/quiz.php" id="move-form-<?= (int) $q['id'] ?>-up">
      <?= csrf_field() ?>
      <input type="hidden" name="id" value="<?= (int) $quiz['id'] ?>">
      <input type="hidden" name="action" value="move_question">
      <input type="hidden" name="quiz_question_id" value="<?= (int) $q['id'] ?>">
      <input type="hidden" name="direction" value="up">
    </form>
    <form method="post" action="/teacher/quiz.php" id="move-form-<?= (int) $q['id'] ?>-down">
      <?= csrf_field() ?>
      <input type="hidden" name="id" value="<?= (int) $quiz['id'] ?>">
      <input type="hidden" name="action" value="move_question">
      <input type="hidden" name="quiz_question_id" value="<?= (int) $q['id'] ?>">
      <input type="hidden" name="direction" value="down">
    </form>
    <form method="post" action="/teacher/quiz.php" id="remove-form-<?= (int) $q['id'] ?>" data-confirm="Remove question from draft?">
      <?= csrf_field() ?>
      <input type="hidden" name="id" value="<?= (int) $quiz['id'] ?>">
      <input type="hidden" name="action" value="remove_question">
      <input type="hidden" name="quiz_question_id" value="<?= (int) $q['id'] ?>">
    </form>
  <?php endforeach; ?>

  <!-- Modal: + From bank -->
  <div class="modal-overlay is-hidden" id="modal-bank" role="dialog" aria-modal="true" aria-labelledby="modal-bank-title" hidden>
    <div class="modal-card modal-card--lg">
      <div class="modal-head-row">
        <div>
          <h2 id="modal-bank-title">Add questions from bank</h2>
          <p>Select questions to copy into this quiz draft. Copies can be edited independently.</p>
        </div>
        <button type="button" class="modal-close-btn" data-close-modal aria-label="Close dialog">×</button>
      </div>

      <div class="bank-filter-row">
        <input type="text" class="input" id="bank-filter-text" placeholder="Search questions by keyword...">
        <select class="input" id="bank-filter-type">
          <option value="">All question types</option>
          <option value="single">Single choice</option>
          <option value="true_false">True / False</option>
          <option value="multi">Multi-select</option>
          <option value="short">Short answer</option>
          <option value="essay">Essay</option>
        </select>
      </div>

      <form method="post" action="/teacher/quiz.php" id="form-add-bank">
        <?= csrf_field() ?>
        <input type="hidden" name="id" value="<?= (int) $quiz['id'] ?>">
        <input type="hidden" name="action" value="add_from_bank">

        <div class="modal-body" id="bank-items-container">
          <?php if (empty($bankQuestions)): ?>
            <p class="muted-sm modal-empty-msg">No active questions found in your Question Bank. <a href="/teacher/bank.php" target="_blank">Author questions here ↗</a></p>
          <?php else: ?>
            <?php foreach ($bankQuestions as $bq): ?>
              <div class="bank-item-row" data-prompt="<?= e(mb_strtolower($bq['prompt'])) ?>" data-type="<?= e($bq['type']) ?>">
                <label>
                  <input type="checkbox" name="bank_question_ids[]" value="<?= (int) $bq['id'] ?>">
                  <div class="bank-item-content">
                    <p class="bank-item-prompt"><?= e($bq['prompt']) ?></p>
                    <div class="bank-item-meta">
                      <span class="badge-tag"><?= e(str_replace('_', ' ', $bq['type'])) ?></span>
                      <span><?= (float) $bq['points'] ?> pts</span>
                      <?php if (!empty($bq['in_this_quiz'])): ?>
                        <span class="badge-tag badge-added">Added</span>
                      <?php endif; ?>
                    </div>
                  </div>
                </label>
              </div>
            <?php endforeach; ?>
          <?php endif; ?>
        </div>

        <div class="modal-actions">
          <button class="btn btn--ghost btn--pill" type="button" id="btn-close-bank" data-close-modal>Cancel</button>
          <button class="btn btn--pill" type="submit" id="btn-submit-bank" <?= empty($bankQuestions) ? 'disabled' : '' ?>>Add selected questions</button>
        </div>
      </form>
    </div>
  </div>

  <!-- Modal: Edit Question Copy -->
  <div class="modal-overlay is-hidden" id="modal-edit-copy" role="dialog" aria-modal="true" aria-labelledby="modal-edit-title" hidden>
    <div class="modal-card">
      <div class="modal-head-row">
        <div>
          <h2 id="modal-edit-title">Edit question copy</h2>
          <p>Changes apply only to this quiz copy. Your Question Bank remains untouched.</p>
        </div>
        <button type="button" class="modal-close-btn" data-close-modal aria-label="Close dialog">×</button>
      </div>

      <form method="post" action="/teacher/quiz.php" id="form-edit-copy">
        <?= csrf_field() ?>
        <input type="hidden" name="id" value="<?= (int) $quiz['id'] ?>">
        <input type="hidden" name="action" value="edit_question_copy">
        <input type="hidden" name="quiz_question_id" id="edit-q-id" value="">

        <div class="modal-body">
          <div class="field">
            <label class="field__label" for="edit-prompt">Question prompt</label>
            <textarea class="input input--area" id="edit-prompt" name="prompt" rows="3" required></textarea>
          </div>

          <div class="field">
            <label class="field__label" for="edit-points">Points</label>
            <input type="number" step="0.25" min="0" class="input" id="edit-points" name="points" required>
          </div>

          <div id="edit-options-container" class="edit-options-list"></div>

          <div class="field mt-3">
            <label class="field__label" for="edit-rubric">Rubric / Grading notes (optional)</label>
            <textarea class="input input--area" id="edit-rubric" name="rubric" rows="2"></textarea>
          </div>
        </div>

        <div class="modal-actions">
          <button class="btn btn--ghost btn--pill" type="button" id="btn-close-edit" data-close-modal>Cancel</button>
          <button class="btn btn--pill" type="submit">Save changes</button>
        </div>
      </form>
    </div>
  </div>
<?php else: ?>
  <!-- Duplicate form for frozen quizzes -->
  <form method="post" action="/teacher/quiz.php" id="duplicate-quiz-form">
    <?= csrf_field() ?>
    <input type="hidden" name="id" value="<?= (int) $quiz['id'] ?>">
    <input type="hidden" name="action" value="duplicate">
  </form>
<?php endif; ?>

<script src="/assets/js/quizzes.js" defer></script>
<?php teacher_end();

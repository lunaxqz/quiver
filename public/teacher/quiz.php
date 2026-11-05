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

$isDraft = $quiz['status'] === 'draft';
$classes = QuizService::activeClassesForTeacher($teacherId);

$errors = [];
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

if (($_SERVER['REQUEST_METHOD'] ?? '') === 'POST') {
    csrf_verify();

    if (!$isDraft) {
        flash_set('Published quizzes are frozen and cannot be edited. Duplicate as a new version to make changes.', 'error');
        redirect('/teacher/quiz.php?id=' . $id);
    }

    $form['title']            = post_str('title');
    $form['class_id']        = (int) post_str('class_id');
    $form['instructions']     = post_str('instructions');
    $form['opens_at_manila']  = post_str('opens_at');
    $form['closes_at_manila'] = post_str('closes_at');
    $form['duration_min']     = (int) post_str('duration_min');
    $form['max_attempts']     = (int) post_str('max_attempts');
    $form['score_policy']     = post_str('score_policy');
    $form['hold_score']       = isset($_POST['hold_score']);
    $form['hold_review']      = isset($_POST['hold_review']);

    $errors = QuizService::validateSettings($form, $teacherId);
    if (!$errors) {
        QuizService::saveSettings($id, $teacherId, $form);
        flash_set('Quiz draft saved.');
        redirect('/teacher/quiz.php?id=' . $id);
    }
}

// Subtitle / breadcrumb matching Figma:
// Draft · IT301, Section B · Last saved 29 Oct 2026, 12:08
$classLabel = ($quiz['course_code'] ? $quiz['course_code'] . ', ' : '') . $quiz['section'];
$lastSaved  = to_manila($quiz['updated_at'], 'j M Y, H:i');
$metaParts  = [
    ucfirst($quiz['status']),
    $classLabel,
    $quiz['previous_version_id'] ? 'v' . $quiz['version'] : 'New quiz',
    'Last saved ' . $lastSaved,
];
$sub = implode(' · ', array_filter($metaParts));

teacher_start(
    $quiz['title'], 'quizzes', 'teacher',
    ['/assets/css/classes.css', '/assets/css/bank.css', '/assets/css/quizzes.css'],
    e($sub),
    $isDraft
        ? '<button class="btn btn--pill" type="submit" form="quiz-form">Save draft</button>'
        : '<a class="btn btn--pill" href="/teacher/quizzes.php">Back to quizzes</a>'
);
?>

<?php if (!$isDraft): ?>
  <div class="alert alert--notice" role="status">
    <strong>Published quiz is frozen.</strong> Quiz content and settings cannot be edited directly. To adjust this quiz, duplicate it as a new version.
  </div>
<?php endif; ?>

<?= error_summary($errors) ?>

<form method="post" action="/teacher/quiz.php" id="quiz-form" novalidate>
  <?= csrf_field() ?>
  <input type="hidden" name="id" value="<?= (int) $quiz['id'] ?>">

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
          <p class="builder-subtext">Instructions are shown to students on the quiz start screen before beginning.</p>
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

    <!-- Right Pane: Questions & Ready to Publish (Day 10) -->
    <div class="builder-right">
      <section class="card questions-card">
        <div class="questions-head">
          <div>
            <h2>Questions</h2>
            <div class="questions-meta"><?= (int) $quiz['questions_count'] ?> questions · <?= (float) $quiz['total_points'] ?> points total</div>
          </div>
          <?php if ($isDraft): ?>
            <a class="btn btn--pill btn--sm" href="/teacher/bank.php">+ From bank</a>
          <?php endif; ?>
        </div>

        <?php if ((int) $quiz['questions_count'] === 0): ?>
          <div class="questions-empty">
            <p><strong>No questions added yet</strong></p>
            <p class="muted-sm">Questions are added from the Question Bank and frozen upon publishing (Day 10).</p>
          </div>
        <?php endif; ?>
      </section>

      <section class="publish-panel">
        <div class="publish-panel__status">
          <span class="publish-panel__icon">✓</span>
          <div>
            <h4 class="publish-panel__title">Draft settings configured</h4>
            <p class="publish-panel__desc">Title, class, schedule, attempt limits, and release policy are saved. Check and publish arrive on Day 10.</p>
          </div>
        </div>
        <div class="publish-panel__footer">
          <span>Window: <?= e($quiz['window_formatted'] !== '—' ? $quiz['window_formatted'] : 'Unscheduled') ?></span>
          <button class="btn btn--pill btn--off" type="button" disabled title="Publish and freeze are enabled on Day 10">Publish quiz</button>
        </div>
      </section>
    </div>
  </div>
</form>

<?php teacher_end();
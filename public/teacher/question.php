<?php
declare(strict_types=1);

require_once __DIR__ . '/../../app/bootstrap.php';
require_once __DIR__ . '/../../app/helpers/layout.php';

$user      = require_role('teacher');
$teacherId = (int) $user['id'];

// Editing an existing question? It must be this teacher's, otherwise "not found".
$id = isset($_GET['id']) ? (int) $_GET['id'] : (int) post_str('id');
$existing = null;
if ($id > 0) {
    $existing = QuestionService::find($id, $teacherId);
    if ($existing === null) {
        abort_404();
    }
}

// Starting values for the form.
$form = [
    'type'    => $existing['type'] ?? 'single',
    'prompt'  => $existing['prompt'] ?? '',
    'points'  => $existing ? rtrim(rtrim(number_format((float) $existing['points'], 2, '.', ''), '0'), '.') : '1',
    'options' => array_fill(0, QuestionService::MAX_OPTIONS, ['label' => '', 'correct' => false]),
    'tf'      => '',
    'accepted' => [],
    'rubric'  => $existing['rubric'] ?? '',
    'tags'    => $existing['tags'] ?? [],
];
if ($existing) {
    if ($existing['type'] === 'true_false') {
        foreach ($existing['options'] as $o) {
            if ((int) $o['is_correct'] === 1) { $form['tf'] = strtolower($o['label']) === 'true' ? 'true' : 'false'; }
        }
    } elseif ($existing['type'] === 'short') {
        $form['accepted'] = array_map(static fn($o) => $o['label'], $existing['options']);
    } elseif ($existing['type'] !== 'essay') {
        foreach (array_slice($existing['options'], 0, QuestionService::MAX_OPTIONS) as $i => $o) {
            $form['options'][$i] = ['label' => $o['label'], 'correct' => (int) $o['is_correct'] === 1];
        }
    }
}
$errors = [];

if (($_SERVER['REQUEST_METHOD'] ?? '') === 'POST') {
    csrf_verify();
    // The type of an existing question never changes; a new one takes it from the form.
    $form['type']   = $existing['type'] ?? post_str('type');
    $form['prompt'] = post_str('prompt');
    $form['points'] = post_str('points');
    $form['tf']     = post_str('tf');
    $form['rubric'] = post_str('rubric');
    $acc = [];
    foreach (preg_split('/\R/u', post_str('accepted')) ?: [] as $line) {
        $line = trim(preg_replace('/\s+/u', ' ', $line) ?? '');
        if ($line !== '' && !isset($acc[mb_strtolower($line)])) {
            $acc[mb_strtolower($line)] = $line;
        }
    }
    $form['accepted'] = array_values($acc);
    $form['tags']   = QuestionService::parseTags(post_str('tags'));

    $labels  = $_POST['option'] ?? [];
    $correct = $form['type'] === 'multi'
        ? (is_array($_POST['correct_multi'] ?? null) ? array_map('strval', $_POST['correct_multi']) : [])
        : [post_str('correct_single')];
    $opts = [];
    for ($i = 0; $i < QuestionService::MAX_OPTIONS; $i++) {
        $label = is_array($labels) && isset($labels[$i]) && is_string($labels[$i]) ? trim($labels[$i]) : '';
        $opts[$i] = ['label' => $label, 'correct' => $label !== '' && in_array((string) $i, $correct, true)];
    }
    $form['options'] = $opts;

    $errors = QuestionService::validate($form);
    if (!$errors) {
        $savedId = QuestionService::save($teacherId, $existing ? (int) $existing['id'] : null, [
            'type' => $form['type'], 'prompt' => $form['prompt'], 'points' => number_format((float) $form['points'], 2, '.', ''),
            'options' => $form['options'], 'tf' => $form['tf'], 'tags' => $form['tags'],
            'accepted' => $form['accepted'], 'rubric' => $form['rubric'],
        ]);
        flash_set($existing ? 'Question saved.' : 'Question created.');
        redirect('/teacher/question.php?id=' . $savedId);
    }
}

$editable = in_array($form['type'], QuestionService::EDITABLE, true);
$used     = (int) ($existing['used_in'] ?? 0);

$crumb = '<a href="/teacher/bank.php">Question bank</a>'
       . ($form['tags'] ? ' / ' . e($form['tags'][0]) : '')
       . ($existing ? ' / ' . e(mb_strimwidth($existing['prompt'], 0, 40, '…')) : ' / New question');

teacher_start(
    $existing ? 'Edit question' : 'New question', 'bank', 'teacher', ['/assets/css/classes.css', '/assets/css/bank.css'],
    $crumb, '<a class="btn btn--pill" href="/teacher/bank.php">Back to bank</a>'
);

if ($existing && !$editable) {
    echo state_empty('This question type is edited on Day 8', QuestionService::TYPES[$existing['type']] . ' questions can be viewed in the bank for now.', 'Back to the bank', '/teacher/bank.php');
    teacher_end();
    exit;
}

// How many choice rows to draw: four for a new question, otherwise as many as are filled (at least two).
$last = -1;
foreach ($form['options'] as $i => $o) {
    if ($o['label'] !== '') { $last = $i; }
}
$rowCount = max($existing ? 2 : 4, $last + 1);
$letters  = 'ABCDEF';
$hint     = [
    'single' => 'One correct option.', 'multi' => 'One or more correct options.', 'true_false' => 'Two fixed options: True and False.',
    'short' => 'Marked automatically against your accepted answers.', 'essay' => 'Marked by you, using the rubric.',
];
?>
<?php if ($used > 0): ?>
  <div class="alert alert--notice" role="status">
    <strong>Used in <?= $used ?> quiz<?= $used === 1 ? '' : 'zes' ?>.</strong>
    Saving changes this bank question only. Quizzes keep their own copies, so existing quizzes and grades do not change.
  </div>
<?php endif; ?>
<?= error_summary($errors) ?>

<form method="post" action="/teacher/question.php" id="editor" data-type="<?= e($form['type']) ?>" novalidate>
  <?= csrf_field() ?>
  <?php if ($existing): ?><input type="hidden" name="id" value="<?= (int) $existing['id'] ?>"><?php endif; ?>
  <div class="editor-grid">
    <section class="card editor-form" aria-labelledby="details-title">
      <h2 id="details-title">Question details</h2>

      <div class="row-type">
        <div class="field">
          <label class="field__label" for="type">Type</label>
          <select class="input" id="type" name="type"<?= $existing ? ' disabled' : '' ?>>
            <?php foreach (QuestionService::EDITABLE as $t): ?>
              <option value="<?= e($t) ?>"<?= $form['type'] === $t ? ' selected' : '' ?>><?= e(QuestionService::TYPES[$t]) ?></option>
            <?php endforeach; ?>
          </select>
          <p class="field__hint" id="type-hint" data-hints='<?= e(json_encode($hint)) ?>'><?= e($hint[$form['type']] ?? '') ?><?= $existing ? ' A saved question’s type cannot change.' : '' ?></p>
        </div>
        <?= field('points', 'Points', 'text', ['value' => $form['points'], 'error' => $errors['points'] ?? '', 'maxlength' => 6]) ?>
      </div>

      <div class="field">
        <label class="field__label" for="prompt">Prompt</label>
        <textarea class="input input--area" id="prompt" name="prompt" rows="4" maxlength="5000" required<?= isset($errors['prompt']) ? ' aria-invalid="true"' : '' ?>><?= e($form['prompt']) ?></textarea>
        <?php if (isset($errors['prompt'])): ?><p class="field__error"><?= e($errors['prompt']) ?></p><?php endif; ?>
      </div>

      <!-- single-select and multi-select -->
      <div class="field only-choice">
        <p class="eyebrow">Options · select the correct key</p>
        <div id="opts" data-max="<?= QuestionService::MAX_OPTIONS ?>">
        <?php for ($i = 0; $i < $rowCount; $i++): $o = $form['options'][$i]; ?>
          <div class="opt-row">
            <input class="key only-single" type="radio" name="correct_single" value="<?= $i ?>" aria-label="Option <?= $letters[$i] ?> is the correct key"<?= $o['correct'] && $form['type'] !== 'multi' ? ' checked' : '' ?>>
            <input class="key only-multi" type="checkbox" name="correct_multi[]" value="<?= $i ?>" aria-label="Option <?= $letters[$i] ?> is a correct key"<?= $o['correct'] && $form['type'] === 'multi' ? ' checked' : '' ?>>
            <label class="opt-box"<?= isset($errors['option_' . $i]) ? ' data-invalid' : '' ?>>
              <span class="opt-letter" aria-hidden="true"><?= $letters[$i] ?></span>
              <input class="opt-input" type="text" name="option[<?= $i ?>]" maxlength="500" aria-label="Option <?= $letters[$i] ?>" value="<?= e($o['label']) ?>">
              <span class="opt-badge" aria-hidden="true">Correct key</span>
            </label>
            <button class="opt-remove" type="button" aria-label="Remove option <?= $letters[$i] ?>">×</button>
          </div>
          <?php if (isset($errors['option_' . $i])): ?><p class="field__error" id="option_<?= $i ?>"><?= e($errors['option_' . $i]) ?></p><?php endif; ?>
        <?php endfor; ?>
        </div>
        <?php if (isset($errors['correct'])): ?><p class="field__error" id="correct"><?= e($errors['correct']) ?></p><?php endif; ?>
        <button class="btn btn--pill btn--quiet btn--sm" type="button" id="add-opt">+ Add option</button>
      </div>

      <!-- true / false -->
      <div class="field only-tf">
        <p class="eyebrow">Correct answer</p>
        <label class="check"><input type="radio" name="tf" value="true"<?= $form['tf'] === 'true' ? ' checked' : '' ?>><span>True</span></label>
        <label class="check"><input type="radio" name="tf" value="false"<?= $form['tf'] === 'false' ? ' checked' : '' ?>><span>False</span></label>
        <?php if (isset($errors['tf'])): ?><p class="field__error" id="tf"><?= e($errors['tf']) ?></p><?php endif; ?>
      </div>

      <!-- short answer -->
      <div class="field only-short">
        <label class="eyebrow" for="accepted">Accepted answers · one per line</label>
        <textarea class="input input--area" id="accepted" name="accepted" rows="4" placeholder="e.g. Domain Name System"<?= isset($errors['accepted']) ? ' aria-invalid="true"' : '' ?>><?= e(implode("\n", $form['accepted'])) ?></textarea>
        <?php if (isset($errors['accepted'])): ?><p class="field__error" id="accepted-error"><?= e($errors['accepted']) ?></p><?php endif; ?>
        <p class="field__hint">Capital letters and extra spaces are ignored when a student's answer is checked.</p>
      </div>

      <!-- essay -->
      <div class="field only-essay">
        <label class="eyebrow" for="rubric">Marking rubric · what a full-credit answer includes</label>
        <textarea class="input input--area" id="rubric" name="rubric" rows="5" maxlength="5000"<?= isset($errors['rubric']) ? ' aria-invalid="true"' : '' ?>><?= e($form['rubric']) ?></textarea>
        <?php if (isset($errors['rubric'])): ?><p class="field__error" id="rubric-error"><?= e($errors['rubric']) ?></p><?php endif; ?>
        <p class="field__hint">Only you see the rubric. Essays are marked by hand on the grading screen.</p>
      </div>

      <div class="field">
        <p class="eyebrow" id="tags-label">Tags</p>
        <div class="tag-box" id="tags" role="group" aria-labelledby="tags-label"<?= isset($errors['tags']) ? ' data-invalid' : '' ?>>
          <input type="hidden" name="tags" id="tags-value" value="<?= e(implode(', ', $form['tags'])) ?>">
          <input class="tag-add" type="text" id="tag-add" maxlength="40" placeholder="+ Add tag" aria-label="Add a tag, then press Enter">
        </div>
        <?php if (isset($errors['tags'])): ?><p class="field__error"><?= e($errors['tags']) ?></p><?php endif; ?>
        <p class="field__hint">Type-aware fields: true/false uses two options; multi-select allows multiple keys; short answer uses accepted text; essay uses a marking rubric.</p>
      </div>
    </section>

    <div class="editor-side">
      <aside class="card preview" aria-labelledby="preview-title">
        <div class="card__row">
          <p class="eyebrow" id="preview-title">Student preview</p>
          <span class="muted-sm" id="pv-points"></span>
        </div>
        <p class="preview__prompt reading" id="pv-prompt"></p>
        <ul class="preview__opts" id="pv-opts"></ul>
        <p class="foot-note">Preview only. Students never see the correct key while answering.</p>
      </aside>
      <section class="card">
        <h2>Safe to improve</h2>
        <p class="muted-sm">
          <?php if ($existing): ?>
            Used in <?= $used ?> quiz<?= $used === 1 ? '' : 'zes' ?>. Saving updates this bank question only. Existing quiz copies and published content remain unchanged; add the updated question to a new draft when needed.
          <?php else: ?>
            Saving creates a master copy in your bank. A quiz takes its own copy when you add it, so later edits never change an old quiz or grade.
          <?php endif; ?>
        </p>
      </section>
    </div>
  </div>

  <div class="save-bar">
    <button class="btn btn--primary btn--pill" type="submit"><?= $existing ? 'Save question' : 'Create question' ?></button>
    <a class="btn btn--pill" href="/teacher/bank.php">Cancel</a>
    <span class="foot-note" id="dirty" hidden>Unsaved changes</span>
  </div>
</form>
<script src="/assets/js/question-editor.js" defer></script>
<?php teacher_end();

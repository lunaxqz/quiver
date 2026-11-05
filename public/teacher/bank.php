<?php
declare(strict_types=1);

require_once __DIR__ . '/../../app/bootstrap.php';
require_once __DIR__ . '/../../app/helpers/layout.php';

$user      = require_role('teacher');
$teacherId = (int) $user['id'];

function bank_url(array $p): string
{
    $p = array_filter($p, static fn($v) => $v !== null && $v !== '' && $v !== 0 && $v !== 1 && $v !== 'active');
    return '/teacher/bank.php' . ($p ? '?' . http_build_query($p) : '');
}

$tab      = ($_GET['tab'] ?? $_POST['tab'] ?? '') === 'archived' ? 'archived' : 'active';
$archived = $tab === 'archived';
$q    = mb_substr(trim((string) ($_GET['q'] ?? $_POST['q'] ?? '')), 0, 100);
$type = (string) ($_GET['type'] ?? $_POST['type'] ?? '');
$tag  = mb_substr(trim((string) ($_GET['tag'] ?? $_POST['tag'] ?? '')), 0, 40);
if (!isset(QuestionService::TYPES[$type])) {
    $type = '';
}
$page = max(1, (int) ($_GET['p'] ?? $_POST['p'] ?? 1));

// Archive / restore (a bank question is hidden, never deleted; quizzes keep their own copies).
if (($_SERVER['REQUEST_METHOD'] ?? '') === 'POST') {
    csrf_verify();
    $qid    = (int) post_str('question_id');
    $action = post_str('action');
    if (!in_array($action, ['archive', 'restore'], true) || QuestionService::find($qid, $teacherId) === null) {
        abort_404();
    }
    QuestionService::setArchived($qid, $teacherId, $action === 'archive');
    flash_set($action === 'archive' ? 'Question archived. Quizzes that use it are not changed.' : 'Question restored to the active bank.');
    redirect(bank_url(['tab' => $tab, 'q' => $q, 'type' => $type, 'tag' => $tag, 'p' => $page]));
}

$total = QuestionService::count($teacherId, $q, $type, $tag, $archived);
$pages = max(1, (int) ceil($total / QuestionService::PER_PAGE));
$page  = min($page, $pages);
$rows  = QuestionService::list($teacherId, $q, $type, $tag, $page, $archived);
$tags  = QuestionService::tagsFor($teacherId);
$filtered    = $q !== '' || $type !== '' || $tag !== '';
$activeTotal = QuestionService::count($teacherId);
$archTotal   = QuestionService::count($teacherId, '', '', '', true);

teacher_start(
    'Question bank', 'bank', 'teacher', ['/assets/css/classes.css', '/assets/css/bank.css'],
    'Reusable questions, kept separate from the quizzes that use them.',
    '<a class="btn btn--primary btn--pill" href="/teacher/question.php">+ Add question</a>'
);
?>
<section class="card bank-card">
  <nav class="tabs" aria-label="Bank sections">
    <a href="<?= e(bank_url([])) ?>"<?= !$archived ? ' aria-current="page"' : '' ?>>Active questions · <?= $activeTotal ?></a>
    <a href="<?= e(bank_url(['tab' => 'archived'])) ?>"<?= $archived ? ' aria-current="page"' : '' ?>>Archived · <?= $archTotal ?></a>
  </nav>

  <form class="bank-filters" method="get" action="/teacher/bank.php">
    <?php if ($archived): ?><input type="hidden" name="tab" value="archived"><?php endif; ?>
    <?= field('q', 'Search questions', 'text', ['value' => $q, 'placeholder' => 'Prompt or keyword', 'maxlength' => 100]) ?>
    <div class="field">
      <label class="field__label" for="type">Question type</label>
      <select class="input" id="type" name="type" data-autosubmit>
        <option value="">All types</option>
        <?php foreach (QuestionService::TYPES as $k => $label): ?>
          <option value="<?= e($k) ?>"<?= $k === $type ? ' selected' : '' ?>><?= e($label) ?></option>
        <?php endforeach; ?>
      </select>
    </div>
    <div class="field">
      <label class="field__label" for="tag">Tags</label>
      <select class="input" id="tag" name="tag" data-autosubmit>
        <option value="">All tags</option>
        <?php foreach ($tags as $t): ?>
          <option value="<?= e($t) ?>"<?= strcasecmp($t, $tag) === 0 ? ' selected' : '' ?>><?= e($t) ?></option>
        <?php endforeach; ?>
      </select>
    </div>
    <noscript><button class="btn btn--pill" type="submit">Apply</button></noscript>
  </form>

  <?php if ($total === 0): ?>
    <?php if ($filtered): ?>
      <?= state_empty('No questions match', 'Try different words, or clear the type and tag filters.', 'Clear filters', bank_url(['tab' => $tab])) ?>
    <?php elseif ($archived): ?>
      <?= state_empty('Nothing archived', 'Questions you archive wait here. You can restore them at any time.') ?>
    <?php else: ?>
      <?= state_empty('Your bank is empty', 'Write your first question. You can reuse it in any quiz.', 'Add question', '/teacher/question.php') ?>
    <?php endif; ?>
  <?php else: ?>
    <table class="tbl tbl--bank">
      <thead><tr>
        <th scope="col">Question</th><th scope="col">Type</th><th scope="col">Tags</th>
        <th scope="col">Used in</th><th scope="col">Actions</th>
      </tr></thead>
      <tbody>
      <?php foreach ($rows as $r):
          $tagList = array_filter(explode(',', (string) $r['tags']));
          $n = (int) $r['used_in'];
      ?>
        <tr>
          <td class="q-prompt">
            <a href="/teacher/question.php?id=<?= (int) $r['id'] ?>"><?= e(mb_strimwidth($r['prompt'], 0, 90, '…')) ?></a>
            <span class="q-meta"><?= e($user['full_name']) ?> · Updated <?= e(to_manila($r['updated_at'], 'j M Y')) ?></span>
          </td>
          <td><?= e(QuestionService::TYPES[$r['type']] ?? $r['type']) ?></td>
          <td><?= $tagList ? e(implode(' · ', $tagList)) : '<span class="muted-sm">None</span>' ?></td>
          <td><?= $n ?> quiz<?= $n === 1 ? '' : 'zes' ?></td>
          <td class="row-actions">
            <a href="/teacher/question.php?id=<?= (int) $r['id'] ?>">Edit</a>
            <form class="inline-form" method="post" action="/teacher/bank.php"<?= $archived ? '' : ' data-confirm="Archive this question? Quizzes that already use it are not changed."' ?>>
              <?= csrf_field() ?>
              <input type="hidden" name="action" value="<?= $archived ? 'restore' : 'archive' ?>">
              <input type="hidden" name="question_id" value="<?= (int) $r['id'] ?>">
              <input type="hidden" name="tab" value="<?= e($tab) ?>"><input type="hidden" name="q" value="<?= e($q) ?>">
              <input type="hidden" name="type" value="<?= e($type) ?>"><input type="hidden" name="tag" value="<?= e($tag) ?>">
              <input type="hidden" name="p" value="<?= $page ?>">
              <button class="linklike linklike--quiet" type="submit"><?= $archived ? 'Restore' : 'Archive' ?></button>
            </form>
          </td>
        </tr>
      <?php endforeach; ?>
      </tbody>
    </table>
    <div class="pager">
      <span class="foot-note">Showing <?= count($rows) ?> of <?= $total ?> <?= $archived ? 'archived' : 'active' ?> question<?= $total === 1 ? '' : 's' ?></span>
      <span class="pager__btns">
        <?php if ($page > 1): ?>
          <a class="btn btn--pill" href="<?= e(bank_url(['tab' => $tab, 'q' => $q, 'type' => $type, 'tag' => $tag, 'p' => $page - 1])) ?>">‹ Previous</a>
        <?php else: ?><span class="btn btn--pill btn--off" aria-disabled="true">‹ Previous</span><?php endif; ?>
        <?php if ($page < $pages): ?>
          <a class="btn btn--pill" href="<?= e(bank_url(['tab' => $tab, 'q' => $q, 'type' => $type, 'tag' => $tag, 'p' => $page + 1])) ?>">Next ›</a>
        <?php else: ?><span class="btn btn--pill btn--off" aria-disabled="true">Next ›</span><?php endif; ?>
      </span>
    </div>
  <?php endif; ?>
</section>

<div class="info-pair">
  <section class="card">
    <h2>One bank, many quizzes</h2>
    <p class="muted-sm">Single-select, true/false and multi-select are scored from a key. Short answers can use accepted answers; essays are marked with a rubric.</p>
  </section>
  <section class="card">
    <h2>Edits stay in the bank</h2>
    <p class="muted-sm">Quiz questions are independent copies. Editing or archiving a bank question does not change existing quizzes or their records.</p>
  </section>
</div>
<script src="/assets/js/classes.js" defer></script>
<?php teacher_end();

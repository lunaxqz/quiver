<?php
declare(strict_types=1);

require_once __DIR__ . '/../../app/bootstrap.php';
require_once __DIR__ . '/../../app/helpers/layout.php';

$user      = require_role('teacher');
$teacherId = (int) $user['id'];

function quizzes_url(array $p): string
{
    $p = array_filter($p, static fn($v) => $v !== null && $v !== '' && $v !== 0 && $v !== 1 && $v !== 'all');
    return '/teacher/quizzes.php' . ($p ? '?' . http_build_query($p) : '');
}

$tab      = (string) ($_GET['tab'] ?? $_POST['tab'] ?? 'all');
if (!in_array($tab, ['all', 'drafts', 'published', 'archived'], true)) {
    $tab = 'all';
}
$q        = mb_substr(trim((string) ($_GET['q'] ?? $_POST['q'] ?? '')), 0, 100);
$classId  = (string) ($_GET['class_id'] ?? $_POST['class_id'] ?? '');
$state    = (string) ($_GET['state'] ?? $_POST['state'] ?? '');
$page     = max(1, (int) ($_GET['p'] ?? $_POST['p'] ?? 1));

// Actions: archive, restore, duplicate, or create draft
if (($_SERVER['REQUEST_METHOD'] ?? '') === 'POST') {
    csrf_verify();
    $action = post_str('action');

    if ($action === 'create_draft') {
        $title = post_str('title');
        $cId   = (int) post_str('class_id');
        try {
            $newId = QuizService::createDraft($teacherId, $cId, $title);
            flash_set('Draft quiz created. Set the schedule and settings below.');
            redirect('/teacher/quiz.php?id=' . $newId);
        } catch (Throwable $e) {
            flash_set('Could not create quiz: ' . $e->getMessage(), 'error');
            redirect(quizzes_url(['tab' => $tab, 'q' => $q, 'class_id' => $classId, 'state' => $state]));
        }
    }

    $quizId = (int) post_str('quiz_id');
    if ($action === 'archive' || $action === 'restore') {
        QuizService::setArchived($quizId, $teacherId, $action === 'archive');
        flash_set($action === 'archive' ? 'Quiz archived.' : 'Quiz restored.');
        redirect(quizzes_url(['tab' => $tab, 'q' => $q, 'class_id' => $classId, 'state' => $state, 'p' => $page]));
    }

    if ($action === 'duplicate') {
        try {
            $newId = QuizService::duplicateAsVersion($quizId, $teacherId);
            flash_set('Created new draft version.');
            redirect('/teacher/quiz.php?id=' . $newId);
        } catch (Throwable $e) {
            flash_set('Could not duplicate: ' . $e->getMessage(), 'error');
            redirect(quizzes_url(['tab' => $tab, 'q' => $q, 'class_id' => $classId, 'state' => $state, 'p' => $page]));
        }
    }
}

$classes     = QuizService::activeClassesForTeacher($teacherId);
$activeCount = QuizService::countForTeacher($teacherId, 'all');
$draftCount  = QuizService::countForTeacher($teacherId, 'drafts');
$pubCount    = QuizService::countForTeacher($teacherId, 'published');
$archCount   = QuizService::countForTeacher($teacherId, 'archived');

$data        = QuizService::listForTeacher($teacherId, $q, $classId, $state, $tab, $page);
$rows        = $data['items'];
$total       = $data['total'];
$pages       = $data['pages'];

$openCount = 0;
foreach ($rows as $r) {
    if ($r['phase'] === 'open') {
        $openCount++;
    }
}

teacher_start(
    'Quizzes', 'quizzes', 'teacher',
    ['/assets/css/classes.css', '/assets/css/bank.css', '/assets/css/quizzes.css'],
    (string) $activeCount . ' active quiz' . ($activeCount === 1 ? '' : 'zes') . ' · Windows shown in Asia/Manila (UTC+8).',
    '<button class="btn btn--primary btn--pill" type="button" data-open-modal="create-modal">+ Create quiz</button>'
);
?>

<section class="card quizzes-card">
  <nav class="tabs" aria-label="Quiz sections">
    <a href="<?= e(quizzes_url([])) ?>"<?= $tab === 'all' ? ' aria-current="page"' : '' ?>>All quizzes</a>
    <a href="<?= e(quizzes_url(['tab' => 'drafts'])) ?>"<?= $tab === 'drafts' ? ' aria-current="page"' : '' ?>>Drafts</a>
    <a href="<?= e(quizzes_url(['tab' => 'published'])) ?>"<?= $tab === 'published' ? ' aria-current="page"' : '' ?>>Published</a>
    <a href="<?= e(quizzes_url(['tab' => 'archived'])) ?>"<?= $tab === 'archived' ? ' aria-current="page"' : '' ?>>Archived</a>
  </nav>

  <form class="quizzes-filters" method="get" action="/teacher/quizzes.php">
    <?php if ($tab !== 'all'): ?><input type="hidden" name="tab" value="<?= e($tab) ?>"><?php endif; ?>
    <?= field('q', 'Search quizzes', 'text', ['value' => $q, 'placeholder' => 'Title or class', 'maxlength' => 100]) ?>
    <div class="field">
      <label class="field__label" for="class_id">Class</label>
      <select class="input" id="class_id" name="class_id" onchange="this.form.submit()">
        <option value="">All your classes</option>
        <?php foreach ($classes as $c): ?>
          <option value="<?= (int) $c['id'] ?>"<?= (string) $c['id'] === $classId ? ' selected' : '' ?>>
            <?= e($c['course_code'] ? $c['course_code'] . ' · ' . $c['name'] : $c['name']) ?> (<?= e($c['section']) ?>)
          </option>
        <?php endforeach; ?>
      </select>
    </div>
    <div class="field">
      <label class="field__label" for="state">Window state</label>
      <select class="input" id="state" name="state" onchange="this.form.submit()">
        <option value="">All states</option>
        <option value="open"<?= $state === 'open' ? ' selected' : '' ?>>Open</option>
        <option value="scheduled"<?= $state === 'scheduled' ? ' selected' : '' ?>>Scheduled</option>
        <option value="closed"<?= $state === 'closed' ? ' selected' : '' ?>>Closed</option>
        <option value="draft"<?= $state === 'draft' ? ' selected' : '' ?>>Draft</option>
      </select>
    </div>
    <noscript><button class="btn btn--pill" type="submit">Filter</button></noscript>
  </form>

  <?php if ($total === 0): ?>
    <?php if ($q !== '' || $classId !== '' || $state !== ''): ?>
      <?= state_empty('No quizzes match', 'Try adjusting your search keywords or clearing the state filter.', 'Clear filters', quizzes_url(['tab' => $tab])) ?>
    <?php elseif ($tab === 'drafts'): ?>
      <?= state_empty('No draft quizzes', 'Create a new quiz draft to prepare questions and schedule.', 'Create quiz', '#', 'data-open-modal="create-modal"') ?>
    <?php elseif ($tab === 'archived'): ?>
      <?= state_empty('No archived quizzes', 'Quizzes you archive stay here with their student records intact.') ?>
    <?php else: ?>
      <?= state_empty('No quizzes yet', 'Create your first quiz to schedule an assessment for your class.', 'Create quiz', '#', 'data-open-modal="create-modal"') ?>
    <?php endif; ?>
  <?php else: ?>
    <table class="tbl tbl--quizzes">
      <thead>
        <tr>
          <th scope="col">Quiz / Version</th>
          <th scope="col">Window state</th>
          <th scope="col">Window · 2026</th>
          <th scope="col">Submitted</th>
          <th scope="col">Actions</th>
        </tr>
      </thead>
      <tbody>
      <?php foreach ($rows as $r):
          $phase = $r['phase'];
          $isDraft = $r['status'] === 'draft';
          $isArchived = $r['status'] === 'archived';

          $classDesc = ($r['course_code'] ? $r['course_code'] . ' · ' : '') . $r['section'];
          $meta = $classDesc . ' · ' . ucfirst($r['status']) . ($r['status'] === 'published' ? ' · v' . $r['version'] : '');

          // Status mark styling
          if ($phase === 'open') {
              $mark = status_mark('active', 'Open');
          } elseif ($phase === 'scheduled') {
              $mark = status_mark('scheduled', 'Scheduled');
          } elseif ($isDraft) {
              $mark = status_mark('neutral', 'Draft');
          } elseif ($isArchived) {
              $mark = status_mark('neutral', 'Archived');
          } else {
              $mark = status_mark('neutral', 'Closed');
          }

          // Submission string
          if ($isDraft) {
              $submitted = '—';
          } elseif ($phase === 'scheduled') {
              $submitted = '— / ' . $r['roster_count'];
          } else {
              $submitted = $r['submitted_count'] . ' / ' . $r['roster_count'];
          }
      ?>
        <tr>
          <td class="q-title-cell">
            <a href="/teacher/quiz.php?id=<?= (int) $r['id'] ?>"><?= e($r['title']) ?></a>
            <span class="q-meta"><?= e($meta) ?></span>
          </td>
          <td><?= $mark ?></td>
          <td><?= e($r['window_formatted']) ?></td>
          <td><?= e($submitted) ?></td>
          <td class="q-actions-cell">
            <?php if ($isDraft): ?>
              <a class="action-main" href="/teacher/quiz.php?id=<?= (int) $r['id'] ?>">Edit draft →</a>
              <form class="inline-form" method="post" action="/teacher/quizzes.php" data-confirm="Archive this draft?">
                <?= csrf_field() ?>
                <input type="hidden" name="action" value="archive">
                <input type="hidden" name="quiz_id" value="<?= (int) $r['id'] ?>">
                <button class="linklike action-sub" type="submit">Archive</button>
              </form>
            <?php elseif ($isArchived): ?>
              <a class="action-main" href="/teacher/quiz.php?id=<?= (int) $r['id'] ?>">View record →</a>
              <form class="inline-form" method="post" action="/teacher/quizzes.php">
                <?= csrf_field() ?>
                <input type="hidden" name="action" value="restore">
                <input type="hidden" name="quiz_id" value="<?= (int) $r['id'] ?>">
                <button class="linklike action-sub" type="submit">Restore</button>
              </form>
            <?php elseif ($phase === 'open'): ?>
              <a class="action-main" href="/teacher/quiz.php?id=<?= (int) $r['id'] ?>">Live view →</a>
              <form class="inline-form" method="post" action="/teacher/quizzes.php">
                <?= csrf_field() ?>
                <input type="hidden" name="action" value="duplicate">
                <input type="hidden" name="quiz_id" value="<?= (int) $r['id'] ?>">
                <button class="linklike action-sub" type="submit">Duplicate as new version</button>
              </form>
            <?php elseif ($phase === 'scheduled'): ?>
              <a class="action-main" href="/teacher/quiz.php?id=<?= (int) $r['id'] ?>">View quiz →</a>
              <form class="inline-form" method="post" action="/teacher/quizzes.php">
                <?= csrf_field() ?>
                <input type="hidden" name="action" value="duplicate">
                <input type="hidden" name="quiz_id" value="<?= (int) $r['id'] ?>">
                <button class="linklike action-sub" type="submit">Duplicate as new version</button>
              </form>
            <?php else: ?>
              <a class="action-main" href="/teacher/quiz.php?id=<?= (int) $r['id'] ?>">Review &amp; release →</a>
              <form class="inline-form" method="post" action="/teacher/quizzes.php">
                <?= csrf_field() ?>
                <input type="hidden" name="action" value="duplicate">
                <input type="hidden" name="quiz_id" value="<?= (int) $r['id'] ?>">
                <button class="linklike action-sub" type="submit">Duplicate as new version</button>
              </form>
            <?php endif; ?>
          </td>
        </tr>
      <?php endforeach; ?>
      </tbody>
    </table>

    <div class="pager">
      <span class="foot-note">Showing <?= count($rows) ?> quiz<?= count($rows) === 1 ? '' : 'zes' ?> · Published content is frozen. Duplicate to make a new version.</span>
      <span class="pager__btns">
        <?php if ($page > 1): ?>
          <a class="btn btn--pill" href="<?= e(quizzes_url(['tab' => $tab, 'q' => $q, 'class_id' => $classId, 'state' => $state, 'p' => $page - 1])) ?>">‹ Previous</a>
        <?php else: ?><span class="btn btn--pill btn--off" aria-disabled="true">‹ Previous</span><?php endif; ?>
        <?php if ($page < $pages): ?>
          <a class="btn btn--pill" href="<?= e(quizzes_url(['tab' => $tab, 'q' => $q, 'class_id' => $classId, 'state' => $state, 'p' => $page + 1])) ?>">Next ›</a>
        <?php else: ?><span class="btn btn--pill btn--off" aria-disabled="true">Next ›</span><?php endif; ?>
      </span>
    </div>
  <?php endif; ?>
</section>

<div class="info-pair">
  <section class="card">
    <h2>States and phases</h2>
    <p class="muted-sm">Draft, published and archived describe the record. Scheduled, open and closed are derived from a published quiz's time window.</p>
  </section>
  <section class="card">
    <h2>Access and records</h2>
    <p class="muted-sm">Only students enrolled in the selected class can access a published quiz. Archiving retains attempts and grading records.</p>
  </section>
</div>

<!-- Modal: + Create quiz -->
<div class="modal-overlay is-hidden" id="create-modal" hidden>
  <div class="modal-card" role="dialog" aria-modal="true" aria-labelledby="modal-title">
    <div style="display: flex; justify-content: space-between; align-items: flex-start; margin-bottom: var(--s2);">
      <h2 id="modal-title" style="margin: 0;">Create a new quiz</h2>
      <button type="button" class="linklike" data-close-modal style="font-size: 22px; line-height: 1; cursor: pointer; color: var(--ink-3);" aria-label="Close dialog">×</button>
    </div>
    <p>Give your quiz a title and select the class it belongs to. You can adjust settings and add questions next.</p>
    <form method="post" action="/teacher/quizzes.php">
      <?= csrf_field() ?>
      <input type="hidden" name="action" value="create_draft">
      <?= field('title', 'Quiz title', 'text', ['placeholder' => 'e.g. Routing Practice', 'required' => true, 'maxlength' => 160]) ?>
      <div class="field">
        <label class="field__label" for="modal_class_id">Class</label>
        <select class="input" id="modal_class_id" name="class_id" required>
          <?php foreach ($classes as $c): ?>
            <option value="<?= (int) $c['id'] ?>">
              <?= e($c['course_code'] ? $c['course_code'] . ' · ' . $c['name'] : $c['name']) ?> (<?= e($c['section']) ?>)
            </option>
          <?php endforeach; ?>
        </select>
      </div>
      <div class="modal-actions">
        <button class="btn btn--pill" type="button" data-close-modal>Cancel</button>
        <button class="btn btn--primary btn--pill" type="submit">Create draft</button>
      </div>
    </form>
  </div>
</div>

<script>
(function () {
  var modal = document.getElementById('create-modal');
  if (!modal) return;

  function openModal() {
    modal.hidden = false;
    modal.classList.remove('is-hidden');
    var input = modal.querySelector('input[name="title"]');
    if (input) { input.focus(); }
  }

  function closeModal() {
    modal.hidden = true;
    modal.classList.add('is-hidden');
  }

  document.querySelectorAll('[data-open-modal="create-modal"]').forEach(function (btn) {
    btn.addEventListener('click', function (e) {
      e.preventDefault();
      openModal();
    });
  });

  modal.querySelectorAll('[data-close-modal]').forEach(function (btn) {
    btn.addEventListener('click', function (e) {
      e.preventDefault();
      closeModal();
    });
  });

  modal.addEventListener('click', function (e) {
    if (e.target === modal) {
      closeModal();
    }
  });

  document.addEventListener('keydown', function (e) {
    if (e.key === 'Escape' && !modal.hidden) {
      closeModal();
    }
  });
})();
</script>

<?php teacher_end();
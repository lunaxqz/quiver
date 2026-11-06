<?php
declare(strict_types=1);

require_once __DIR__ . '/../../app/bootstrap.php';
require_once __DIR__ . '/../../app/helpers/layout.php';

$user = require_role('admin');

if (($_SERVER['REQUEST_METHOD'] ?? '') === 'POST') {
    csrf_verify();
    $class = ClassService::findOwned((int) post_str('class_id'), $user);   // admins may open any class
    if ($class === null) {
        abort_404();
    }
    if (post_str('action') === 'archive') {
        ClassService::archive((int) $class['id']);
        flash_set('Class archived. Its records are kept.');
    }
    redirect('/admin/classes.php');
}

$flash   = flash_get();
$classes = ClassService::listAll();

teacher_start(
    'All school classes',
    'classes',
    'admin',
    ['/assets/css/classes.css'],
    'Review active and archived classes across the school. Archiving a class hides it from daily lists while keeping all student records safe.'
);
?>

<?php if ($flash !== null): ?>
  <div class="alert alert--notice" role="status"><?= e($flash['message']) ?></div>
<?php endif; ?>

<?php if (!$classes): ?>
  <?= state_empty('No classes yet', 'Classes appear here once teachers set them up.') ?>
<?php else: ?>
  <section class="panel" aria-labelledby="all-classes-heading">
    <div class="panel__head-row">
      <h2 id="all-classes-heading">School classes (<?= count($classes) ?>)</h2>
      <a class="btn btn--sm" href="/admin/index.php">&larr; Back to people</a>
    </div>

    <?= status_legend([
        ['complete', 'Active'],
        ['active',   'Join code on'],
        ['neutral',  'Archived or code off'],
    ], 'Class status:') ?>

    <ul class="well-list">
      <?php foreach ($classes as $c):
          $open = $c['archived_at'] === null;
          $label = trim(implode(' · ', array_filter([
              (string) ($c['course_code'] ?? ''),
              ($c['section'] ?? '') !== '' ? 'Section ' . $c['section'] : '',
          ])));
          $stuCount = (int) ($c['student_count'] ?? 0);
      ?>
        <li class="well-row">
          <div class="well-row__main">
            <div class="well-row__top">
              <span class="well-row__title"><?= e($c['name']) ?></span>
              <?php if ($label !== ''): ?>
                <span class="role-chip"><?= e($label) ?></span>
              <?php endif; ?>
              <?= $open ? status_mark('complete', 'Active') : status_mark('neutral', 'Archived') ?>
              <?= (int) ($c['code_enabled'] ?? 0) === 1 ? status_mark('active', 'Code on') : status_mark('neutral', 'Code off') ?>
            </div>
            <div class="well-row__meta">
              Teacher: <?= e((string) $c['owner_name']) ?> · <?= e((string) $c['school_year']) ?> · <?= $stuCount ?> student<?= $stuCount === 1 ? '' : 's' ?>
            </div>
          </div>
          <?php if ($open): ?>
            <div class="well-row__actions">
              <form class="inline-form" method="post" action="/admin/classes.php" data-confirm="Archive this class? All student quiz records are kept safely.">
                <?= csrf_field() ?>
                <input type="hidden" name="action" value="archive">
                <input type="hidden" name="class_id" value="<?= (int) $c['id'] ?>">
                <button class="btn btn--sm" type="submit">Archive class</button>
              </form>
            </div>
          <?php endif; ?>
        </li>
      <?php endforeach; ?>
    </ul>
  </section>
<?php endif; ?>

<script src="/assets/js/classes.js" defer></script>
<?php teacher_end();

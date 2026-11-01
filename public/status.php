<?php
declare(strict_types=1);

require_once __DIR__ . '/../app/helpers/layout.php';

$states = [
    ['neutral',   'Draft'],
    ['scheduled', 'Opens Mon 8:00'],
    ['active',    'In progress'],
    ['attention', 'Needs grading'],
    ['complete',  'Graded'],
    ['problem',   'Voided'],
];

teacher_start('Status marks', 'overview');
?>
<p>Every state in color (left) and in grayscale (right). In grayscale you should still tell all six apart by the ring style and the symbol.</p>

<div class="sheet">
  <?php foreach ([false, true] as $gray): ?>
    <section class="<?= $gray ? 'sheet--gray' : '' ?>">
      <h2><?= $gray ? 'Grayscale' : 'Color' ?></h2>
      <ul>
        <?php foreach ($states as [$state, $label]): ?>
          <li><?= status_mark($state, $label) ?></li>
        <?php endforeach; ?>
      </ul>
    </section>
  <?php endforeach; ?>
</div>

<h2 style="margin:var(--s6) 0 var(--s3)">Designed states</h2>
<?= state_empty('No questions yet', 'Questions you write will appear here.', 'Write a question', '#') ?>
<?= state_loading(3) ?>
<?= state_error('We could not load your classes. Check your connection and try again.', '/status.php') ?>
<?= state_not_found() ?>
<?php teacher_end();

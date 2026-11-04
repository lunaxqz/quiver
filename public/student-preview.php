<?php
declare(strict_types=1);

require_once __DIR__ . '/../app/helpers/layout.php';

student_start('Networking Quiz');
?>
<p class="reading">
  Which layer of the OSI model is responsible for routing packets between networks?
</p>
<p><?= status_mark('active', 'In progress') ?></p>
<?php student_end();

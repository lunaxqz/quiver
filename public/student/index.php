<?php
declare(strict_types=1);

require_once __DIR__ . '/../../app/bootstrap.php';
require_once __DIR__ . '/../../app/helpers/layout.php';

$user  = require_role('student');
$flash = flash_get();

student_start('Your classes');
if ($flash !== null) {
    echo '<div class="alert alert--' . ($flash['type'] === 'error' ? 'error' : 'notice') . '" role="status">' . e($flash['message']) . '</div>';
}
echo state_empty('No classes yet', 'Your classes and open quizzes will appear here. Ask your teacher for a class code.');
student_end();

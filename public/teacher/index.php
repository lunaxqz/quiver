<?php
declare(strict_types=1);

require_once __DIR__ . '/../../app/bootstrap.php';
require_once __DIR__ . '/../../app/helpers/layout.php';

$user = require_role('teacher');

teacher_start('Overview', 'overview');
echo state_empty('Nothing needs you right now', 'Create a class to get started. The Today rail will appear here on Day 17.', 'Create a class', '/teacher/classes.php');
teacher_end();

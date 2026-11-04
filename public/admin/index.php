<?php
declare(strict_types=1);

require_once __DIR__ . '/../../app/bootstrap.php';
require_once __DIR__ . '/../../app/helpers/layout.php';

$user = require_role('admin');

teacher_start('Accounts', 'accounts', 'admin');
echo state_empty('Account management comes next', 'On Day 5 you will create teacher accounts, reset passwords and deactivate accounts here.');
teacher_end();

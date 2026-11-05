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

teacher_start('Classes', 'classes', 'admin', ['/assets/css/classes.css']);
if ($flash !== null) {
    echo '<div class="alert alert--notice" role="status">' . e($flash['message']) . '</div>';
}
if (!$classes) {
    echo state_empty('No classes yet', 'Classes appear here once teachers create them.');
} else {
    echo '<table class="tbl"><thead><tr><th scope="col">Class</th><th scope="col">Teacher</th><th scope="col">Term</th><th scope="col">Students</th><th scope="col">Status</th><th scope="col"><span class="visually-hidden">Actions</span></th></tr></thead><tbody>';
    foreach ($classes as $c) {
        $open = $c['archived_at'] === null;
        $label = trim(($c['course_code'] ?? '') . ($c['section'] ? ' · Section ' . $c['section'] : ''), ' ·');
        echo '<tr><td>' . e($c['name']) . ($label !== '' ? '<br><span class="muted-sm">' . e($label) . '</span>' : '') . '</td>'
           . '<td>' . e($c['owner_name']) . '</td><td>' . e($c['school_year']) . '</td><td>' . (int) $c['student_count'] . '</td>'
           . '<td>' . ($open ? 'Active' : 'Archived') . '</td><td class="actions">';
        if ($open) {
            echo '<form class="inline-form" method="post" action="/admin/classes.php" data-confirm="Archive this class? Its records are kept.">'
               . csrf_field() . '<input type="hidden" name="action" value="archive"><input type="hidden" name="class_id" value="' . (int) $c['id'] . '">'
               . '<button class="linklike" type="submit">Archive</button></form>';
        }
        echo '</td></tr>';
    }
    echo '</tbody></table>';
}
echo '<script src="/assets/js/classes.js" defer></script>';
teacher_end();

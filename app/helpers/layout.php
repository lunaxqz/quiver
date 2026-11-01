<?php
declare(strict_types=1);

require_once __DIR__ . '/status_mark.php';
require_once __DIR__ . '/states.php';

/**
 * Page frames. Use them like this on any page:
 *
 *   teacher_start('Question bank', 'bank');   // 'bank' highlights that rail link
 *   ...your page content...
 *   teacher_end();
 *
 * Student pages use student_start('Title') ... student_end().
 */

function _page_head(string $title): void
{
    $t = htmlspecialchars($title, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
    echo '<!doctype html><html lang="en"><head><meta charset="utf-8">'
       . '<meta name="viewport" content="width=device-width, initial-scale=1">'
       . '<title>' . $t . ' · QUIVER</title>'
       . '<link rel="stylesheet" href="/assets/css/tokens.css">'
       . '<link rel="stylesheet" href="/assets/css/layout.css">'
       . '</head><body>';
}

function teacher_start(string $title, string $active = ''): void
{
    _page_head($title);
    $links = [
        'overview' => ['Overview',      '/teacher/index.php'],
        'classes'  => ['Classes',       '/teacher/classes.php'],
        'bank'     => ['Question bank', '/teacher/bank.php'],
        'quizzes'  => ['Quizzes',       '/teacher/quizzes.php'],
    ];
    echo '<div class="t-shell"><aside class="t-rail">'
       . '<a class="t-brand" href="/teacher/index.php">QUIVER</a><nav class="t-nav" aria-label="Main">';
    foreach ($links as $key => [$label, $href]) {
        $cur = $key === $active ? ' aria-current="page"' : '';
        echo '<a href="' . $href . '"' . $cur . '>' . $label . '</a>';
    }
    echo '</nav><div class="t-user">Signed in as a teacher<br><a href="/logout.php">Sign out</a></div></aside>'
       . '<main class="t-main"><div class="t-content">'
       . '<div class="page-head"><h1>' . htmlspecialchars($title, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') . '</h1></div>';
}

function teacher_end(): void
{
    echo '</div></main></div></body></html>';
}

function student_start(string $title): void
{
    _page_head($title);
    echo '<header class="s-header"><span class="s-brand">QUIVER</span>'
       . '<span class="s-who">Student · <a href="/logout.php">Sign out</a></span></header>'
       . '<main class="s-main"><div class="page-head"><h1>' . htmlspecialchars($title, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') . '</h1></div>';
}

function student_end(): void
{
    echo '</main></body></html>';
}

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
 * Administrators use teacher_start('Accounts', 'accounts', 'admin') (same frame, different links).
 * Student pages use student_start('Title') ... student_end().
 */

function _page_head(string $title, array $extraCss = [], string $bodyClass = ''): void
{
    $t = htmlspecialchars($title, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
    echo '<!doctype html><html lang="en"><head><meta charset="utf-8">'
       . '<meta name="viewport" content="width=device-width, initial-scale=1">'
       . '<title>' . $t . ' · QUIVER</title>'
       . '<link rel="stylesheet" href="/assets/css/tokens.css">'
       . '<link rel="stylesheet" href="/assets/css/layout.css">'
       . '<link rel="stylesheet" href="/assets/css/forms.css">';
    foreach ($extraCss as $css) {
        echo '<link rel="stylesheet" href="' . htmlspecialchars($css, ENT_QUOTES, 'UTF-8') . '">';
    }
    echo '</head><body' . ($bodyClass !== '' ? ' class="' . htmlspecialchars($bodyClass, ENT_QUOTES, 'UTF-8') . '"' : '') . '>';
}

/** "Sign out" must be a POST with a CSRF ticket (a plain link could be triggered by another site). */
function _signout_form(): string
{
    if (!function_exists('csrf_field')) {
        return '<a href="/login.php">Sign in</a>';
    }
    return '<form class="inline-form" method="post" action="/logout.php">' . csrf_field()
         . '<button class="linklike" type="submit">Sign out</button></form>';
}

/** A one-time message left by the previous page (e.g. "Password changed."). Printed once, then forgotten. */
function _flash_html(): string
{
    $f = function_exists('flash_get') ? flash_get() : null;
    if ($f === null) {
        return '';
    }
    $cls = ($f['type'] ?? '') === 'error' ? 'alert--error' : 'alert--notice';
    return '<div class="alert ' . $cls . '" role="status">' . htmlspecialchars((string) $f['message'], ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') . '</div>';
}

function _who(): array
{
    $u = function_exists('current_user') ? current_user() : null;
    return $u ?? ['full_name' => 'Not signed in', 'role' => ''];
}

/**
 * $subtitle and $action are trusted HTML written by our own pages (escape any person's text first).
 * With either one, the title area matches the Figma headers: no rule underneath, button at the right.
 */
function teacher_start(string $title, string $active = '', string $role = 'teacher', array $extraCss = [], string $subtitle = '', string $action = ''): void
{
    _page_head($title, $extraCss);
    $links = $role === 'admin'
        ? [
            'accounts' => ['Accounts', '/admin/index.php'],
            'classes'  => ['Classes',  '/admin/classes.php'],
        ]
        : [
            'overview' => ['Overview',      '/teacher/index.php'],
            'classes'  => ['Classes',       '/teacher/classes.php'],
            'bank'     => ['Question bank', '/teacher/bank.php'],
            'quizzes'  => ['Quizzes',       '/teacher/quizzes.php'],
        ];
    $who = _who();
    $roleLabel = $who['role'] !== '' ? ucfirst((string) $who['role']) : '';

    echo '<div class="t-shell"><aside class="t-rail">'
       . '<a class="t-brand" href="/">QUIVER</a><nav class="t-nav" aria-label="Main">';
    foreach ($links as $key => [$label, $href]) {
        $cur = $key === $active ? ' aria-current="page"' : '';
        echo '<a href="' . $href . '"' . $cur . '>' . $label . '</a>';
    }
    echo '</nav><div class="t-user"><strong>' . htmlspecialchars((string) $who['full_name'], ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') . '</strong><br>'
       . $roleLabel . ($roleLabel !== '' ? ' · ' : '') . _signout_form()
       . '<br><a href="/change_password.php">Change password</a></div></aside>'
       . '<main class="t-main"><div class="t-content">'
       . '<div class="page-head' . ($subtitle !== '' || $action !== '' ? ' page-head--clean' : '') . '"><div><h1>' . htmlspecialchars($title, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') . '</h1>'
       . ($subtitle !== '' ? '<p class="page-head__sub">' . $subtitle . '</p>' : '') . '</div>' . $action . '</div>'
       . _flash_html();
}

function teacher_end(): void
{
    echo '</div></main></div></body></html>';
}

function student_start(string $title): void
{
    _page_head($title);
    $who = _who();
    echo '<header class="s-header"><a class="s-brand" href="/">QUIVER</a>'
       . '<span class="s-who">' . htmlspecialchars((string) $who['full_name'], ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') . ' · <a href="/change_password.php">Change password</a> · ' . _signout_form() . '</span></header>'
       . '<main class="s-main"><div class="page-head"><h1>' . htmlspecialchars($title, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') . '</h1></div>'
       . _flash_html();
}

function student_end(): void
{
    echo '</main></body></html>';
}

<?php
declare(strict_types=1);

require_once __DIR__ . '/status_mark.php';
require_once __DIR__ . '/states.php';

/**
 * Shared page frames for QUIVER (Teacher, Administrator, Student, and Auth).
 */

function _saved_theme(): string
{
    $c = $_COOKIE['quiver_theme'] ?? '';
    return $c === 'dark' ? 'dark' : 'light';
}

function _page_head(string $title, array $extraCss = [], string $bodyClass = ''): void
{
    $t = htmlspecialchars($title, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');

    /* Keep timed quiz-taking screens calm with minimal motion */
    $scriptName = (string) ($_SERVER['SCRIPT_NAME'] ?? '');
    if (str_ends_with($scriptName, '/student/attempt.php')) {
        $bodyClass = trim($bodyClass . ' calm-focus');
    }

    $theme = _saved_theme();

    echo '<!doctype html><html lang="en" data-theme="' . $theme . '"><head><meta charset="utf-8">'
       . '<meta name="viewport" content="width=device-width, initial-scale=1">'
       . '<title>' . $t . ' · QUIVER</title>'
       . '<script src="/assets/js/theme-boot.js"></script>'
       . '<link rel="stylesheet" href="/assets/css/tokens.css">'
       . '<link rel="stylesheet" href="/assets/css/layout.css">'
       . '<link rel="stylesheet" href="/assets/css/forms.css">'
       . '<link rel="stylesheet" href="/assets/css/tables.css">';
    foreach ($extraCss as $css) {
        echo '<link rel="stylesheet" href="' . htmlspecialchars($css, ENT_QUOTES, 'UTF-8') . '">';
    }
    echo '<script src="/assets/js/ui.js" defer></script>';
    echo '</head><body' . ($bodyClass !== '' ? ' class="' . htmlspecialchars($bodyClass, ENT_QUOTES, 'UTF-8') . '"' : '') . '>';
}

/** "Sign out" must be a POST with a CSRF ticket (a plain link could be triggered by another site). */
function _signout_form(string $btnClass = 'btn btn--sm'): string
{
    if (!function_exists('csrf_field')) {
        return '<a class="' . htmlspecialchars($btnClass, ENT_QUOTES, 'UTF-8') . '" href="/login.php">Sign in</a>';
    }
    return '<form class="inline-form" method="post" action="/logout.php">' . csrf_field()
         . '<button class="' . htmlspecialchars($btnClass, ENT_QUOTES, 'UTF-8') . '" type="submit">Sign out</button></form>';
}

/** Light / Dark theme toggle button rendered in headers and rails. */
function _theme_toggle_btn(): string
{
    $isDark = _saved_theme() === 'dark';
    $label  = $isDark ? '☀ Light' : '☾ Dark';
    $title  = $isDark ? 'Switch to light theme' : 'Switch to dark theme';
    return '<button class="theme-toggle" type="button" data-theme-toggle aria-pressed="'
         . ($isDark ? 'true' : 'false') . '" title="' . $title . '">' . $label . '</button>';
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
 * Teacher & Administrator workspace frame with numbered workflow navigation.
 */
function teacher_start(string $title, string $active = '', string $role = 'teacher', array $extraCss = [], string $subtitle = '', string $action = ''): void
{
    _page_head($title, $extraCss);
    $who = _who();
    $roleLabel = match ((string) ($who['role'] ?? '')) {
        'admin'   => 'School Administrator',
        'teacher' => 'Teacher',
        default   => ucfirst((string) ($who['role'] ?? '')),
    };

    $gradingBadge = 0;
    if ($role === 'teacher' && isset($who['id']) && (int) $who['id'] > 0 && class_exists('GradingService')) {
        try {
            $gradingBadge = GradingService::pendingCountForTeacher((int) $who['id']);
        } catch (Throwable) {
            $gradingBadge = 0;
        }
    }

    echo '<div class="t-shell"><aside class="t-rail">'
       . '<div class="t-rail__top">'
       . '<a class="brand-lockup" href="/"><span class="brand-mark" aria-hidden="true">Q</span><span>QUIVER</span></a>'
       . _theme_toggle_btn()
       . '</div>';

    if ($role === 'admin') {
        $adminLinks = [
            'accounts' => ['1', 'People & passwords', '/admin/index.php'],
            'classes'  => ['2', 'Class records',      '/admin/classes.php'],
        ];
        echo '<div class="t-nav-group"><p class="t-nav-heading">School office steps</p><nav class="t-nav" aria-label="Main">';
        foreach ($adminLinks as $key => [$step, $label, $href]) {
            $cur = $key === $active ? ' aria-current="page"' : '';
            echo '<a href="' . $href . '"' . $cur . '>'
               . '<span class="t-nav__step" aria-hidden="true">' . $step . '</span>'
               . '<span class="t-nav__label">' . $label . '</span></a>';
        }
        echo '</nav></div>';
    } else {
        $ovCur = $active === 'overview' ? ' aria-current="page"' : '';
        echo '<div class="t-nav-group"><p class="t-nav-heading">Start here</p><nav class="t-nav" aria-label="Overview">'
           . '<a href="/teacher/index.php"' . $ovCur . '>'
           . '<span class="t-nav__step" aria-hidden="true">★</span>'
           . '<span class="t-nav__label">Today\'s Overview</span></a></nav></div>';

        $teacherSteps = [
            'classes' => ['1', 'Set Up Classes',   '/teacher/classes.php'],
            'bank'    => ['2', 'Write Questions',  '/teacher/bank.php'],
            'quizzes' => ['3', 'Schedule Quizzes', '/teacher/quizzes.php'],
            'grading' => ['4', 'Grade Answers',    '/teacher/grading.php'],
            'reports' => ['5', 'Share Scores',     '/teacher/reports.php'],
        ];
        echo '<div class="t-nav-group"><p class="t-nav-heading">Your teaching steps</p><nav class="t-nav" aria-label="Teaching workflow">';
        foreach ($teacherSteps as $key => [$step, $label, $href]) {
            $cur = $key === $active ? ' aria-current="page"' : '';
            $badgeHtml = ($key === 'grading' && $gradingBadge > 0)
                ? ' <span class="t-nav__badge" id="nav-grading-badge">' . $gradingBadge . '</span>'
                : ($key === 'grading' ? ' <span class="t-nav__badge" id="nav-grading-badge" hidden>0</span>' : '');
            echo '<a href="' . $href . '"' . $cur . '>'
               . '<span class="t-nav__step" aria-hidden="true">' . $step . '</span>'
               . '<span class="t-nav__label">' . $label . '</span>'
               . $badgeHtml . '</a>';
        }
        echo '</nav></div>';
    }

    echo '<div class="t-user"><strong>' . htmlspecialchars((string) $who['full_name'], ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') . '</strong>'
       . '<span class="t-user__meta">' . htmlspecialchars($roleLabel, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') . '</span>'
       . '<div class="t-user__actions">'
       . '<a class="btn btn--sm btn--quiet" href="/change_password.php">Password</a>'
       . _signout_form('btn btn--sm')
       . '</div></div></aside>'
       . '<main class="t-main"><div class="t-content">'
       . '<div class="page-head' . ($subtitle !== '' || $action !== '' ? ' page-head--clean' : '') . '"><div><h1>' . htmlspecialchars($title, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') . '</h1>'
       . ($subtitle !== '' ? '<p class="page-head__sub">' . $subtitle . '</p>' : '') . '</div>' . $action . '</div>'
       . _flash_html();
}

function teacher_end(): void
{
    echo '</div></main></div></body></html>';
}

/**
 * Student frame with floating clay header, clear home navigation, and theme toggle.
 */
function student_start(string $title, string $context = '', bool $ownHead = false, array $extraCss = []): void
{
    _page_head($title, $extraCss);
    $who = _who();
    $displayName = (string) $who['full_name'];
    if ($context !== '') {
        $displayName .= ' · ' . $context;
    }

    $scriptName = (string) ($_SERVER['SCRIPT_NAME'] ?? '');
    $onAttempt  = str_ends_with($scriptName, '/student/attempt.php');
    $onHome     = str_ends_with($scriptName, '/student/index.php');

    echo '<header class="s-header">'
       . '<div class="s-header__left">'
       . '<a class="brand-lockup" href="/student/index.php"><span class="brand-mark" aria-hidden="true">Q</span><span>QUIVER</span></a>';
    if (!$onAttempt) {
        echo '<nav aria-label="Student navigation"><a class="btn btn--sm' . ($onHome ? '' : ' btn--quiet') . '" href="/student/index.php"'
           . ($onHome ? ' aria-current="page"' : '') . '>My Classes &amp; Quizzes</a></nav>';
    }
    echo '</div>'
       . '<div class="s-header__right">'
       . _theme_toggle_btn()
       . '<span class="s-who"><span class="s-who__name">' . htmlspecialchars($displayName, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') . '</span></span>';
    if (!$onAttempt) {
        echo '<a class="btn btn--sm btn--quiet" href="/change_password.php">Password</a>'
           . _signout_form('btn btn--sm');
    }
    echo '</div></header><main class="s-main">';

    if (!$ownHead) {
        echo '<div class="page-head"><h1>' . htmlspecialchars($title, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') . '</h1></div>';
    }
    echo _flash_html();
}

function student_end(): void
{
    echo '</main></body></html>';
}

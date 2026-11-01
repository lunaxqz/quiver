<?php
declare(strict_types=1);

/* The four designed states every list and form needs. */

function _h(string $s): string
{
    return htmlspecialchars($s, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}

/** Nothing here yet. Say what the page is for and what to do next. */
function state_empty(string $title, string $body, string $actionLabel = '', string $actionHref = ''): string
{
    $btn = $actionLabel !== ''
        ? '<a class="btn btn--primary" href="' . _h($actionHref) . '">' . _h($actionLabel) . '</a>'
        : '';
    return '<div class="state"><h3>' . _h($title) . '</h3><p>' . _h($body) . '</p>' . $btn . '</div>';
}

/** Loading: grey bars in the shape of the content. Announced politely to screen readers. */
function state_loading(int $rows = 4): string
{
    $bars = str_repeat('<span class="skeleton"></span>', max(1, $rows));
    return '<div role="status" aria-live="polite"><span class="visually-hidden">Loading</span>' . $bars . '</div>';
}

/** Something failed. Say what happened in plain words and offer a retry. */
function state_error(string $message, string $retryHref = ''): string
{
    $btn = $retryHref !== ''
        ? '<a class="btn" href="' . _h($retryHref) . '">Try again</a>'
        : '';
    return '<div class="state state--error" role="alert"><h3>Something went wrong</h3><p>' . _h($message) . '</p>' . $btn . '</div>';
}

/** "Not found" is also what we show for anything a person is not allowed to see. */
function state_not_found(): string
{
    return '<div class="state"><h3>We couldn\'t find that page</h3>'
         . '<p>It may have moved, or it may not exist.</p>'
         . '<a class="btn" href="/">Go back to the start</a></div>';
}

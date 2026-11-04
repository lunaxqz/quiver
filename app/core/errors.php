<?php
declare(strict_types=1);

require_once __DIR__ . '/../helpers/layout.php';

/** Stop with a simple full-page message. */
function abort_page(int $status, string $title, string $message): never
{
    http_response_code($status);
    _page_head($title);
    echo '<main class="s-main"><div class="state"><h3>' . _h($title) . '</h3><p>' . _h($message) . '</p>'
       . '<a class="btn" href="/">Go back to the start</a></div></main></body></html>';
    exit;
}

/**
 * "Not found" is ALSO what we show for anything a person is not allowed to see.
 * A 403 ("forbidden") would confirm the page is real (guide section 7).
 */
function abort_404(): never
{
    http_response_code(404);
    _page_head('Not found');
    echo '<main class="s-main">' . state_not_found() . '</main></body></html>';
    exit;
}

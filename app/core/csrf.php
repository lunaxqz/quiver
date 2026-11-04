<?php
declare(strict_types=1);

/**
 * CSRF protection: a secret ticket that proves a form was sent from OUR page,
 * not from another website that tricked your browser.
 */
function csrf_token(): string
{
    if (empty($_SESSION['csrf']) || !is_string($_SESSION['csrf'])) {
        $_SESSION['csrf'] = bin2hex(random_bytes(32));
    }
    return $_SESSION['csrf'];
}

/** Put this inside every <form method="post">. */
function csrf_field(): string
{
    return '<input type="hidden" name="_csrf" value="' . e(csrf_token()) . '">';
}

/** Call at the top of any page that changes data. Accepts the form field or an X-CSRF-Token header (for fetch). */
function csrf_verify(): void
{
    $sent = $_POST['_csrf'] ?? ($_SERVER['HTTP_X_CSRF_TOKEN'] ?? '');
    if (!is_string($sent) || !hash_equals(csrf_token(), $sent)) {
        abort_page(400, 'That form has expired', 'Go back, reload the page, and try again.');
    }
}

/** State changes are POST only (guide section 7). */
function require_post(): void
{
    if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') {
        http_response_code(405);
        header('Allow: POST');
        exit;
    }
}

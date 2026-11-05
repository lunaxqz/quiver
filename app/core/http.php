<?php
declare(strict_types=1);

/** Escape text before printing it into a page. Use e() on EVERY value that came from a person or the database. */
function e(?string $value): string
{
    return htmlspecialchars((string) $value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}

/** Send a JSON reply and stop. Used by the API pages. */
function json_out(array $data, int $status = 200): never
{
    http_response_code($status);
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}

/** Send the browser to another page and stop. 303 means "now fetch that page with a normal GET". */
function redirect(string $path): never
{
    header('Location: ' . $path, true, 303);
    exit;
}

/** Read a text field from a form (trimmed). Anything that is not plain text becomes ''. */
function post_str(string $key): string
{
    $v = $_POST[$key] ?? '';
    return is_string($v) ? trim($v) : '';
}

/** Read a field exactly as typed (used for passwords, where spaces count). */
function post_raw(string $key): string
{
    $v = $_POST[$key] ?? '';
    return is_string($v) ? $v : '';
}

/**
 * The visitor's address as seen by the server. We deliberately do NOT trust headers like
 * X-Forwarded-For, because anyone can fake them.
 */
function client_ip(): string
{
    return substr((string) ($_SERVER['REMOTE_ADDR'] ?? '0.0.0.0'), 0, 45);
}

/** Security headers from guide section 7. Cheap, and they block most XSS even if one slips through. */
function send_security_headers(): void
{
    header("Content-Security-Policy: default-src 'self'; frame-ancestors 'none'; base-uri 'self'; form-action 'self'");
    header('X-Content-Type-Options: nosniff');
    header('Referrer-Policy: same-origin');
}

/** One-time message shown on the next page (e.g. "Your account is ready"). */
function flash_set(string $message, string $type = 'notice'): void
{
    $_SESSION['flash'] = ['message' => $message, 'type' => $type];
}

function flash_get(): ?array
{
    $f = $_SESSION['flash'] ?? null;
    unset($_SESSION['flash']);
    return is_array($f) ? $f : null;
}

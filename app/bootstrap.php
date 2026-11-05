<?php
declare(strict_types=1);

/**
 * Every page starts with:  require_once __DIR__ . '/../app/bootstrap.php';
 * It sets up UTC time, the error policy, the private settings, security headers, the session, and the helpers.
 */

define('APP_ROOT', dirname(__DIR__));

date_default_timezone_set('UTC');

require_once __DIR__ . '/core/env.php';
require_once __DIR__ . '/core/db.php';
require_once __DIR__ . '/core/http.php';
require_once __DIR__ . '/core/time.php';
require_once __DIR__ . '/core/session.php';
require_once __DIR__ . '/core/csrf.php';
require_once __DIR__ . '/core/errors.php';
require_once __DIR__ . '/core/auth.php';
require_once __DIR__ . '/helpers/forms.php';

// Scoring and other business rules: plain functions, one file each.
foreach (glob(__DIR__ . '/rules/*.php') ?: [] as $ruleFile) {
    require_once $ruleFile;
}

// Services (AuthService and friends) load themselves the first time they are used.
spl_autoload_register(function (string $class): void {
    $file = __DIR__ . '/services/' . $class . '.php';
    if (is_file($file)) {
        require_once $file;
    }
});

// ---- Error policy: show details only while developing, always write to a log ----
error_reporting(E_ALL);
ini_set('log_errors', '1');
$logDir = APP_ROOT . '/storage/logs';
if (!is_dir($logDir)) {
    @mkdir($logDir, 0775, true);
}
ini_set('error_log', $logDir . '/php-error.log');

$isDev = false;
try {
    env_load(APP_ROOT . '/.env');
    $isDev = env('APP_ENV', 'production') === 'development';
} catch (Throwable $t) {
    // .env is missing: fall through with safe defaults and show the problem below.
    $envProblem = $t->getMessage();
}
ini_set('display_errors', $isDev ? '1' : '0');

// A missing .env is not a secret, and it is the most common beginner problem, so always explain it.
$showDetails = $isDev || isset($envProblem);

set_exception_handler(function (Throwable $t) use ($showDetails): void {
    error_log($t::class . ': ' . $t->getMessage() . ' in ' . $t->getFile() . ':' . $t->getLine());
    if (!headers_sent()) {
        http_response_code(500);
        header('Content-Type: text/html; charset=utf-8');
    }
    if ($showDetails) {
        echo '<pre style="font:14px/1.5 monospace;padding:16px;white-space:pre-wrap">'
           . htmlspecialchars($t::class . "\n" . $t->getMessage() . "\n\n" . $t->getFile() . ':' . $t->getLine(), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8')
           . '</pre>';
    } else {
        echo '<p style="font:16px sans-serif;padding:24px">Something went wrong. Please try again.</p>';
    }
});

if (isset($envProblem)) {
    throw new RuntimeException($envProblem);
}

// ---- Web requests only (not the terminal scripts): security headers and the session ----
if (PHP_SAPI !== 'cli') {
    send_security_headers();
    session_boot();
}

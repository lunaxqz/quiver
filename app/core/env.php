<?php
declare(strict_types=1);

/**
 * Reads the private .env file (passwords and settings) into memory.
 * We write this ourselves so nothing needs installing.
 */

function env_store(?array $set = null): array
{
    static $values = [];
    if ($set !== null) {
        $values = $set;
    }
    return $values;
}

function env_load(string $path): void
{
    if (!is_file($path)) {
        throw new RuntimeException('The .env file was not found. Copy .env.example to a new file named .env in the project folder.');
    }
    $vars = [];
    foreach (file($path, FILE_IGNORE_NEW_LINES) as $i => $line) {
        if ($i === 0) {
            $line = preg_replace('/^\xEF\xBB\xBF/', '', $line); // strip a Windows "BOM" if an editor added one
        }
        $line = trim($line);
        if ($line === '' || $line[0] === '#') {
            continue;
        }
        $pos = strpos($line, '=');
        if ($pos === false) {
            continue;
        }
        $key = trim(substr($line, 0, $pos));
        $val = trim(substr($line, $pos + 1));
        $len = strlen($val);
        if ($len >= 2 && (($val[0] === '"' && $val[$len - 1] === '"') || ($val[0] === "'" && $val[$len - 1] === "'"))) {
            $val = substr($val, 1, -1);
        }
        $vars[$key] = $val;
    }
    env_store($vars);
}

function env(string $key, ?string $default = null): ?string
{
    $values = env_store();
    return array_key_exists($key, $values) ? $values[$key] : $default;
}

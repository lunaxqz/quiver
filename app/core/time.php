<?php
declare(strict_types=1);

/** Everything is STORED in UTC. We only convert to Manila time when showing it to a person. */

function utc_now(): string
{
    return gmdate('Y-m-d H:i:s');
}

function to_manila(?string $utc, string $format = 'D j M Y, g:i A'): string
{
    if ($utc === null || $utc === '') {
        return '';
    }
    $d = new DateTimeImmutable($utc, new DateTimeZone('UTC'));
    return $d->setTimezone(new DateTimeZone('Asia/Manila'))->format($format);
}

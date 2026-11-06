<?php
declare(strict_types=1);

/**
 * Everything is STORED in UTC. We only convert to Manila time when showing it to a person.
 * Asia/Manila is UTC+8 with no daylight saving time.
 */

function utc_now(): string
{
    return gmdate('Y-m-d H:i:s');
}

function to_manila(?string $utc, string $format = 'D j M Y, g:i A'): string
{
    if ($utc === null || $utc === '') {
        return '';
    }
    try {
        $d = new DateTimeImmutable($utc, new DateTimeZone('UTC'));
        return $d->setTimezone(new DateTimeZone('Asia/Manila'))->format($format);
    } catch (Throwable $e) {
        return '';
    }
}

/**
 * Plain-language 12-hour Manila timestamp without raw timezone jargon (e.g. "Today at 6:30 PM",
 * "Tomorrow at 8:00 AM", or "Fri, 8 Nov at 5:00 PM").
 */
function friendly_manila(?string $utc, ?string $nowUtc = null): string
{
    if ($utc === null || $utc === '') {
        return '';
    }
    try {
        $utcTz    = new DateTimeZone('UTC');
        $manilaTz = new DateTimeZone('Asia/Manila');
        $target   = (new DateTimeImmutable($utc, $utcTz))->setTimezone($manilaTz);
        $now      = (new DateTimeImmutable($nowUtc ?? utc_now(), $utcTz))->setTimezone($manilaTz);

        $targetDay   = $target->format('Y-m-d');
        $todayDay    = $now->format('Y-m-d');
        $tomorrowDay = $now->modify('+1 day')->format('Y-m-d');
        $yestDay     = $now->modify('-1 day')->format('Y-m-d');
        $timePart    = $target->format('g:i A');

        if ($targetDay === $todayDay) {
            return 'today at ' . $timePart;
        }
        if ($targetDay === $tomorrowDay) {
            return 'tomorrow at ' . $timePart;
        }
        if ($targetDay === $yestDay) {
            return 'yesterday at ' . $timePart;
        }
        if ($target->format('Y') === $now->format('Y')) {
            return $target->format('D, j M') . ' at ' . $timePart;
        }
        return $target->format('j M Y') . ' at ' . $timePart;
    } catch (Throwable $e) {
        return '';
    }
}

/** Formats a UTC datetime string for an HTML datetime-local input in Manila time (YYYY-MM-DDTHH:MM). */
function to_manila_input(?string $utc): string
{
    return to_manila($utc, 'Y-m-d\TH:i');
}

/** Converts a Manila time string (e.g. from an HTML input) to UTC string 'Y-m-d H:i:s'. */
function manila_to_utc(?string $manila, string $format = 'Y-m-d H:i:s'): ?string
{
    if ($manila === null || trim($manila) === '') {
        return null;
    }
    $cleaned = str_replace('T', ' ', trim($manila));
    if (strlen($cleaned) === 16) {
        $cleaned .= ':00';
    }
    try {
        $tz = new DateTimeZone('Asia/Manila');
        $d = new DateTimeImmutable($cleaned, $tz);
        return $d->setTimezone(new DateTimeZone('UTC'))->format($format);
    } catch (Throwable $e) {
        return null;
    }
}

/**
 * Formats a quiz window in Manila time:
 * e.g. "Thu 29 Oct · 11:00–12:55"
 */
function format_window_manila(?string $opensAtUtc, ?string $closesAtUtc): string
{
    if ($opensAtUtc === null || $closesAtUtc === null || $opensAtUtc === '' || $closesAtUtc === '') {
        return '—';
    }
    try {
        $utc = new DateTimeZone('UTC');
        $manila = new DateTimeZone('Asia/Manila');
        $start = (new DateTimeImmutable($opensAtUtc, $utc))->setTimezone($manila);
        $end = (new DateTimeImmutable($closesAtUtc, $utc))->setTimezone($manila);

        if ($start->format('Y-m-d') === $end->format('Y-m-d')) {
            return $start->format('D d M · g:i A') . ' – ' . $end->format('g:i A');
        }
        return $start->format('D d M g:i A') . ' – ' . $end->format('D d M g:i A');
    } catch (Throwable $e) {
        return '—';
    }
}

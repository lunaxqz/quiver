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
 * Formats a quiz window in Manila time matching the Figma design:
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
            return $start->format('D d M · H:i') . '–' . $end->format('H:i');
        }
        return $start->format('D d M H:i') . ' – ' . $end->format('D d M H:i');
    } catch (Throwable $e) {
        return '—';
    }
}

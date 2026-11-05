<?php
declare(strict_types=1);

/**
 * Rules live here as small pure functions that never touch the database or web request.
 * Quiz phase (scheduled / open / closed) is derived, not stored.
 *
 * @param DateTimeInterface|string|null $opensAt  Opening time in UTC
 * @param DateTimeInterface|string|null $closesAt Closing time in UTC
 * @param DateTimeInterface|string|null $now      Current time in UTC (defaults to current time)
 * @return 'scheduled'|'open'|'closed'
 */
function quiz_phase(
    DateTimeInterface|string|null $opensAt,
    DateTimeInterface|string|null $closesAt,
    DateTimeInterface|string|null $now = null
): string {
    if ($opensAt === null || $closesAt === null) {
        return 'closed';
    }

    $toUtc = static function (DateTimeInterface|string $val): DateTimeImmutable {
        if ($val instanceof DateTimeImmutable) {
            return $val->setTimezone(new DateTimeZone('UTC'));
        }
        if ($val instanceof DateTime) {
            return DateTimeImmutable::createFromMutable($val)->setTimezone(new DateTimeZone('UTC'));
        }
        return new DateTimeImmutable($val, new DateTimeZone('UTC'));
    };

    try {
        $start = $toUtc($opensAt);
        $end   = $toUtc($closesAt);
        $cur   = $now !== null ? $toUtc($now) : new DateTimeImmutable('now', new DateTimeZone('UTC'));
    } catch (Throwable $e) {
        return 'closed';
    }

    // Invalid window where closing is not after opening is treated as closed.
    if ($end <= $start) {
        return 'closed';
    }

    if ($cur < $start) {
        return 'scheduled';
    }
    if ($cur < $end) {
        return 'open';
    }
    return 'closed';
}

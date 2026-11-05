<?php
declare(strict_types=1);

/**
 * Slows down password guessing (guide section 7: "5 failures per 15 minutes per username and IP").
 *
 * Three counters over the last 15 minutes:
 *   - this username from this address:   5 failures  (the rule from the guide)
 *   - this username from anywhere:      20 failures  (someone guessing from many addresses)
 *   - this address, any username:       50 failures  (someone trying many usernames)
 * The two wider counters are higher on purpose: a whole school can share ONE address,
 * and one student's typos must never lock the whole class out.
 */
final class RateLimiter
{
    public const WINDOW_SECONDS = 900;
    public const MAX_PAIR = 5;
    public const MAX_IDENTIFIER = 20;
    public const MAX_IP = 50;

    /** Make a username comparable: lower case, trimmed, at most 100 characters. */
    public static function key(string $identifier): string
    {
        return mb_strtolower(mb_substr(trim($identifier), 0, 100));
    }

    public static function isBlocked(string $kind, string $identifier, string $ip): bool
    {
        $id = self::key($identifier);
        $since = gmdate('Y-m-d H:i:s', time() - self::WINDOW_SECONDS);
        $stmt = db()->prepare(
            'SELECT COALESCE(SUM(identifier = ? AND ip = ?), 0) AS pair_fails,
                    COALESCE(SUM(identifier = ?), 0)            AS id_fails,
                    COALESCE(SUM(ip = ?), 0)                    AS ip_fails
               FROM login_attempts
              WHERE kind = ? AND succeeded = 0 AND created_at >= ?
                AND (identifier = ? OR ip = ?)'
        );
        $stmt->execute([$id, $ip, $id, $ip, $kind, $since, $id, $ip]);
        $r = $stmt->fetch();
        return (int) $r['pair_fails'] >= self::MAX_PAIR
            || (int) $r['id_fails']   >= self::MAX_IDENTIFIER
            || (int) $r['ip_fails']   >= self::MAX_IP;
    }

    public static function recordFailure(string $kind, string $identifier, string $ip): void
    {
        db()->prepare(
            'INSERT INTO login_attempts (kind, identifier, ip, succeeded, created_at) VALUES (?, ?, ?, 0, ?)'
        )->execute([$kind, self::key($identifier), $ip, utc_now()]);

        // Tidy up now and then so the table never grows without limit.
        if (random_int(1, 50) === 1) {
            db()->prepare('DELETE FROM login_attempts WHERE created_at < ?')
                ->execute([gmdate('Y-m-d H:i:s', time() - 86400)]);
        }
    }

    /** A correct password wipes that person's earlier mistakes from this address. */
    public static function recordSuccess(string $kind, string $identifier, string $ip): void
    {
        db()->prepare('DELETE FROM login_attempts WHERE kind = ? AND identifier = ? AND ip = ? AND succeeded = 0')
            ->execute([$kind, self::key($identifier), $ip]);
    }

    /** Used when an administrator issues a temporary password: lift the lock for that username. */
    public static function clearFor(string $kind, string $identifier): void
    {
        db()->prepare('DELETE FROM login_attempts WHERE kind = ? AND identifier = ?')
            ->execute([$kind, self::key($identifier)]);
    }
}

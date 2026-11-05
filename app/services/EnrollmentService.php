<?php
declare(strict_types=1);

/** Who is in which class. Joining by code is rate-limited so codes cannot be guessed. */
final class EnrollmentService
{
    public const MAX_FAILURES = 5;      // wrong codes allowed...
    public const WINDOW_MINUTES = 15;   // ...within this many minutes
    private const MAX_FAILURES_PER_IP = 20;

    /** Make a code typed by a person comparable: capitals, no spaces or hyphens. */
    public static function normalizeCode(string $code): string
    {
        return strtoupper(str_replace([' ', '-'], '', trim($code)));
    }

    /** Joins the student to the class with this code. Returns false if the code does not work. */
    public static function joinByCode(int $studentId, string $code): bool
    {
        return self::tryJoin($studentId, $code) === 'joined';
    }

    /**
     * Returns 'joined', 'invalid' (wrong, disabled or archived: deliberately the same answer) or 'limited'.
     * Every wrong guess is recorded in login_attempts (kind 'join').
     */
    public static function tryJoin(int $studentId, string $code): string
    {
        $who = 'student:' . $studentId;
        $ip = substr((string) ($_SERVER['REMOTE_ADDR'] ?? 'cli'), 0, 45);

        if (self::failures('identifier', $who) >= self::MAX_FAILURES || self::failures('ip', $ip) >= self::MAX_FAILURES_PER_IP) {
            return 'limited';
        }

        $code = self::normalizeCode($code);
        $classId = false;
        if ($code !== '') {
            $stmt = db()->prepare(
                'SELECT id FROM classes WHERE join_code = ? AND code_enabled = 1 AND archived_at IS NULL'
            );
            $stmt->execute([$code]);
            $classId = $stmt->fetchColumn();
        }

        db()->prepare('INSERT INTO login_attempts (kind, identifier, ip, succeeded, created_at) VALUES (?, ?, ?, ?, ?)')
            ->execute(['join', $who, $ip, $classId === false ? 0 : 1, utc_now()]);

        if ($classId === false) {
            return 'invalid';
        }
        // If the student was removed earlier and joins again, this simply brings them back.
        db()->prepare(
            'INSERT INTO class_memberships (class_id, student_id, joined_at)
             VALUES (?, ?, ?)
             ON DUPLICATE KEY UPDATE removed_at = NULL'
        )->execute([$classId, $studentId, utc_now()]);
        return 'joined';
    }

    private static function failures(string $column, string $value): int
    {
        $since = gmdate('Y-m-d H:i:s', time() - self::WINDOW_MINUTES * 60);
        $col = $column === 'ip' ? 'ip' : 'identifier';   // fixed choice, never user text
        $stmt = db()->prepare("SELECT COUNT(*) FROM login_attempts WHERE kind = 'join' AND $col = ? AND succeeded = 0 AND created_at > ?");
        $stmt->execute([$value, $since]);
        return (int) $stmt->fetchColumn();
    }
}

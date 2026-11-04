<?php
declare(strict_types=1);

/** Who is in which class. Day 6 adds the teacher side and rate limiting. */
final class EnrollmentService
{
    /** Make a code typed by a person comparable: capitals, no spaces or hyphens. */
    public static function normalizeCode(string $code): string
    {
        return strtoupper(str_replace([' ', '-'], '', trim($code)));
    }

    /** Joins the student to the class with this code. Returns false if the code does not work. */
    public static function joinByCode(int $studentId, string $code): bool
    {
        $code = self::normalizeCode($code);
        if ($code === '') {
            return false;
        }
        $stmt = db()->prepare(
            'SELECT id FROM classes WHERE join_code = ? AND code_enabled = 1 AND archived_at IS NULL'
        );
        $stmt->execute([$code]);
        $classId = $stmt->fetchColumn();
        if ($classId === false) {
            return false;
        }
        // If the student was removed earlier and joins again, this simply brings them back.
        db()->prepare(
            'INSERT INTO class_memberships (class_id, student_id, joined_at)
             VALUES (?, ?, ?)
             ON DUPLICATE KEY UPDATE removed_at = NULL'
        )->execute([$classId, $studentId, utc_now()]);
        return true;
    }
}

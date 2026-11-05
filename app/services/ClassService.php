<?php
declare(strict_types=1);

/**
 * Everything about classes and who is in them. Pages call this; only this runs the SQL.
 *
 * The one habit that keeps teachers out of each other's data: every query that touches a
 * single class goes through findOwned(), which includes "and this class belongs to this teacher".
 * A class you do not own simply does not exist as far as you are concerned (the page shows "not found").
 */
final class ClassService
{
    /** No 0, O, 1 or I: they look alike when read aloud or off a projector. */
    public const CODE_ALPHABET = 'ABCDEFGHJKLMNPQRSTUVWXYZ23456789';
    public const CODE_LENGTH = 6;

    /** "ABC234" shown as "ABC-234". (Typing it with or without the hyphen works: see EnrollmentService.) */
    public static function formatCode(string $code): string
    {
        return strlen($code) === self::CODE_LENGTH ? substr($code, 0, 3) . '-' . substr($code, 3) : $code;
    }

    public static function generateCode(): string
    {
        $max = strlen(self::CODE_ALPHABET) - 1;
        $code = '';
        for ($i = 0; $i < self::CODE_LENGTH; $i++) {
            $code .= self::CODE_ALPHABET[random_int(0, $max)];
        }
        return $code;
    }

    /** The school terms offered in the "School term" list. */
    public static function termOptions(): array
    {
        $y = (int) date('Y');
        $out = [];
        foreach ([$y - 1, $y] as $start) {
            $label = $start . '–' . substr((string) ($start + 1), 2);
            $out[] = $label . ' · First semester';
            $out[] = $label . ' · Second semester';
        }
        return $out;
    }

    // ---------------------------------------------------------------- classes

    /**
     * Creates a class for this teacher with a fresh join code. Returns the new class id.
     * Retries if (very rarely) the random code is already taken.
     */
    public static function create(int $teacherId, string $name, string $courseCode, string $section, string $term): int
    {
        for ($try = 0; $try < 5; $try++) {
            try {
                db()->prepare(
                    'INSERT INTO classes (teacher_id, name, course_code, section, school_year, join_code, code_enabled, created_at)
                     VALUES (?, ?, ?, ?, ?, ?, 1, ?)'
                )->execute([
                    $teacherId, $name, $courseCode !== '' ? $courseCode : null, $section !== '' ? $section : null,
                    $term, self::generateCode(), utc_now(),
                ]);
                return (int) db()->lastInsertId();
            } catch (PDOException $e) {
                if (($e->errorInfo[1] ?? 0) !== 1062) {
                    throw $e;
                }
            }
        }
        throw new RuntimeException('Could not generate a unique join code.');
    }

    /** The teacher's own active classes with live student counts. $search matches name, course code or section. */
    public static function listForTeacher(int $teacherId, string $search = '', ?int $limit = null): array
    {
        $sql = 'SELECT c.*, (SELECT COUNT(*) FROM class_memberships m WHERE m.class_id = c.id AND m.removed_at IS NULL) AS student_count
                  FROM classes c WHERE c.teacher_id = ? AND c.archived_at IS NULL';
        $args = [$teacherId];
        if ($search !== '') {
            $like = '%' . self::escapeLike($search) . '%';
            $sql .= ' AND (c.name LIKE ? OR c.course_code LIKE ? OR c.section LIKE ?)';
            array_push($args, $like, $like, $like);
        }
        $sql .= ' ORDER BY c.created_at DESC, c.id DESC';
        if ($limit !== null) {
            $sql .= ' LIMIT ' . (int) $limit;
        }
        $stmt = db()->prepare($sql);
        $stmt->execute($args);
        return $stmt->fetchAll();
    }

    /** Totals for the "8 classes · 212 students" line. */
    public static function totalsForTeacher(int $teacherId): array
    {
        $stmt = db()->prepare(
            'SELECT COUNT(DISTINCT c.id) AS classes, COUNT(m.id) AS students
               FROM classes c
               LEFT JOIN class_memberships m ON m.class_id = c.id AND m.removed_at IS NULL
              WHERE c.teacher_id = ? AND c.archived_at IS NULL'
        );
        $stmt->execute([$teacherId]);
        $r = $stmt->fetch();
        return ['classes' => (int) $r['classes'], 'students' => (int) $r['students']];
    }

    /**
     * The one class this person may manage, or null. Teachers only get their own classes;
     * an administrator may open any class. Archived classes can still be opened (read only).
     */
    public static function findOwned(int $classId, array $user): ?array
    {
        $sql = 'SELECT c.*, u.full_name AS owner_name,
                       (SELECT COUNT(*) FROM class_memberships m WHERE m.class_id = c.id AND m.removed_at IS NULL) AS student_count
                  FROM classes c JOIN users u ON u.id = c.teacher_id
                 WHERE c.id = ?';
        $args = [$classId];
        if ($user['role'] !== 'admin') {
            $sql .= ' AND c.teacher_id = ?';
            $args[] = (int) $user['id'];
        }
        $stmt = db()->prepare($sql);
        $stmt->execute($args);
        $row = $stmt->fetch();
        return $row === false ? null : $row;
    }

    /** Every class, for the administrator's overview. */
    public static function listAll(): array
    {
        return db()->query(
            'SELECT c.*, u.full_name AS owner_name,
                    (SELECT COUNT(*) FROM class_memberships m WHERE m.class_id = c.id AND m.removed_at IS NULL) AS student_count
               FROM classes c JOIN users u ON u.id = c.teacher_id
              ORDER BY c.archived_at IS NOT NULL, c.created_at DESC'
        )->fetchAll();
    }

    /** Old code stops working immediately. Students already enrolled keep access. */
    public static function regenerateCode(int $classId): void
    {
        for ($try = 0; $try < 5; $try++) {
            try {
                db()->prepare('UPDATE classes SET join_code = ?, code_enabled = 1 WHERE id = ? AND archived_at IS NULL')
                    ->execute([self::generateCode(), $classId]);
                return;
            } catch (PDOException $e) {
                if (($e->errorInfo[1] ?? 0) !== 1062) {
                    throw $e;
                }
            }
        }
        throw new RuntimeException('Could not generate a unique join code.');
    }

    public static function setCodeEnabled(int $classId, bool $enabled): void
    {
        db()->prepare('UPDATE classes SET code_enabled = ? WHERE id = ? AND archived_at IS NULL')
            ->execute([$enabled ? 1 : 0, $classId]);
    }

    /** Archive, never delete: attempts, grades and rosters are kept. Joining closes. */
    public static function archive(int $classId): void
    {
        db()->prepare('UPDATE classes SET archived_at = ?, code_enabled = 0 WHERE id = ? AND archived_at IS NULL')
            ->execute([utc_now(), $classId]);
    }

    // ---------------------------------------------------------------- roster

    public static function rosterCount(int $classId, string $search = ''): int
    {
        [$where, $args] = self::rosterWhere($classId, $search);
        $stmt = db()->prepare('SELECT COUNT(*) FROM class_memberships m JOIN users u ON u.id = m.student_id WHERE ' . $where);
        $stmt->execute($args);
        return (int) $stmt->fetchColumn();
    }

    public static function roster(int $classId, string $search = '', int $page = 1, int $perPage = 10): array
    {
        [$where, $args] = self::rosterWhere($classId, $search);
        $offset = max(0, ($page - 1) * $perPage);
        $stmt = db()->prepare(
            'SELECT u.id, u.full_name, u.username, m.joined_at
               FROM class_memberships m JOIN users u ON u.id = m.student_id
              WHERE ' . $where . ' ORDER BY u.full_name, u.id LIMIT ' . (int) $perPage . ' OFFSET ' . (int) $offset
        );
        $stmt->execute($args);
        return $stmt->fetchAll();
    }

    /** @return array{0:string,1:array} */
    private static function rosterWhere(int $classId, string $search): array
    {
        $where = 'm.class_id = ? AND m.removed_at IS NULL';
        $args = [$classId];
        if ($search !== '') {
            $like = '%' . self::escapeLike($search) . '%';
            $where .= ' AND (u.full_name LIKE ? OR u.username LIKE ?)';
            array_push($args, $like, $like);
        }
        return [$where, $args];
    }

    /** Returns 'added', 'already', 'closed', or 'not_found' (also used for non-students, so no one learns who is a teacher). */
    public static function addByUsername(int $classId, string $username): string
    {
        $class = db()->prepare('SELECT archived_at FROM classes WHERE id = ?');
        $class->execute([$classId]);
        $c = $class->fetch();
        if ($c === false || $c['archived_at'] !== null) {
            return 'closed';
        }
        $stmt = db()->prepare("SELECT id FROM users WHERE username = ? AND role = 'student' AND status = 'active'");
        $stmt->execute([$username]);
        $studentId = $stmt->fetchColumn();
        if ($studentId === false) {
            return 'not_found';
        }
        $ex = db()->prepare('SELECT removed_at FROM class_memberships WHERE class_id = ? AND student_id = ?');
        $ex->execute([$classId, $studentId]);
        $row = $ex->fetch();
        if ($row !== false && $row['removed_at'] === null) {
            return 'already';
        }
        db()->prepare(
            'INSERT INTO class_memberships (class_id, student_id, joined_at) VALUES (?, ?, ?)
             ON DUPLICATE KEY UPDATE removed_at = NULL, joined_at = VALUES(joined_at)'
        )->execute([$classId, $studentId, utc_now()]);
        return 'added';
    }

    /** Soft remove: the student's earlier attempts and grades are kept. */
    public static function removeStudent(int $classId, int $studentId): void
    {
        db()->prepare('UPDATE class_memberships SET removed_at = ? WHERE class_id = ? AND student_id = ? AND removed_at IS NULL')
            ->execute([utc_now(), $classId, $studentId]);
    }

    /**
     * Gives a student of THIS class a temporary password and forces a change at next sign-in.
     * Returns the temporary password, or null if that student is not in this class.
     */
    public static function issueTemporaryPassword(int $classId, int $studentId): ?string
    {
        $stmt = db()->prepare(
            "SELECT u.id FROM class_memberships m JOIN users u ON u.id = m.student_id
              WHERE m.class_id = ? AND m.student_id = ? AND m.removed_at IS NULL AND u.role = 'student'"
        );
        $stmt->execute([$classId, $studentId]);
        if ($stmt->fetchColumn() === false) {
            return null;
        }
        $temp = '';
        for ($i = 0; $i < 12; $i++) {
            $temp .= self::CODE_ALPHABET[random_int(0, strlen(self::CODE_ALPHABET) - 1)];
        }
        db()->prepare('UPDATE users SET password_hash = ?, must_change_password = 1 WHERE id = ?')
            ->execute([password_hash($temp, PASSWORD_DEFAULT), $studentId]);
        return $temp;
    }

    // ---------------------------------------------------------------- student side

    public static function listForStudent(int $studentId): array
    {
        $stmt = db()->prepare(
            'SELECT c.id, c.name, c.course_code, c.section, c.school_year, u.full_name AS owner_name
               FROM class_memberships m JOIN classes c ON c.id = m.class_id JOIN users u ON u.id = c.teacher_id
              WHERE m.student_id = ? AND m.removed_at IS NULL AND c.archived_at IS NULL
              ORDER BY c.name'
        );
        $stmt->execute([$studentId]);
        return $stmt->fetchAll();
    }

    private static function escapeLike(string $s): string
    {
        return str_replace(['\\', '%', '_'], ['\\\\', '\\%', '\\_'], $s);
    }
}

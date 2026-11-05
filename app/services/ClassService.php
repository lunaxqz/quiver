<?php
declare(strict_types=1);

/** Classes. Day 5 only needs the administrator's read-only list; Day 6 adds the rest. */
final class ClassService
{
    /** Newest classes first. Shows records only: never answers or grades. */
    public static function adminList(string $q, int $limit = 10): array
    {
        $args = [];
        $where = '';
        if ($q !== '') {
            $like = '%' . str_replace(['\\', '%', '_'], ['\\\\', '\\%', '\\_'], $q) . '%';
            $where = 'WHERE (c.name LIKE ? OR u.full_name LIKE ?)';
            $args = [$like, $like];
        }
        $stmt = db()->prepare(
            "SELECT c.id, c.name, c.subject, c.section, c.school_year, c.code_enabled, c.archived_at,
                    u.full_name AS teacher_name,
                    (SELECT COUNT(*) FROM class_memberships m
                      WHERE m.class_id = c.id AND m.removed_at IS NULL) AS students
               FROM classes c
               JOIN users u ON u.id = c.teacher_id
               $where
              ORDER BY c.created_at DESC, c.id DESC
              LIMIT ?"
        );
        $i = 1;
        foreach ($args as $a) {
            $stmt->bindValue($i++, $a);
        }
        $stmt->bindValue($i, $limit, PDO::PARAM_INT);
        $stmt->execute();
        return $stmt->fetchAll();
    }
}

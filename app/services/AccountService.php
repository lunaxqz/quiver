<?php
declare(strict_types=1);

/** Administrator tools for accounts. Accounts are deactivated, never deleted. */
final class AccountService
{
    public const PAGE_SIZE = 10;

    /** Make text safe to use inside LIKE '%...%' (so a typed % or _ is just a character). */
    private static function escapeLike(string $s): string
    {
        return str_replace(['\\', '%', '_'], ['\\\\', '\\%', '\\_'], $s);
    }

    /** @return array{rows: array, total: int, page: int, pages: int} */
    public static function search(string $q, string $role, string $status, int $page): array
    {
        $where = [];
        $args  = [];
        if ($q !== '') {
            $like = '%' . self::escapeLike($q) . '%';
            $where[] = '(full_name LIKE ? OR username LIKE ?)';
            $args[] = $like;
            $args[] = $like;
        }
        if (in_array($role, ['admin', 'teacher', 'student'], true)) {
            $where[] = 'role = ?';
            $args[]  = $role;
        }
        if (in_array($status, ['active', 'disabled'], true)) {
            $where[] = 'status = ?';
            $args[]  = $status;
        }
        $w = $where ? 'WHERE ' . implode(' AND ', $where) : '';

        $count = db()->prepare("SELECT COUNT(*) FROM users $w");
        $count->execute($args);
        $total = (int) $count->fetchColumn();
        $pages = max(1, (int) ceil($total / self::PAGE_SIZE));
        $page  = min(max(1, $page), $pages);

        $stmt = db()->prepare(
            "SELECT id, username, full_name, role, status, must_change_password, last_login_at
               FROM users $w
              ORDER BY created_at DESC, id DESC
              LIMIT ? OFFSET ?"
        );
        $i = 1;
        foreach ($args as $a) {
            $stmt->bindValue($i++, $a);
        }
        $stmt->bindValue($i++, self::PAGE_SIZE, PDO::PARAM_INT);
        $stmt->bindValue($i, ($page - 1) * self::PAGE_SIZE, PDO::PARAM_INT);
        $stmt->execute();

        return ['rows' => $stmt->fetchAll(), 'total' => $total, 'page' => $page, 'pages' => $pages];
    }

    /**
     * Creates a teacher with a temporary password they must change at first sign-in.
     * If $tempPassword is null a strong one is generated. Returns the password so it can be shown ONCE.
     * @throws DuplicateUsernameException
     */
    public static function createTeacher(string $fullName, string $username, ?string $tempPassword): string
    {
        $password = ($tempPassword === null || $tempPassword === '') ? AuthService::randomPassword() : $tempPassword;
        AuthService::createUser($username, $fullName, $password, 'teacher', true);
        return $password;
    }

    /** @return array{ok: bool, error?: string, password?: string, name?: string} */
    public static function issueTemporaryPassword(string $username, int $actingAdminId): array
    {
        $stmt = db()->prepare('SELECT id, full_name, username FROM users WHERE username = ?');
        $stmt->execute([$username]);
        $u = $stmt->fetch();
        if ($u === false) {
            return ['ok' => false, 'error' => 'No account has that username.'];
        }
        if ((int) $u['id'] === $actingAdminId) {
            return ['ok' => false, 'error' => 'Use "Change password" for your own account.'];
        }
        $password = AuthService::randomPassword();
        db()->prepare('UPDATE users SET password_hash = ?, must_change_password = 1 WHERE id = ?')
            ->execute([password_hash($password, PASSWORD_DEFAULT), $u['id']]);
        // If they were locked out by too many wrong tries, the temporary password must work straight away.
        RateLimiter::clearFor('login', (string) $u['username']);
        return ['ok' => true, 'password' => $password, 'name' => (string) $u['full_name']];
    }

    /** Deactivate or reactivate. Returns an error message, or null on success. */
    public static function setStatus(int $userId, string $status, int $actingAdminId): ?string
    {
        if (!in_array($status, ['active', 'disabled'], true)) {
            return 'That is not a valid status.';
        }
        if ($userId === $actingAdminId) {
            return 'You cannot deactivate your own account.';
        }
        $stmt = db()->prepare('UPDATE users SET status = ? WHERE id = ?');
        $stmt->execute([$status, $userId]);
        return $stmt->rowCount() === 0 && !self::exists($userId) ? 'That account was not found.' : null;
    }

    private static function exists(int $userId): bool
    {
        $stmt = db()->prepare('SELECT 1 FROM users WHERE id = ?');
        $stmt->execute([$userId]);
        return $stmt->fetchColumn() !== false;
    }
}

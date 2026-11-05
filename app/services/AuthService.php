<?php
declare(strict_types=1);

final class DuplicateUsernameException extends RuntimeException
{
}

/** Everything about accounts and passwords. Pages call this; only this runs the SQL. */
final class AuthService
{
    public static function findActiveById(int $id): ?array
    {
        $stmt = db()->prepare(
            "SELECT id, username, full_name, role, status, must_change_password
               FROM users WHERE id = ? AND status = 'active'"
        );
        $stmt->execute([$id]);
        $row = $stmt->fetch();
        return $row === false ? null : $row;
    }

    /** Returns the user if the username and password are right and the account is active, otherwise null. */
    public static function attempt(string $username, string $password): ?array
    {
        $stmt = db()->prepare(
            'SELECT id, username, full_name, role, status, must_change_password, password_hash
               FROM users WHERE username = ? LIMIT 1'
        );
        $stmt->execute([$username]);
        $row = $stmt->fetch();

        // Always do one password check, even for an unknown username, so timing does not reveal which usernames exist.
        $hash = $row['password_hash'] ?? self::dummyHash();
        $ok = password_verify($password, $hash);

        if ($row === false || !$ok || $row['status'] !== 'active') {
            return null;
        }

        if (password_needs_rehash($row['password_hash'], PASSWORD_DEFAULT)) {
            db()->prepare('UPDATE users SET password_hash = ? WHERE id = ?')
                ->execute([password_hash($password, PASSWORD_DEFAULT), $row['id']]);
        }
        db()->prepare('UPDATE users SET last_login_at = ? WHERE id = ?')
            ->execute([utc_now(), $row['id']]);

        unset($row['password_hash']);
        return $row;
    }

    /** Creates a student account and returns its id. Throws DuplicateUsernameException if the name is taken. */
    public static function registerStudent(string $username, string $fullName, string $password): int
    {
        try {
            db()->prepare(
                "INSERT INTO users (username, full_name, password_hash, role, created_at)
                 VALUES (?, ?, ?, 'student', ?)"
            )->execute([$username, $fullName, password_hash($password, PASSWORD_DEFAULT), utc_now()]);
        } catch (PDOException $e) {
            if (($e->errorInfo[1] ?? 0) === 1062) {   // the database's own "already exists" rule
                throw new DuplicateUsernameException();
            }
            throw $e;
        }
        return (int) db()->lastInsertId();
    }

    /** Creates any account (used by the admin script today, the admin screen on Day 5). */
    public static function createUser(string $username, string $fullName, string $password, string $role, bool $mustChange = false): int
    {
        try {
            db()->prepare(
                'INSERT INTO users (username, full_name, password_hash, role, must_change_password, created_at)
                 VALUES (?, ?, ?, ?, ?, ?)'
            )->execute([$username, $fullName, password_hash($password, PASSWORD_DEFAULT), $role, $mustChange ? 1 : 0, utc_now()]);
        } catch (PDOException $e) {
            if (($e->errorInfo[1] ?? 0) === 1062) {
                throw new DuplicateUsernameException();
            }
            throw $e;
        }
        return (int) db()->lastInsertId();
    }

    /** A strong random password from an alphabet without look-alike characters (no 0/O, 1/l/I). */
    public static function randomPassword(int $length = 16): string
    {
        $alphabet = 'abcdefghijkmnpqrstuvwxyzABCDEFGHJKLMNPQRSTUVWXYZ23456789';
        $out = '';
        for ($i = 0; $i < $length; $i++) {
            $out .= $alphabet[random_int(0, strlen($alphabet) - 1)];
        }
        return $out;
    }

    /** Is this the right password for this account? (Used before allowing a password change.) */
    public static function verifyPassword(int $userId, string $password): bool
    {
        $stmt = db()->prepare('SELECT password_hash FROM users WHERE id = ?');
        $stmt->execute([$userId]);
        $hash = $stmt->fetchColumn();
        return is_string($hash) && password_verify($password, $hash);
    }

    /** Sets a new password the person chose themselves, and ends any "must change" requirement. */
    public static function changePassword(int $userId, string $newPassword): void
    {
        db()->prepare('UPDATE users SET password_hash = ?, must_change_password = 0 WHERE id = ?')
            ->execute([password_hash($newPassword, PASSWORD_DEFAULT), $userId]);
    }

    private static function dummyHash(): string
    {
        static $hash = null;
        return $hash ??= password_hash('not-a-real-password', PASSWORD_DEFAULT);
    }
}

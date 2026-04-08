<?php

namespace App\Repositories;

use App\Support\Database;

final class UserRepository
{
    private static array $publicFields = ['id', 'email', 'display_name', 'title', 'avatar_color', 'status', 'last_seen_at'];

    private static function selectPublic(string $alias = ''): string
    {
        $prefix = $alias !== '' ? "$alias." : '';
        return implode(',', array_map(fn($f) => $prefix . $f, self::$publicFields));
    }

    public static function find(int $id): ?array
    {
        $stmt = Database::connection()->prepare(
            'SELECT ' . self::selectPublic() . ' FROM users WHERE id = ?'
        );
        $stmt->execute([$id]);
        return $stmt->fetch() ?: null;
    }

    public static function findByEmail(string $email): ?array
    {
        $stmt = Database::connection()->prepare(
            'SELECT id, email, password_hash, display_name, title, avatar_color, status, last_seen_at FROM users WHERE email = ?'
        );
        $stmt->execute([$email]);
        return $stmt->fetch() ?: null;
    }

    public static function all(): array
    {
        return Database::connection()
            ->query('SELECT ' . self::selectPublic() . ' FROM users ORDER BY display_name')
            ->fetchAll();
    }

    /** Return all users who share at least one space with the given user. */
    public static function coMembers(int $userId): array
    {
        $sql = '
            SELECT DISTINCT ' . self::selectPublic('u') . '
            FROM users u
            JOIN space_members sm ON sm.user_id = u.id
            WHERE sm.space_id IN (SELECT space_id FROM space_members WHERE user_id = ?)
            ORDER BY u.display_name
        ';
        $stmt = Database::connection()->prepare($sql);
        $stmt->execute([$userId]);
        return $stmt->fetchAll();
    }

    public static function updateStatus(int $id, string $status): void
    {
        $stmt = Database::connection()->prepare(
            'UPDATE users SET status = ?, last_seen_at = NOW() WHERE id = ?'
        );
        $stmt->execute([$status, $id]);
    }

    public static function touchPresence(int $id): void
    {
        $stmt = Database::connection()->prepare(
            'UPDATE users SET last_seen_at = NOW(), status = "online" WHERE id = ?'
        );
        $stmt->execute([$id]);
    }

    /** Mark stale users (no heartbeat for >2min) as away, >10min as offline */
    public static function expirePresence(): void
    {
        Database::connection()->exec("
            UPDATE users SET status = 'away'
            WHERE status = 'online' AND last_seen_at < DATE_SUB(NOW(), INTERVAL 2 MINUTE)
        ");
        Database::connection()->exec("
            UPDATE users SET status = 'offline'
            WHERE status IN ('online','away') AND last_seen_at < DATE_SUB(NOW(), INTERVAL 10 MINUTE)
        ");
    }
}


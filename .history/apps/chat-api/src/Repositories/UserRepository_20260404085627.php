<?php

namespace App\Repositories;

use App\Support\Database;

final class UserRepository
{
    private static array $fields = ['id', 'email', 'display_name', 'title', 'avatar_color', 'status'];

    public static function find(int $id): ?array
    {
        $stmt = Database::connection()->prepare(
            'SELECT ' . implode(',', self::$fields) . ' FROM users WHERE id = ?'
        );
        $stmt->execute([$id]);
        return $stmt->fetch() ?: null;
    }

    public static function findByEmail(string $email): ?array
    {
        $stmt = Database::connection()->prepare(
            'SELECT ' . implode(',', self::$fields) . ' FROM users WHERE email = ?'
        );
        $stmt->execute([$email]);
        return $stmt->fetch() ?: null;
    }

    public static function all(): array
    {
        return Database::connection()
            ->query('SELECT ' . implode(',', self::$fields) . ' FROM users ORDER BY display_name')
            ->fetchAll();
    }

    public static function updateStatus(int $id, string $status): void
    {
        $stmt = Database::connection()->prepare('UPDATE users SET status = ? WHERE id = ?');
        $stmt->execute([$status, $id]);
    }
}


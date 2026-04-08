<?php

namespace App\Repositories;

use App\Support\Database;

final class SpaceRepository
{
    public static function find(int $id): ?array
    {
        $stmt = Database::connection()->prepare('SELECT * FROM spaces WHERE id = ?');
        $stmt->execute([$id]);
        return $stmt->fetch() ?: null;
    }

    public static function forUser(int $userId): array
    {
        $sql = '
            SELECT s.*, sm.role
            FROM spaces s
            JOIN space_members sm ON sm.space_id = s.id
            WHERE sm.user_id = ?
            ORDER BY s.name
        ';
        $stmt = Database::connection()->prepare($sql);
        $stmt->execute([$userId]);
        return $stmt->fetchAll();
    }

    public static function memberRole(int $spaceId, int $userId): ?string
    {
        $stmt = Database::connection()->prepare(
            'SELECT role FROM space_members WHERE space_id = ? AND user_id = ?'
        );
        $stmt->execute([$spaceId, $userId]);
        $row = $stmt->fetch();
        return $row ? $row['role'] : null;
    }

    public static function isMember(int $spaceId, int $userId): bool
    {
        return self::memberRole($spaceId, $userId) !== null;
    }

    public static function isAdminOrOwner(int $spaceId, int $userId): bool
    {
        $role = self::memberRole($spaceId, $userId);
        return in_array($role, ['owner', 'admin'], true);
    }

    public static function members(int $spaceId): array
    {
        $sql = '
            SELECT u.id, u.email, u.display_name, u.title, u.avatar_color, u.status, u.last_seen_at,
                   sm.role
            FROM users u
            JOIN space_members sm ON sm.user_id = u.id
            WHERE sm.space_id = ?
            ORDER BY u.display_name
        ';
        $stmt = Database::connection()->prepare($sql);
        $stmt->execute([$spaceId]);
        return $stmt->fetchAll();
    }

    public static function create(string $name, string $slug, string $description, int $ownerId): array
    {
        $stmt = Database::connection()->prepare(
            'INSERT INTO spaces (name, slug, description, owner_id) VALUES (?, ?, ?, ?)'
        );
        $stmt->execute([$name, $slug, $description, $ownerId]);
        $id = (int) Database::connection()->lastInsertId();

        self::addMember($id, $ownerId, 'owner');

        return self::find($id);
    }

    public static function addMember(int $spaceId, int $userId, string $role = 'member'): void
    {
        $stmt = Database::connection()->prepare(
            'INSERT IGNORE INTO space_members (space_id, user_id, role) VALUES (?, ?, ?)'
        );
        $stmt->execute([$spaceId, $userId, $role]);
    }

    public static function updateMemberRole(int $spaceId, int $userId, string $role): void
    {
        $stmt = Database::connection()->prepare(
            'UPDATE space_members SET role = ? WHERE space_id = ? AND user_id = ?'
        );
        $stmt->execute([$role, $spaceId, $userId]);
    }

    public static function removeMember(int $spaceId, int $userId): void
    {
        $stmt = Database::connection()->prepare(
            'DELETE FROM space_members WHERE space_id = ? AND user_id = ?'
        );
        $stmt->execute([$spaceId, $userId]);
    }

    /** Check whether two users share at least one space. */
    public static function sharesSpace(int $userId1, int $userId2): bool
    {
        $stmt = Database::connection()->prepare('
            SELECT 1 FROM space_members sm1
            JOIN space_members sm2 ON sm2.space_id = sm1.space_id
            WHERE sm1.user_id = ? AND sm2.user_id = ?
            LIMIT 1
        ');
        $stmt->execute([$userId1, $userId2]);
        return (bool) $stmt->fetch();
    }
}

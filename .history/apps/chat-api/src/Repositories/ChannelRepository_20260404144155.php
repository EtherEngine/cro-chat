<?php

namespace App\Repositories;

use App\Support\Database;

final class ChannelRepository
{
    public static function find(int $id): ?array
    {
        $stmt = Database::connection()->prepare(
            'SELECT c.*, (SELECT COUNT(*) FROM channel_members WHERE channel_id = c.id) AS member_count
             FROM channels c WHERE c.id = ?'
        );
        $stmt->execute([$id]);
        return $stmt->fetch() ?: null;
    }

    public static function forSpace(int $spaceId, int $userId): array
    {
        $sql = '
            SELECT c.id, c.space_id, c.name, c.description, c.color, c.is_private,
                   (SELECT COUNT(*) FROM channel_members WHERE channel_id = c.id) AS member_count
            FROM channels c
            LEFT JOIN channel_members cm ON cm.channel_id = c.id AND cm.user_id = ?
            WHERE c.space_id = ?
              AND (c.is_private = 0 OR cm.user_id IS NOT NULL)
            ORDER BY c.name
        ';
        $stmt = Database::connection()->prepare($sql);
        $stmt->execute([$userId, $spaceId]);
        return $stmt->fetchAll();
    }

    public static function members(int $channelId): array
    {
        $sql = '
            SELECT u.id, u.email, u.display_name, u.title, u.avatar_color, u.status, u.last_seen_at,
                   cm.role AS channel_role
            FROM users u
            JOIN channel_members cm ON cm.user_id = u.id
            WHERE cm.channel_id = ?
            ORDER BY u.display_name
        ';
        $stmt = Database::connection()->prepare($sql);
        $stmt->execute([$channelId]);
        return $stmt->fetchAll();
    }

    public static function isMember(int $channelId, int $userId): bool
    {
        $stmt = Database::connection()->prepare(
            'SELECT 1 FROM channel_members WHERE channel_id = ? AND user_id = ?'
        );
        $stmt->execute([$channelId, $userId]);
        return (bool) $stmt->fetch();
    }

    public static function memberRole(int $channelId, int $userId): ?string
    {
        $stmt = Database::connection()->prepare(
            'SELECT role FROM channel_members WHERE channel_id = ? AND user_id = ?'
        );
        $stmt->execute([$channelId, $userId]);
        $row = $stmt->fetch();
        return $row ? $row['role'] : null;
    }

    public static function create(int $spaceId, string $name, string $description, string $color, bool $isPrivate, int $createdBy): array
    {
        $stmt = Database::connection()->prepare(
            'INSERT INTO channels (space_id, name, description, color, is_private, created_by) VALUES (?, ?, ?, ?, ?, ?)'
        );
        $stmt->execute([$spaceId, $name, $description, $color, (int) $isPrivate, $createdBy]);
        $id = (int) Database::connection()->lastInsertId();

        // Creator becomes channel admin
        self::addMember($id, $createdBy, 'admin');

        return self::find($id);
    }

    public static function update(int $id, array $data): array
    {
        $sets = [];
        $vals = [];
        foreach (['name', 'description', 'color', 'is_private'] as $key) {
            if (array_key_exists($key, $data)) {
                $sets[] = "$key = ?";
                $vals[] = $data[$key];
            }
        }
        if ($sets) {
            $vals[] = $id;
            $stmt = Database::connection()->prepare(
                'UPDATE channels SET ' . implode(', ', $sets) . ' WHERE id = ?'
            );
            $stmt->execute($vals);
        }
        return self::find($id);
    }

    public static function delete(int $id): void
    {
        $stmt = Database::connection()->prepare('DELETE FROM channels WHERE id = ?');
        $stmt->execute([$id]);
    }

    public static function addMember(int $channelId, int $userId, string $role = 'member'): void
    {
        $stmt = Database::connection()->prepare(
            'INSERT IGNORE INTO channel_members (channel_id, user_id, role) VALUES (?, ?, ?)'
        );
        $stmt->execute([$channelId, $userId, $role]);
    }

    public static function removeMember(int $channelId, int $userId): void
    {
        $stmt = Database::connection()->prepare(
            'DELETE FROM channel_members WHERE channel_id = ? AND user_id = ?'
        );
        $stmt->execute([$channelId, $userId]);
    }

    public static function updateMemberRole(int $channelId, int $userId, string $role): void
    {
        $stmt = Database::connection()->prepare(
            'UPDATE channel_members SET role = ? WHERE channel_id = ? AND user_id = ?'
        );
        $stmt->execute([$role, $channelId, $userId]);
    }

    public static function updateMutedUntil(int $channelId, int $userId, ?string $mutedUntil): void
    {
        $stmt = Database::connection()->prepare(
            'UPDATE channel_members SET muted_until = ? WHERE channel_id = ? AND user_id = ?'
        );
        $stmt->execute([$mutedUntil, $channelId, $userId]);
    }

    public static function getMutedUntil(int $channelId, int $userId): ?string
    {
        $stmt = Database::connection()->prepare(
            'SELECT muted_until FROM channel_members WHERE channel_id = ? AND user_id = ?'
        );
        $stmt->execute([$channelId, $userId]);
        $row = $stmt->fetch();
        return $row ? $row['muted_until'] : null;
    }
}


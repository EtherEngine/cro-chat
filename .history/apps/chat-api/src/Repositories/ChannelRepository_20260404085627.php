<?php

namespace App\Repositories;

use App\Support\Database;

final class ChannelRepository
{
    public static function forUser(int $userId): array
    {
        $sql = '
            SELECT c.id, c.name, c.description, c.color,
                   (SELECT COUNT(*) FROM channel_members WHERE channel_id = c.id) AS member_count
            FROM channels c
            JOIN channel_members cm ON cm.channel_id = c.id
            WHERE cm.user_id = ?
            ORDER BY c.name
        ';
        $stmt = Database::connection()->prepare($sql);
        $stmt->execute([$userId]);
        return $stmt->fetchAll();
    }

    public static function members(int $channelId): array
    {
        $sql = '
            SELECT u.id, u.email, u.display_name, u.title, u.avatar_color, u.status
            FROM users u
            JOIN channel_members cm ON cm.user_id = u.id
            WHERE cm.channel_id = ?
            ORDER BY u.display_name
        ';
        $stmt = Database::connection()->prepare($sql);
        $stmt->execute([$channelId]);
        return $stmt->fetchAll();
    }
}


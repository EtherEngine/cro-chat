<?php

declare(strict_types=1);

namespace App\Repositories;

use App\Support\Database;

final class ModerationRepository
{
    public static function log(
        int $spaceId,
        string $actionType,
        int $actorId,
        ?int $channelId = null,
        ?int $targetUserId = null,
        ?int $messageId = null,
        ?string $reason = null,
        ?array $metadata = null
    ): array {
        $db = Database::connection();
        $db->prepare(
            'INSERT INTO moderation_actions (space_id, channel_id, action_type, actor_id, target_user_id, message_id, reason, metadata)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?)'
        )->execute([
            $spaceId,
            $channelId,
            $actionType,
            $actorId,
            $targetUserId,
            $messageId,
            $reason,
            $metadata ? json_encode($metadata) : null,
        ]);

        return self::find((int) $db->lastInsertId());
    }

    public static function find(int $id): ?array
    {
        $stmt = Database::connection()->prepare('SELECT * FROM moderation_actions WHERE id = ?');
        $stmt->execute([$id]);
        return $stmt->fetch() ?: null;
    }

    public static function forSpace(int $spaceId, int $limit = 50, ?int $before = null): array
    {
        $sql = '
            SELECT ma.*,
                   a.display_name AS actor_name,
                   t.display_name AS target_name
            FROM moderation_actions ma
            JOIN users a ON a.id = ma.actor_id
            LEFT JOIN users t ON t.id = ma.target_user_id
            WHERE ma.space_id = ?
        ';
        $params = [$spaceId];

        if ($before !== null) {
            $sql .= ' AND ma.id < ?';
            $params[] = $before;
        }

        $sql .= ' ORDER BY ma.id DESC LIMIT ?';
        $params[] = $limit;

        $stmt = Database::connection()->prepare($sql);
        $stmt->execute($params);
        return $stmt->fetchAll();
    }

    public static function forChannel(int $channelId, int $limit = 50, ?int $before = null): array
    {
        $sql = '
            SELECT ma.*,
                   a.display_name AS actor_name,
                   t.display_name AS target_name
            FROM moderation_actions ma
            JOIN users a ON a.id = ma.actor_id
            LEFT JOIN users t ON t.id = ma.target_user_id
            WHERE ma.channel_id = ?
        ';
        $params = [$channelId];

        if ($before !== null) {
            $sql .= ' AND ma.id < ?';
            $params[] = $before;
        }

        $sql .= ' ORDER BY ma.id DESC LIMIT ?';
        $params[] = $limit;

        $stmt = Database::connection()->prepare($sql);
        $stmt->execute($params);
        return $stmt->fetchAll();
    }
}

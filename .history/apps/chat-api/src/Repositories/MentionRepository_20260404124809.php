<?php

declare(strict_types=1);

namespace App\Repositories;

use App\Support\Database;

final class MentionRepository
{
    /**
     * Store mentions for a message (batch insert, skip duplicates).
     */
    public static function store(int $messageId, array $userIds): void
    {
        if (empty($userIds)) {
            return;
        }

        $db = Database::connection();
        $stmt = $db->prepare(
            'INSERT IGNORE INTO message_mentions (message_id, mentioned_user_id) VALUES (?, ?)'
        );
        foreach ($userIds as $userId) {
            $stmt->execute([$messageId, $userId]);
        }
    }

    /**
     * Delete all mentions for a message (used before re-parsing on edit).
     */
    public static function deleteForMessage(int $messageId): void
    {
        Database::connection()->prepare(
            'DELETE FROM message_mentions WHERE message_id = ?'
        )->execute([$messageId]);
    }

    /**
     * Batch-load mentions for multiple messages (avoids N+1).
     * Returns [ messageId => [ { user_id, display_name, avatar_color } ] ]
     */
    public static function forMessages(array $messageIds): array
    {
        if (empty($messageIds)) {
            return [];
        }

        $placeholders = implode(',', array_fill(0, count($messageIds), '?'));
        $stmt = Database::connection()->prepare(
            "SELECT mm.message_id, mm.mentioned_user_id, u.display_name, u.avatar_color
             FROM message_mentions mm
             JOIN users u ON u.id = mm.mentioned_user_id
             WHERE mm.message_id IN ($placeholders)
             ORDER BY mm.message_id, mm.id ASC"
        );
        $stmt->execute($messageIds);

        $map = [];
        foreach ($stmt->fetchAll() as $row) {
            $mid = (int) $row['message_id'];
            $map[$mid][] = [
                'user_id' => (int) $row['mentioned_user_id'],
                'display_name' => $row['display_name'],
                'avatar_color' => $row['avatar_color'],
            ];
        }
        return $map;
    }

    /**
     * Mentions for a single message.
     */
    public static function forMessage(int $messageId): array
    {
        $result = self::forMessages([$messageId]);
        return $result[$messageId] ?? [];
    }
}

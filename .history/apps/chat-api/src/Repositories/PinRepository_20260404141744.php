<?php

declare(strict_types=1);

namespace App\Repositories;

use App\Support\Database;

final class PinRepository
{
    /**
     * Pin a message. Returns true if newly pinned, false if already pinned.
     */
    public static function pin(int $messageId, ?int $channelId, ?int $conversationId, int $pinnedBy): bool
    {
        try {
            Database::connection()->prepare(
                'INSERT INTO pinned_messages (message_id, channel_id, conversation_id, pinned_by) VALUES (?, ?, ?, ?)'
            )->execute([$messageId, $channelId, $conversationId, $pinnedBy]);
            return true;
        } catch (\PDOException $e) {
            if ((int) $e->errorInfo[1] === 1062) {
                return false;
            }
            throw $e;
        }
    }

    /**
     * Unpin a message. Returns true if removed, false if was not pinned.
     */
    public static function unpin(int $messageId): bool
    {
        $stmt = Database::connection()->prepare(
            'DELETE FROM pinned_messages WHERE message_id = ?'
        );
        $stmt->execute([$messageId]);
        return $stmt->rowCount() > 0;
    }

    /**
     * Check whether a message is pinned.
     */
    public static function isPinned(int $messageId): bool
    {
        $stmt = Database::connection()->prepare(
            'SELECT 1 FROM pinned_messages WHERE message_id = ? LIMIT 1'
        );
        $stmt->execute([$messageId]);
        return (bool) $stmt->fetchColumn();
    }

    /**
     * List pinned messages for a channel, newest pin first.
     * Returns hydrated message rows with pin metadata.
     */
    public static function forChannel(int $channelId): array
    {
        return self::listPins('p.channel_id = ?', [$channelId]);
    }

    /**
     * List pinned messages for a conversation, newest pin first.
     */
    public static function forConversation(int $conversationId): array
    {
        return self::listPins('p.conversation_id = ?', [$conversationId]);
    }

    private static function listPins(string $where, array $params): array
    {
        $sql = "
            SELECT p.id AS pin_id, p.message_id, p.pinned_by, p.created_at AS pinned_at,
                   m.body, m.user_id, m.channel_id, m.conversation_id,
                   m.edited_at, m.deleted_at, m.created_at AS message_created_at,
                   u.display_name, u.avatar_color, u.title AS user_title,
                   pu.display_name AS pinner_name
            FROM pinned_messages p
            JOIN messages m ON m.id = p.message_id
            JOIN users u ON u.id = m.user_id
            JOIN users pu ON pu.id = p.pinned_by
            WHERE $where
            ORDER BY p.created_at DESC, p.id DESC
        ";

        $stmt = Database::connection()->prepare($sql);
        $stmt->execute($params);
        $rows = $stmt->fetchAll(\PDO::FETCH_ASSOC);

        return array_map(fn(array $row) => [
            'pin_id' => (int) $row['pin_id'],
            'pinned_by' => (int) $row['pinned_by'],
            'pinner_name' => $row['pinner_name'],
            'pinned_at' => $row['pinned_at'],
            'message' => [
                'id' => (int) $row['message_id'],
                'body' => $row['deleted_at'] ? null : $row['body'],
                'user_id' => (int) $row['user_id'],
                'channel_id' => $row['channel_id'] ? (int) $row['channel_id'] : null,
                'conversation_id' => $row['conversation_id'] ? (int) $row['conversation_id'] : null,
                'edited_at' => $row['edited_at'],
                'deleted_at' => $row['deleted_at'],
                'created_at' => $row['message_created_at'],
                'user' => [
                    'id' => (int) $row['user_id'],
                    'display_name' => $row['display_name'],
                    'avatar_color' => $row['avatar_color'],
                    'title' => $row['user_title'],
                ],
            ],
        ], $rows);
    }
}

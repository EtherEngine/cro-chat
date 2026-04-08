<?php

declare(strict_types=1);

namespace App\Repositories;

use App\Support\Database;

final class SavedMessageRepository
{
    /**
     * Save a message for a user. Returns true if newly saved, false if already saved.
     */
    public static function save(int $userId, int $messageId): bool
    {
        try {
            Database::connection()->prepare(
                'INSERT INTO saved_messages (user_id, message_id) VALUES (?, ?)'
            )->execute([$userId, $messageId]);
            return true;
        } catch (\PDOException $e) {
            if ((int) $e->errorInfo[1] === 1062) {
                return false;
            }
            throw $e;
        }
    }

    /**
     * Remove a saved message. Returns true if removed.
     */
    public static function unsave(int $userId, int $messageId): bool
    {
        $stmt = Database::connection()->prepare(
            'DELETE FROM saved_messages WHERE user_id = ? AND message_id = ?'
        );
        $stmt->execute([$userId, $messageId]);
        return $stmt->rowCount() > 0;
    }

    /**
     * Check if a message is saved by a user.
     */
    public static function isSaved(int $userId, int $messageId): bool
    {
        $stmt = Database::connection()->prepare(
            'SELECT 1 FROM saved_messages WHERE user_id = ? AND message_id = ? LIMIT 1'
        );
        $stmt->execute([$userId, $messageId]);
        return (bool) $stmt->fetchColumn();
    }

    /**
     * List saved messages for a user, newest save first.
     */
    public static function forUser(int $userId): array
    {
        $sql = "
            SELECT s.id AS saved_id, s.message_id, s.created_at AS saved_at,
                   m.body, m.user_id, m.channel_id, m.conversation_id,
                   m.edited_at, m.deleted_at, m.created_at AS message_created_at,
                   u.display_name, u.avatar_color, u.title AS user_title,
                   ch.name AS channel_name,
                   c.is_group AS conv_is_group
            FROM saved_messages s
            JOIN messages m ON m.id = s.message_id
            JOIN users u ON u.id = m.user_id
            LEFT JOIN channels ch ON ch.id = m.channel_id
            LEFT JOIN conversations c ON c.id = m.conversation_id
            WHERE s.user_id = ?
            ORDER BY s.created_at DESC, s.id DESC
        ";

        $stmt = Database::connection()->prepare($sql);
        $stmt->execute([$userId]);
        $rows = $stmt->fetchAll(\PDO::FETCH_ASSOC);

        return array_map(fn(array $row) => [
            'saved_id' => (int) $row['saved_id'],
            'saved_at' => $row['saved_at'],
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
                'context' => $row['channel_name']
                    ? $row['channel_name']
                    : ($row['conversation_id'] ? 'DM' : null),
            ],
        ], $rows);
    }
}

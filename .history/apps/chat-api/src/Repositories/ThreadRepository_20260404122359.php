<?php

declare(strict_types=1);

namespace App\Repositories;

use App\Support\Database;

final class ThreadRepository
{
    public static function find(int $id): ?array
    {
        $stmt = Database::connection()->prepare(
            'SELECT t.id, t.root_message_id, t.channel_id, t.conversation_id,
                    t.reply_count, t.last_reply_at, t.created_by, t.created_at
             FROM threads t
             WHERE t.id = ?'
        );
        $stmt->execute([$id]);
        $row = $stmt->fetch();
        return $row ? self::hydrate($row) : null;
    }

    public static function findByRootMessage(int $messageId): ?array
    {
        $stmt = Database::connection()->prepare(
            'SELECT t.id, t.root_message_id, t.channel_id, t.conversation_id,
                    t.reply_count, t.last_reply_at, t.created_by, t.created_at
             FROM threads t
             WHERE t.root_message_id = ?'
        );
        $stmt->execute([$messageId]);
        $row = $stmt->fetch();
        return $row ? self::hydrate($row) : null;
    }

    public static function create(
        int $rootMessageId,
        ?int $channelId,
        ?int $conversationId,
        int $createdBy
    ): array {
        $db = Database::connection();
        $db->prepare(
            'INSERT INTO threads (root_message_id, channel_id, conversation_id, created_by)
             VALUES (?, ?, ?, ?)'
        )->execute([$rootMessageId, $channelId, $conversationId, $createdBy]);

        return self::find((int) $db->lastInsertId());
    }

    public static function incrementReplyCount(int $threadId): void
    {
        Database::connection()->prepare(
            'UPDATE threads SET reply_count = reply_count + 1, last_reply_at = NOW() WHERE id = ?'
        )->execute([$threadId]);
    }

    private static function hydrate(array $row): array
    {
        return [
            'id' => (int) $row['id'],
            'root_message_id' => (int) $row['root_message_id'],
            'channel_id' => $row['channel_id'] ? (int) $row['channel_id'] : null,
            'conversation_id' => $row['conversation_id'] ? (int) $row['conversation_id'] : null,
            'reply_count' => (int) $row['reply_count'],
            'last_reply_at' => $row['last_reply_at'],
            'created_by' => (int) $row['created_by'],
            'created_at' => $row['created_at'],
        ];
    }
}

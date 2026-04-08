<?php

namespace App\Repositories;

use App\Support\Database;

final class ReadReceiptRepository
{
    public static function markChannelRead(int $userId, int $channelId, int $messageId): void
    {
        $sql = '
            INSERT INTO read_receipts (user_id, channel_id, last_read_message_id)
            VALUES (?, ?, ?)
            ON DUPLICATE KEY UPDATE last_read_message_id = GREATEST(last_read_message_id, VALUES(last_read_message_id)),
                                    read_at = NOW()
        ';
        $stmt = Database::connection()->prepare($sql);
        $stmt->execute([$userId, $channelId, $messageId]);
    }

    public static function markConversationRead(int $userId, int $conversationId, int $messageId): void
    {
        $sql = '
            INSERT INTO read_receipts (user_id, conversation_id, last_read_message_id)
            VALUES (?, ?, ?)
            ON DUPLICATE KEY UPDATE last_read_message_id = GREATEST(last_read_message_id, VALUES(last_read_message_id)),
                                    read_at = NOW()
        ';
        $stmt = Database::connection()->prepare($sql);
        $stmt->execute([$userId, $conversationId, $messageId]);
    }

    public static function markThreadRead(int $userId, int $threadId, int $messageId): void
    {
        $sql = '
            INSERT INTO read_receipts (user_id, thread_id, last_read_message_id)
            VALUES (?, ?, ?)
            ON DUPLICATE KEY UPDATE last_read_message_id = GREATEST(last_read_message_id, VALUES(last_read_message_id)),
                                    read_at = NOW()
        ';
        $stmt = Database::connection()->prepare($sql);
        $stmt->execute([$userId, $threadId, $messageId]);
    }

    public static function unreadCounts(int $userId): array
    {
        $channels = [];
        $conversations = [];

        // Unread per channel the user is a member of
        $sql = '
            SELECT cm.channel_id,
                   COUNT(m.id) AS unread_count
            FROM channel_members cm
            JOIN messages m ON m.channel_id = cm.channel_id AND m.deleted_at IS NULL AND m.thread_id IS NULL
            LEFT JOIN read_receipts rr ON rr.user_id = cm.user_id AND rr.channel_id = cm.channel_id
            WHERE cm.user_id = ?
              AND m.id > COALESCE(rr.last_read_message_id, 0)
              AND m.user_id != ?
            GROUP BY cm.channel_id
        ';
        $stmt = Database::connection()->prepare($sql);
        $stmt->execute([$userId, $userId]);
        foreach ($stmt->fetchAll() as $row) {
            $channels[(int) $row['channel_id']] = (int) $row['unread_count'];
        }

        // Unread per conversation
        $sql = '
            SELECT cvm.conversation_id,
                   COUNT(m.id) AS unread_count
            FROM conversation_members cvm
            JOIN messages m ON m.conversation_id = cvm.conversation_id AND m.deleted_at IS NULL AND m.thread_id IS NULL
            LEFT JOIN read_receipts rr ON rr.user_id = cvm.user_id AND rr.conversation_id = cvm.conversation_id
            WHERE cvm.user_id = ?
              AND m.id > COALESCE(rr.last_read_message_id, 0)
              AND m.user_id != ?
            GROUP BY cvm.conversation_id
        ';
        $stmt = Database::connection()->prepare($sql);
        $stmt->execute([$userId, $userId]);
        foreach ($stmt->fetchAll() as $row) {
            $conversations[(int) $row['conversation_id']] = (int) $row['unread_count'];
        }

        // Unread per thread the user has participated in
        $threads = [];
        $sql = '
            SELECT t.id AS thread_id,
                   COUNT(m.id) AS unread_count
            FROM threads t
            JOIN messages m ON m.thread_id = t.id AND m.deleted_at IS NULL
            LEFT JOIN read_receipts rr ON rr.user_id = ? AND rr.thread_id = t.id
            WHERE t.id IN (
                SELECT DISTINCT tm.thread_id FROM messages tm WHERE tm.user_id = ? AND tm.thread_id IS NOT NULL
                UNION
                SELECT DISTINCT t2.id FROM threads t2 WHERE t2.root_message_id IN (
                    SELECT rm.id FROM messages rm WHERE rm.user_id = ?
                )
            )
              AND m.id > COALESCE(rr.last_read_message_id, 0)
              AND m.user_id != ?
            GROUP BY t.id
        ';
        $stmt = Database::connection()->prepare($sql);
        $stmt->execute([$userId, $userId, $userId, $userId]);
        foreach ($stmt->fetchAll() as $row) {
            $threads[(int) $row['thread_id']] = (int) $row['unread_count'];
        }

        return compact('channels', 'conversations', 'threads');
    }
}

<?php

declare(strict_types=1);

namespace App\Repositories;

use App\Support\Database;

final class NotificationRepository
{
    private const PAGE_SIZE = 30;

    /**
     * Insert a notification. Returns the created row.
     */
    public static function create(
        int $userId,
        string $type,
        int $actorId,
        ?int $messageId = null,
        ?int $channelId = null,
        ?int $conversationId = null,
        ?int $threadId = null,
        ?array $data = null,
        ?int $spaceId = null
    ): array {
        // Resolve space_id from channel/conversation if not provided
        if ($spaceId === null) {
            $spaceId = \App\Support\SpacePolicy::resolveSpaceId($channelId, $conversationId);
        }

        $db = Database::connection();
        $stmt = $db->prepare(
            'INSERT INTO notifications (user_id, space_id, type, actor_id, message_id, channel_id, conversation_id, thread_id, data)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)'
        );
        $stmt->execute([
            $userId,
            $spaceId,
            $type,
            $actorId,
            $messageId,
            $channelId,
            $conversationId,
            $threadId,
            $data !== null ? json_encode($data, JSON_UNESCAPED_UNICODE) : null,
        ]);

        return self::find((int) $db->lastInsertId());
    }

    /**
     * Find a single notification by ID.
     */
    public static function find(int $id): ?array
    {
        $stmt = Database::connection()->prepare(
            self::baseSelect() . ' WHERE n.id = ?'
        );
        $stmt->execute([$id]);
        $row = $stmt->fetch();
        return $row ? self::hydrate($row) : null;
    }

    /**
     * Cursor-paginated list of notifications for a user (newest first).
     * Returns { notifications: [], next_cursor: ?int, has_more: bool }
     */
    public static function forUser(int $userId, ?int $before = null, int $limit = self::PAGE_SIZE, ?int $spaceId = null): array
    {
        $sql = self::baseSelect() . ' WHERE n.user_id = ?';
        $params = [$userId];

        if ($spaceId !== null) {
            $sql .= ' AND n.space_id = ?';
            $params[] = $spaceId;
        }

        if ($before !== null) {
            $sql .= ' AND n.id < ?';
            $params[] = $before;
        }

        $fetchLimit = min($limit, 100) + 1;
        $sql .= " ORDER BY n.id DESC LIMIT $fetchLimit";

        $stmt = Database::connection()->prepare($sql);
        $stmt->execute($params);
        $rows = $stmt->fetchAll();

        $hasMore = count($rows) > min($limit, 100);
        if ($hasMore) {
            array_pop($rows);
        }

        $notifications = array_map([self::class, 'hydrate'], $rows);

        $nextCursor = ($hasMore && count($notifications) > 0)
            ? $notifications[count($notifications) - 1]['id']
            : null;

        return [
            'notifications' => $notifications,
            'next_cursor' => $nextCursor,
            'has_more' => $hasMore,
        ];
    }

    /**
     * Count unread notifications for a user.
     */
    public static function unreadCount(int $userId, ?int $spaceId = null): int
    {
        $sql = 'SELECT COUNT(*) FROM notifications WHERE user_id = ? AND read_at IS NULL';
        $params = [$userId];

        if ($spaceId !== null) {
            $sql .= ' AND space_id = ?';
            $params[] = $spaceId;
        }

        $stmt = Database::connection()->prepare($sql);
        $stmt->execute($params);
        return (int) $stmt->fetchColumn();
    }

    /**
     * Mark a single notification as read.
     */
    public static function markRead(int $id, int $userId): bool
    {
        $stmt = Database::connection()->prepare(
            'UPDATE notifications SET read_at = NOW() WHERE id = ? AND user_id = ? AND read_at IS NULL'
        );
        $stmt->execute([$id, $userId]);
        return $stmt->rowCount() > 0;
    }

    /**
     * Mark all unread notifications as read for a user.
     * Returns the number of notifications marked.
     */
    public static function markAllRead(int $userId, ?int $spaceId = null): int
    {
        $sql = 'UPDATE notifications SET read_at = NOW() WHERE user_id = ? AND read_at IS NULL';
        $params = [$userId];

        if ($spaceId !== null) {
            $sql .= ' AND space_id = ?';
            $params[] = $spaceId;
        }

        $stmt = Database::connection()->prepare($sql);
        $stmt->execute($params);
        return $stmt->rowCount();
    }

    // ── Private ───────────────────────────────

    private static function baseSelect(): string
    {
        return '
            SELECT n.id, n.user_id, n.space_id, n.type, n.actor_id, n.message_id,
                   n.channel_id, n.conversation_id, n.thread_id,
                   n.data, n.read_at, n.created_at,
                   a.display_name AS actor_display_name,
                   a.avatar_color AS actor_avatar_color
            FROM notifications n
            JOIN users a ON a.id = n.actor_id
        ';
    }

    private static function hydrate(array $row): array
    {
        return [
            'id' => (int) $row['id'],
            'user_id' => (int) $row['user_id'],
            'space_id' => (int) $row['space_id'],
            'type' => $row['type'],
            'actor' => [
                'id' => (int) $row['actor_id'],
                'display_name' => $row['actor_display_name'],
                'avatar_color' => $row['actor_avatar_color'],
            ],
            'message_id' => $row['message_id'] ? (int) $row['message_id'] : null,
            'channel_id' => $row['channel_id'] ? (int) $row['channel_id'] : null,
            'conversation_id' => $row['conversation_id'] ? (int) $row['conversation_id'] : null,
            'thread_id' => $row['thread_id'] ? (int) $row['thread_id'] : null,
            'data' => $row['data'] ? json_decode($row['data'], true) : null,
            'read_at' => $row['read_at'],
            'created_at' => $row['created_at'],
        ];
    }
}

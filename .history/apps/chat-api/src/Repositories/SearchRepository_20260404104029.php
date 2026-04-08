<?php

namespace App\Repositories;

use App\Support\Database;

final class SearchRepository
{
    /**
     * Search channels by name within spaces the user belongs to.
     * Respects private-channel visibility.
     */
    public static function channels(int $userId, string $query, int $limit = 20): array
    {
        $sql = '
            SELECT c.id, c.space_id, c.name, c.description, c.color, c.is_private,
                   (SELECT COUNT(*) FROM channel_members WHERE channel_id = c.id) AS member_count
            FROM channels c
            JOIN space_members sm ON sm.space_id = c.space_id AND sm.user_id = ?
            LEFT JOIN channel_members cm ON cm.channel_id = c.id AND cm.user_id = ?
            WHERE c.name LIKE ?
              AND (c.is_private = 0 OR cm.user_id IS NOT NULL)
            ORDER BY c.name
            LIMIT ?
        ';
        $stmt = Database::connection()->prepare($sql);
        $like = '%' . self::escapeLike($query) . '%';
        $stmt->execute([$userId, $userId, $like, $limit]);
        return $stmt->fetchAll();
    }

    /**
     * Search users who share at least one space with the caller.
     */
    public static function users(int $userId, string $query, int $limit = 20): array
    {
        $sql = '
            SELECT DISTINCT u.id, u.email, u.display_name, u.title, u.avatar_color, u.status, u.last_seen_at
            FROM users u
            JOIN space_members sm ON sm.user_id = u.id
            WHERE sm.space_id IN (SELECT space_id FROM space_members WHERE user_id = ?)
              AND (u.display_name LIKE ? OR u.email LIKE ?)
            ORDER BY u.display_name
            LIMIT ?
        ';
        $stmt = Database::connection()->prepare($sql);
        $like = '%' . self::escapeLike($query) . '%';
        $stmt->execute([$userId, $like, $like, $limit]);
        return $stmt->fetchAll();
    }

    /**
     * Search messages the user has access to (channel-messages in visible channels,
     * conversation-messages in conversations the user belongs to).
     * Optionally filter by channel_id, user_id, date range.
     */
    public static function messages(
        int    $userId,
        string $query,
        ?int   $channelId = null,
        ?int   $authorId  = null,
        ?string $after    = null,
        ?string $before   = null,
        int    $limit     = 30
    ): array {
        $params = [];

        // Build the access CTE: all channel_ids + conversation_ids the user can see
        $sql = '
            SELECT m.id, m.body, m.user_id, m.channel_id, m.conversation_id,
                   m.created_at,
                   u.display_name AS author_name, u.avatar_color AS author_color
            FROM messages m
            JOIN users u ON u.id = m.user_id
            LEFT JOIN channels ch ON ch.id = m.channel_id
            WHERE m.deleted_at IS NULL
              AND m.body LIKE ?
              AND (
                    (m.channel_id IS NOT NULL
                     AND (
                           (ch.is_private = 0 AND EXISTS (SELECT 1 FROM space_members WHERE space_id = ch.space_id AND user_id = ?))
                           OR EXISTS (SELECT 1 FROM channel_members WHERE channel_id = m.channel_id AND user_id = ?)
                         )
                    )
                    OR
                    (m.conversation_id IS NOT NULL
                     AND EXISTS (SELECT 1 FROM conversation_members WHERE conversation_id = m.conversation_id AND user_id = ?)
                    )
                  )
        ';
        $like = '%' . self::escapeLike($query) . '%';
        $params[] = $like;
        $params[] = $userId;
        $params[] = $userId;
        $params[] = $userId;

        if ($channelId !== null) {
            $sql .= ' AND m.channel_id = ?';
            $params[] = $channelId;
        }
        if ($authorId !== null) {
            $sql .= ' AND m.user_id = ?';
            $params[] = $authorId;
        }
        if ($after !== null) {
            $sql .= ' AND m.created_at >= ?';
            $params[] = $after;
        }
        if ($before !== null) {
            $sql .= ' AND m.created_at <= ?';
            $params[] = $before;
        }

        $sql .= ' ORDER BY m.created_at DESC LIMIT ?';
        $params[] = $limit;

        $stmt = Database::connection()->prepare($sql);
        $stmt->execute($params);
        $rows = $stmt->fetchAll();

        // Enrich with context: channel name or conversation participant names
        foreach ($rows as &$row) {
            if ($row['channel_id']) {
                $row['context'] = self::channelName((int) $row['channel_id']);
            } else {
                $row['context'] = self::conversationLabel((int) $row['conversation_id'], $userId);
            }
        }

        return $rows;
    }

    // ── helpers ─────────────────────────────────

    private static function escapeLike(string $value): string
    {
        return str_replace(['%', '_', '\\'], ['\\%', '\\_', '\\\\'], $value);
    }

    private static function channelName(int $channelId): string
    {
        $stmt = Database::connection()->prepare('SELECT name FROM channels WHERE id = ?');
        $stmt->execute([$channelId]);
        $row = $stmt->fetch();
        return $row ? '#' . $row['name'] : '';
    }

    private static function conversationLabel(int $conversationId, int $excludeUserId): string
    {
        $stmt = Database::connection()->prepare('
            SELECT u.display_name FROM users u
            JOIN conversation_members cm ON cm.user_id = u.id
            WHERE cm.conversation_id = ? AND u.id != ?
            ORDER BY u.display_name LIMIT 3
        ');
        $stmt->execute([$conversationId, $excludeUserId]);
        $names = $stmt->fetchAll(\PDO::FETCH_COLUMN);
        return implode(', ', $names);
    }
}

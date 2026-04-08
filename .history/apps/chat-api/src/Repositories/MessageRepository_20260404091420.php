<?php

namespace App\Repositories;

use App\Support\Database;

final class MessageRepository
{
    private static function hydrate(array $row): array
    {
        return [
            'id' => (int) $row['id'],
            'body' => $row['body'],
            'user_id' => (int) $row['user_id'],
            'channel_id' => $row['channel_id'] ? (int) $row['channel_id'] : null,
            'conversation_id' => $row['conversation_id'] ? (int) $row['conversation_id'] : null,
            'edited_at' => $row['edited_at'],
            'deleted_at' => $row['deleted_at'],
            'created_at' => $row['created_at'],
            'user' => [
                'id' => (int) $row['user_id'],
                'display_name' => $row['display_name'],
                'avatar_color' => $row['avatar_color'],
                'title' => $row['user_title'],
            ],
        ];
    }

    private static function baseQuery(): string
    {
        return '
            SELECT m.id, m.body, m.user_id, m.channel_id, m.conversation_id,
                   m.edited_at, m.deleted_at, m.created_at,
                   u.display_name, u.avatar_color, u.title AS user_title
            FROM messages m
            JOIN users u ON u.id = m.user_id
        ';
    }

    public static function find(int $id): ?array
    {
        $stmt = Database::connection()->prepare(self::baseQuery() . ' WHERE m.id = ?');
        $stmt->execute([$id]);
        $row = $stmt->fetch();
        return $row ? self::hydrate($row) : null;
    }

    public static function forChannel(int $channelId, int $limit = 100, ?int $before = null): array
    {
        $sql = self::baseQuery() . ' WHERE m.channel_id = ? AND m.deleted_at IS NULL';
        $params = [$channelId];
        if ($before) {
            $sql .= ' AND m.id < ?';
            $params[] = $before;
        }
        $sql .= " ORDER BY m.created_at ASC LIMIT $limit";
        $stmt = Database::connection()->prepare($sql);
        $stmt->execute($params);
        return array_map([self::class, 'hydrate'], $stmt->fetchAll());
    }

    public static function forConversation(int $conversationId, int $limit = 100, ?int $before = null): array
    {
        $sql = self::baseQuery() . ' WHERE m.conversation_id = ? AND m.deleted_at IS NULL';
        $params = [$conversationId];
        if ($before) {
            $sql .= ' AND m.id < ?';
            $params[] = $before;
        }
        $sql .= " ORDER BY m.created_at ASC LIMIT $limit";
        $stmt = Database::connection()->prepare($sql);
        $stmt->execute($params);
        return array_map([self::class, 'hydrate'], $stmt->fetchAll());
    }

    public static function create(int $userId, string $body, ?int $channelId, ?int $conversationId): array
    {
        $stmt = Database::connection()->prepare(
            'INSERT INTO messages (body, user_id, channel_id, conversation_id) VALUES (?, ?, ?, ?)'
        );
        $stmt->execute([$body, $userId, $channelId, $conversationId]);
        return self::find((int) Database::connection()->lastInsertId());
    }

    public static function update(int $id, string $body): array
    {
        $stmt = Database::connection()->prepare(
            'UPDATE messages SET body = ?, edited_at = NOW() WHERE id = ?'
        );
        $stmt->execute([$body, $id]);
        return self::find($id);
    }

    public static function softDelete(int $id): void
    {
        $stmt = Database::connection()->prepare(
            'UPDATE messages SET deleted_at = NOW() WHERE id = ?'
        );
        $stmt->execute([$id]);
    }

    public static function lastInChannel(int $channelId): ?int
    {
        $stmt = Database::connection()->prepare(
            'SELECT MAX(id) AS last_id FROM messages WHERE channel_id = ? AND deleted_at IS NULL'
        );
        $stmt->execute([$channelId]);
        $row = $stmt->fetch();
        return $row['last_id'] ? (int) $row['last_id'] : null;
    }

    public static function lastInConversation(int $conversationId): ?int
    {
        $stmt = Database::connection()->prepare(
            'SELECT MAX(id) AS last_id FROM messages WHERE conversation_id = ? AND deleted_at IS NULL'
        );
        $stmt->execute([$conversationId]);
        $row = $stmt->fetch();
        return $row['last_id'] ? (int) $row['last_id'] : null;
    }
}


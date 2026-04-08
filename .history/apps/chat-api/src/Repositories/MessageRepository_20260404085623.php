<?php

namespace App\Repositories;

use App\Support\Database;

final class MessageRepository
{
    private static function hydrate(array $row): array
    {
        return [
            'id' => $row['id'],
            'body' => $row['body'],
            'user_id' => $row['user_id'],
            'channel_id' => $row['channel_id'],
            'conversation_id' => $row['conversation_id'],
            'created_at' => $row['created_at'],
            'user' => [
                'id' => $row['user_id'],
                'display_name' => $row['display_name'],
                'avatar_color' => $row['avatar_color'],
                'title' => $row['user_title'],
            ],
        ];
    }

    private static function baseQuery(): string
    {
        return '
            SELECT m.id, m.body, m.user_id, m.channel_id, m.conversation_id, m.created_at,
                   u.display_name, u.avatar_color, u.title AS user_title
            FROM messages m
            JOIN users u ON u.id = m.user_id
        ';
    }

    public static function forChannel(int $channelId): array
    {
        $stmt = Database::connection()->prepare(
            self::baseQuery() . ' WHERE m.channel_id = ? ORDER BY m.created_at ASC'
        );
        $stmt->execute([$channelId]);
        return array_map([self::class, 'hydrate'], $stmt->fetchAll());
    }

    public static function forConversation(int $conversationId): array
    {
        $stmt = Database::connection()->prepare(
            self::baseQuery() . ' WHERE m.conversation_id = ? ORDER BY m.created_at ASC'
        );
        $stmt->execute([$conversationId]);
        return array_map([self::class, 'hydrate'], $stmt->fetchAll());
    }

    public static function create(int $userId, string $body, ?int $channelId, ?int $conversationId): array
    {
        $stmt = Database::connection()->prepare(
            'INSERT INTO messages (body, user_id, channel_id, conversation_id) VALUES (?, ?, ?, ?)'
        );
        $stmt->execute([$body, $userId, $channelId, $conversationId]);

        $id = (int)Database::connection()->lastInsertId();
        $stmt = Database::connection()->prepare(self::baseQuery() . ' WHERE m.id = ?');
        $stmt->execute([$id]);
        return self::hydrate($stmt->fetch());
    }
}


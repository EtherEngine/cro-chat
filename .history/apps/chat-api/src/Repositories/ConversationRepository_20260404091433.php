<?php

namespace App\Repositories;

use App\Support\Database;

final class ConversationRepository
{
    public static function find(int $id): ?array
    {
        $stmt = Database::connection()->prepare('SELECT * FROM conversations WHERE id = ?');
        $stmt->execute([$id]);
        return $stmt->fetch() ?: null;
    }

    public static function forUser(int $userId): array
    {
        $sql = '
            SELECT c.id, c.space_id, c.created_at
            FROM conversations c
            JOIN conversation_members cm ON cm.conversation_id = c.id
            WHERE cm.user_id = ?
        ';
        $stmt = Database::connection()->prepare($sql);
        $stmt->execute([$userId]);
        $conversations = $stmt->fetchAll();

        foreach ($conversations as &$conv) {
            $conv['users'] = self::otherMembers((int) $conv['id'], $userId);
        }

        return $conversations;
    }

    public static function otherMembers(int $conversationId, int $excludeUserId): array
    {
        $sql = '
            SELECT u.id, u.email, u.display_name, u.title, u.avatar_color, u.status, u.last_seen_at
            FROM users u
            JOIN conversation_members cm ON cm.user_id = u.id
            WHERE cm.conversation_id = ? AND u.id != ?
        ';
        $stmt = Database::connection()->prepare($sql);
        $stmt->execute([$conversationId, $excludeUserId]);
        return $stmt->fetchAll();
    }

    public static function isMember(int $conversationId, int $userId): bool
    {
        $stmt = Database::connection()->prepare(
            'SELECT 1 FROM conversation_members WHERE conversation_id = ? AND user_id = ?'
        );
        $stmt->execute([$conversationId, $userId]);
        return (bool) $stmt->fetch();
    }

    public static function findBetween(int $spaceId, array $userIds): ?array
    {
        sort($userIds);
        $count = count($userIds);
        $placeholders = implode(',', array_fill(0, $count, '?'));

        $sql = "
            SELECT c.id FROM conversations c
            WHERE c.space_id = ?
              AND (SELECT COUNT(*) FROM conversation_members WHERE conversation_id = c.id) = ?
              AND NOT EXISTS (
                  SELECT 1 FROM conversation_members cm2
                  WHERE cm2.conversation_id = c.id AND cm2.user_id NOT IN ($placeholders)
              )
            LIMIT 1
        ";
        $params = array_merge([$spaceId, $count], $userIds);
        $stmt = Database::connection()->prepare($sql);
        $stmt->execute($params);
        $row = $stmt->fetch();
        return $row ? self::find((int) $row['id']) : null;
    }

    public static function create(int $spaceId, array $userIds): array
    {
        $stmt = Database::connection()->prepare(
            'INSERT INTO conversations (space_id) VALUES (?)'
        );
        $stmt->execute([$spaceId]);
        $id = (int) Database::connection()->lastInsertId();

        $insert = Database::connection()->prepare(
            'INSERT INTO conversation_members (conversation_id, user_id) VALUES (?, ?)'
        );
        foreach ($userIds as $uid) {
            $insert->execute([$id, $uid]);
        }

        return self::find($id);
    }
}


<?php

namespace App\Repositories;

use App\Support\Database;

final class ConversationRepository
{
    public static function forUser(int $userId): array
    {
        $sql = '
            SELECT c.id, c.created_at
            FROM conversations c
            JOIN conversation_members cm ON cm.conversation_id = c.id
            WHERE cm.user_id = ?
        ';
        $stmt = Database::connection()->prepare($sql);
        $stmt->execute([$userId]);
        $conversations = $stmt->fetchAll();

        foreach ($conversations as &$conv) {
            $memberSql = '
                SELECT u.id, u.email, u.display_name, u.title, u.avatar_color, u.status
                FROM users u
                JOIN conversation_members cm ON cm.user_id = u.id
                WHERE cm.conversation_id = ? AND u.id != ?
            ';
            $memberStmt = Database::connection()->prepare($memberSql);
            $memberStmt->execute([$conv['id'], $userId]);
            $conv['users'] = $memberStmt->fetchAll();
        }

        return $conversations;
    }
}


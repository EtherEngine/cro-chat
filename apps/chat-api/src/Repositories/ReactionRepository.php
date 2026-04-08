<?php

declare(strict_types=1);

namespace App\Repositories;

use App\Support\Database;

final class ReactionRepository
{
    /**
     * Add a reaction. Returns the created row or null if duplicate.
     */
    public static function add(int $messageId, int $userId, string $emoji): ?array
    {
        $db = Database::connection();

        // INSERT IGNORE to silently skip duplicate (unique constraint)
        $stmt = $db->prepare(
            'INSERT IGNORE INTO message_reactions (message_id, user_id, emoji) VALUES (?, ?, ?)'
        );
        $stmt->execute([$messageId, $userId, $emoji]);

        if ($stmt->rowCount() === 0) {
            return null; // already existed
        }

        return self::findById((int) $db->lastInsertId());
    }

    /**
     * Remove a reaction. Returns true if deleted, false if it didn't exist.
     */
    public static function remove(int $messageId, int $userId, string $emoji): bool
    {
        $stmt = Database::connection()->prepare(
            'DELETE FROM message_reactions WHERE message_id = ? AND user_id = ? AND emoji = ?'
        );
        $stmt->execute([$messageId, $userId, $emoji]);
        return $stmt->rowCount() > 0;
    }

    /**
     * Aggregated reactions for a single message.
     * Returns: [ { emoji, count, user_ids: [int...] } ]
     */
    public static function forMessage(int $messageId): array
    {
        $stmt = Database::connection()->prepare(
            'SELECT emoji, user_id FROM message_reactions WHERE message_id = ? ORDER BY emoji, id ASC'
        );
        $stmt->execute([$messageId]);

        $grouped = [];
        foreach ($stmt->fetchAll() as $row) {
            $emoji = $row['emoji'];
            if (!isset($grouped[$emoji])) {
                $grouped[$emoji] = ['emoji' => $emoji, 'count' => 0, 'user_ids' => []];
            }
            $grouped[$emoji]['count']++;
            $grouped[$emoji]['user_ids'][] = (int) $row['user_id'];
        }

        return array_values($grouped);
    }

    /**
     * Batch-load aggregated reactions for multiple messages (avoids N+1).
     * Returns [ messageId => [ { emoji, count, user_ids } ] ]
     */
    public static function forMessages(array $messageIds): array
    {
        if (empty($messageIds)) {
            return [];
        }

        $placeholders = implode(',', array_fill(0, count($messageIds), '?'));
        $stmt = Database::connection()->prepare(
            "SELECT message_id, emoji, user_id
             FROM message_reactions
             WHERE message_id IN ($placeholders)
             ORDER BY message_id, emoji, id ASC"
        );
        $stmt->execute($messageIds);

        $raw = [];
        foreach ($stmt->fetchAll() as $row) {
            $mid = (int) $row['message_id'];
            $emoji = $row['emoji'];
            if (!isset($raw[$mid][$emoji])) {
                $raw[$mid][$emoji] = ['emoji' => $emoji, 'count' => 0, 'user_ids' => []];
            }
            $raw[$mid][$emoji]['count']++;
            $raw[$mid][$emoji]['user_ids'][] = (int) $row['user_id'];
        }

        $map = [];
        foreach ($raw as $mid => $emojis) {
            $map[$mid] = array_values($emojis);
        }
        return $map;
    }

    private static function findById(int $id): ?array
    {
        $stmt = Database::connection()->prepare(
            'SELECT id, message_id, user_id, emoji, created_at FROM message_reactions WHERE id = ?'
        );
        $stmt->execute([$id]);
        $row = $stmt->fetch();
        if (!$row) {
            return null;
        }
        return [
            'id' => (int) $row['id'],
            'message_id' => (int) $row['message_id'],
            'user_id' => (int) $row['user_id'],
            'emoji' => $row['emoji'],
            'created_at' => $row['created_at'],
        ];
    }
}

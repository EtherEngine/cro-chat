<?php

namespace App\Repositories;

use App\Support\Database;

final class ConversationRepository
{
    // ── Helpers ────────────────────────────────

    /**
     * Deterministic hash for a set of participant IDs.
     * Sorted ascending, joined with ':', SHA-256.
     */
    public static function participantHash(array $userIds): string
    {
        $ids = array_map('intval', $userIds);
        sort($ids, SORT_NUMERIC);
        return hash('sha256', implode(':', $ids));
    }

    // ── Reads ─────────────────────────────────

    public static function find(int $id): ?array
    {
        $stmt = Database::connection()->prepare(
            'SELECT id, space_id, is_group, participant_hash, created_at FROM conversations WHERE id = ?'
        );
        $stmt->execute([$id]);
        $row = $stmt->fetch();
        if (!$row) return null;
        $row['is_group'] = (bool) $row['is_group'];
        return $row;
    }

    public static function forUser(int $userId): array
    {
        $sql = '
            SELECT c.id, c.space_id, c.is_group, c.participant_hash, c.created_at
            FROM conversations c
            JOIN conversation_members cm ON cm.conversation_id = c.id
            WHERE cm.user_id = ?
            ORDER BY c.id DESC
        ';
        $stmt = Database::connection()->prepare($sql);
        $stmt->execute([$userId]);
        $conversations = $stmt->fetchAll();

        foreach ($conversations as &$conv) {
            $conv['is_group'] = (bool) $conv['is_group'];
            $conv['users'] = self::members((int) $conv['id']);
        }

        return $conversations;
    }

    public static function members(int $conversationId): array
    {
        $sql = '
            SELECT u.id, u.email, u.display_name, u.title, u.avatar_color, u.status, u.last_seen_at
            FROM users u
            JOIN conversation_members cm ON cm.user_id = u.id
            WHERE cm.conversation_id = ?
            ORDER BY u.display_name
        ';
        $stmt = Database::connection()->prepare($sql);
        $stmt->execute([$conversationId]);
        return $stmt->fetchAll();
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

    public static function memberCount(int $conversationId): int
    {
        $stmt = Database::connection()->prepare(
            'SELECT COUNT(*) AS cnt FROM conversation_members WHERE conversation_id = ?'
        );
        $stmt->execute([$conversationId]);
        return (int) $stmt->fetch()['cnt'];
    }

    /**
     * Find an existing conversation by its participant hash.
     * This is the O(1) lookup — no scanning.
     */
    public static function findByHash(int $spaceId, string $hash): ?array
    {
        $stmt = Database::connection()->prepare(
            'SELECT id FROM conversations WHERE space_id = ? AND participant_hash = ?'
        );
        $stmt->execute([$spaceId, $hash]);
        $row = $stmt->fetch();
        return $row ? self::find((int) $row['id']) : null;
    }

    // ── Writes ────────────────────────────────

    /**
     * Get-or-create pattern.
     * For 1:1 (non-group): uses participant_hash as unique key → DB enforces dedup.
     * For group: always creates a new conversation (hash still set for integrity).
     */
    public static function getOrCreate(int $spaceId, array $userIds, bool $isGroup = false): array
    {
        $db = Database::connection();
        $hash = self::participantHash($userIds);

        if (!$isGroup) {
            $existing = self::findByHash($spaceId, $hash);
            if ($existing) {
                $existing['users'] = self::members((int) $existing['id']);
                return $existing;
            }
        }

        $db->beginTransaction();
        try {
            $stmt = $db->prepare(
                'INSERT INTO conversations (space_id, is_group, participant_hash) VALUES (?, ?, ?)'
            );
            $stmt->execute([$spaceId, $isGroup ? 1 : 0, $hash]);
            $id = (int) $db->lastInsertId();

            $insert = $db->prepare(
                'INSERT INTO conversation_members (conversation_id, user_id) VALUES (?, ?)'
            );
            foreach ($userIds as $uid) {
                $insert->execute([$id, (int) $uid]);
            }

            $db->commit();
        } catch (\PDOException $e) {
            $db->rollBack();
            // Duplicate key → race condition, another request won
            if ((int) $e->errorInfo[1] === 1062) {
                $existing = self::findByHash($spaceId, $hash);
                if ($existing) {
                    $existing['users'] = self::members((int) $existing['id']);
                    return $existing;
                }
            }
            throw $e;
        }

        $conv = self::find($id);
        $conv['users'] = self::members($id);
        return $conv;
    }

    /**
     * @deprecated Use getOrCreate() instead.
     */
    public static function create(int $spaceId, array $userIds): array
    {
        return self::getOrCreate($spaceId, $userIds);
    }
}


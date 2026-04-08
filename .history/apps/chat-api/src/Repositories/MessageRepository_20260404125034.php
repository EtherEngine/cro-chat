<?php

namespace App\Repositories;

use App\Support\Database;

final class MessageRepository
{
    private const PAGE_SIZE = 50;

    // ── Hydration ─────────────────────────────

    /**
     * Hydrate a single message row. Attachments are not loaded here
     * to avoid N+1 — use hydrateMany() for batch loading.
     */
    private static function hydrate(array $row, array $attachmentMap = [], array $reactionMap = [], array $mentionMap = []): array
    {
        $id = (int) $row['id'];
        $msg = [
            'id' => $id,
            'body' => $row['body'],
            'user_id' => (int) $row['user_id'],
            'channel_id' => $row['channel_id'] ? (int) $row['channel_id'] : null,
            'conversation_id' => $row['conversation_id'] ? (int) $row['conversation_id'] : null,
            'reply_to_id' => $row['reply_to_id'] ? (int) $row['reply_to_id'] : null,
            'thread_id' => $row['thread_id'] ? (int) $row['thread_id'] : null,
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

        // Thread summary for root messages (present when LEFT JOIN threads is used)
        if (isset($row['thread_summary_id']) && $row['thread_summary_id'] !== null) {
            $msg['thread'] = [
                'id' => (int) $row['thread_summary_id'],
                'reply_count' => (int) $row['thread_reply_count'],
                'last_reply_at' => $row['thread_last_reply_at'],
            ];
        }

        // Omit body for soft-deleted messages
        if ($msg['deleted_at'] !== null) {
            $msg['body'] = null;
        }

        $msg['attachments'] = $attachmentMap[$id] ?? [];
        $msg['reactions'] = $reactionMap[$id] ?? [];
        $msg['mentions'] = $mentionMap[$id] ?? [];

        return $msg;
    }

    /**
     * Batch-load attachments for a set of message IDs (single query).
     * Returns [ messageId => [ attachment, ... ] ]
     */
    private static function attachmentMapFor(array $messageIds): array
    {
        if (empty($messageIds)) {
            return [];
        }

        $placeholders = implode(',', array_fill(0, count($messageIds), '?'));
        $stmt = Database::connection()->prepare(
            "SELECT id, message_id, original_name, mime_type, file_size, created_at
             FROM attachments WHERE message_id IN ($placeholders) ORDER BY id ASC"
        );
        $stmt->execute($messageIds);

        $map = [];
        foreach ($stmt->fetchAll() as $row) {
            $mid = (int) $row['message_id'];
            $map[$mid][] = [
                'id' => (int) $row['id'],
                'message_id' => $mid,
                'original_name' => $row['original_name'],
                'mime_type' => $row['mime_type'],
                'file_size' => (int) $row['file_size'],
                'created_at' => $row['created_at'],
            ];
        }
        return $map;
    }

    /**
     * Hydrate many rows with batch-loaded attachments (1 query instead of N).
     */
    private static function hydrateMany(array $rows): array
    {
        $ids = array_map(fn($r) => (int) $r['id'], $rows);
        $attachmentMap = self::attachmentMapFor($ids);
        $reactionMap = ReactionRepository::forMessages($ids);
        return array_map(fn($r) => self::hydrate($r, $attachmentMap, $reactionMap), $rows);
    }

    private static function baseSelect(): string
    {
        return '
            SELECT m.id, m.body, m.user_id, m.channel_id, m.conversation_id,
                   m.reply_to_id, m.thread_id, m.edited_at, m.deleted_at, m.created_at,
                   u.display_name, u.avatar_color, u.title AS user_title,
                   t.id AS thread_summary_id, t.reply_count AS thread_reply_count,
                   t.last_reply_at AS thread_last_reply_at
            FROM messages m
            JOIN users u ON u.id = m.user_id
            LEFT JOIN threads t ON t.root_message_id = m.id
        ';
    }

    // ── Single lookup ─────────────────────────

    public static function find(int $id): ?array
    {
        $stmt = Database::connection()->prepare(self::baseSelect() . ' WHERE m.id = ?');
        $stmt->execute([$id]);
        $row = $stmt->fetch();
        if (!$row)
            return null;
        $mid = (int) $row['id'];
        $attachmentMap = self::attachmentMapFor([$mid]);
        $reactionMap = ReactionRepository::forMessages([$mid]);
        return self::hydrate($row, $attachmentMap, $reactionMap);
    }

    /**
     * Lightweight find — no attachment loading. For authorization checks only.
     */
    public static function findBasic(int $id): ?array
    {
        $stmt = Database::connection()->prepare(self::baseSelect() . ' WHERE m.id = ?');
        $stmt->execute([$id]);
        $row = $stmt->fetch();
        return $row ? self::hydrate($row) : null;
    }

    // ── Cursor-paginated lists ────────────────

    /**
     * Returns { messages: [], next_cursor: ?int, has_more: bool }
     * Cursor = message id.  ?before=<id> loads older, ?after=<id> loads newer.
     */
    public static function forChannel(
        int $channelId,
        ?int $before = null,
        ?int $after = null,
        int $limit = self::PAGE_SIZE
    ): array {
        return self::cursorQuery(
            'm.channel_id = ? AND m.thread_id IS NULL',
            [$channelId],
            $before,
            $after,
            $limit
        );
    }

    public static function forConversation(
        int $conversationId,
        ?int $before = null,
        ?int $after = null,
        int $limit = self::PAGE_SIZE
    ): array {
        return self::cursorQuery(
            'm.conversation_id = ? AND m.thread_id IS NULL',
            [$conversationId],
            $before,
            $after,
            $limit
        );
    }

    public static function forThread(
        int $threadId,
        ?int $before = null,
        ?int $after = null,
        int $limit = self::PAGE_SIZE
    ): array {
        return self::cursorQuery(
            'm.thread_id = ?',
            [$threadId],
            $before,
            $after,
            $limit
        );
    }

    /**
     * Shared cursor pagination logic.
     * Fetches limit+1 rows to detect `has_more` without a COUNT query.
     */
    private static function cursorQuery(
        string $where,
        array $params,
        ?int $before,
        ?int $after,
        int $limit
    ): array {
        $sql = self::baseSelect() . " WHERE $where AND m.deleted_at IS NULL";

        if ($before !== null) {
            $sql .= ' AND m.id < ?';
            $params[] = $before;
        } elseif ($after !== null) {
            $sql .= ' AND m.id > ?';
            $params[] = $after;
        }

        // Fetch one extra to know if there are more pages
        $fetchLimit = min($limit, 200) + 1;

        if ($after !== null) {
            // Loading newer → ASC, then we keep natural order
            $sql .= " ORDER BY m.id ASC LIMIT $fetchLimit";
        } else {
            // Loading older or initial → DESC to get the latest N, then reverse
            $sql .= " ORDER BY m.id DESC LIMIT $fetchLimit";
        }

        $stmt = Database::connection()->prepare($sql);
        $stmt->execute($params);
        $rows = $stmt->fetchAll();

        $hasMore = count($rows) > min($limit, 200);
        if ($hasMore) {
            array_pop($rows);
        }

        // For "before" / initial load we fetched DESC → reverse to chronological
        if ($after === null) {
            $rows = array_reverse($rows);
        }

        $messages = self::hydrateMany($rows);

        $nextCursor = null;
        if ($hasMore && count($messages) > 0) {
            // For before/initial: the oldest id in result is the next cursor for older pages
            // For after: the newest id in result is the next cursor for newer pages
            $nextCursor = ($after !== null)
                ? $messages[count($messages) - 1]['id']
                : $messages[0]['id'];
        }

        return [
            'messages' => $messages,
            'next_cursor' => $nextCursor,
            'has_more' => $hasMore,
        ];
    }

    // ── Idempotent create ─────────────────────

    public static function create(
        int $userId,
        string $body,
        ?int $channelId,
        ?int $conversationId,
        ?int $replyToId = null,
        ?string $idempotencyKey = null,
        ?int $threadId = null
    ): array {
        $db = Database::connection();

        // Idempotency: return existing message if key matches
        if ($idempotencyKey !== null) {
            $stmt = $db->prepare('SELECT id FROM messages WHERE user_id = ? AND idempotency_key = ?');
            $stmt->execute([$userId, $idempotencyKey]);
            $existing = $stmt->fetch();
            if ($existing) {
                return self::find((int) $existing['id']);
            }
        }

        $stmt = $db->prepare(
            'INSERT INTO messages (body, user_id, channel_id, conversation_id, reply_to_id, thread_id, idempotency_key)
             VALUES (?, ?, ?, ?, ?, ?, ?)'
        );
        $stmt->execute([$body, $userId, $channelId, $conversationId, $replyToId, $threadId, $idempotencyKey]);
        return self::find((int) $db->lastInsertId());
    }

    // ── Update with edit history ──────────────

    public static function update(int $id, string $newBody, int $editedBy): array
    {
        return Database::transaction(function () use ($id, $newBody, $editedBy) {
            $db = Database::connection();

            // Archive the previous body
            $old = $db->prepare('SELECT body FROM messages WHERE id = ?');
            $old->execute([$id]);
            $prev = $old->fetch();
            if ($prev) {
                $db->prepare(
                    'INSERT INTO message_edits (message_id, body, edited_by) VALUES (?, ?, ?)'
                )->execute([$id, $prev['body'], $editedBy]);
            }

            $db->prepare(
                'UPDATE messages SET body = ?, edited_at = NOW() WHERE id = ?'
            )->execute([$newBody, $id]);

            return self::find($id);
        });
    }

    // ── Soft delete ───────────────────────────

    public static function softDelete(int $id): void
    {
        Database::connection()->prepare(
            'UPDATE messages SET deleted_at = NOW() WHERE id = ? AND deleted_at IS NULL'
        )->execute([$id]);
    }

    // ── Edit history ──────────────────────────

    public static function editHistory(int $messageId): array
    {
        $stmt = Database::connection()->prepare(
            'SELECT me.id, me.body, me.edited_by, me.created_at,
                    u.display_name, u.avatar_color
             FROM message_edits me
             JOIN users u ON u.id = me.edited_by
             WHERE me.message_id = ?
             ORDER BY me.created_at ASC'
        );
        $stmt->execute([$messageId]);
        return $stmt->fetchAll();
    }

    // ── Attachments ───────────────────────────

    public static function addAttachment(
        int $messageId,
        string $originalName,
        string $storageName,
        string $mimeType,
        int $fileSize
    ): array {
        $db = Database::connection();
        $db->prepare(
            'INSERT INTO attachments (message_id, original_name, storage_name, mime_type, file_size)
             VALUES (?, ?, ?, ?, ?)'
        )->execute([$messageId, $originalName, $storageName, $mimeType, $fileSize]);

        return [
            'id' => (int) $db->lastInsertId(),
            'message_id' => $messageId,
            'original_name' => $originalName,
            'mime_type' => $mimeType,
            'file_size' => $fileSize,
        ];
    }

    public static function findAttachment(int $id): ?array
    {
        $stmt = Database::connection()->prepare(
            'SELECT id, message_id, original_name, storage_name, mime_type, file_size, created_at
             FROM attachments WHERE id = ?'
        );
        $stmt->execute([$id]);
        $row = $stmt->fetch();
        return $row ?: null;
    }

    public static function attachmentsFor(int $messageId): array
    {
        $stmt = Database::connection()->prepare(
            'SELECT id, message_id, original_name, mime_type, file_size, created_at
             FROM attachments WHERE message_id = ? ORDER BY id ASC'
        );
        $stmt->execute([$messageId]);
        return array_map(function ($row) {
            return [
                'id' => (int) $row['id'],
                'message_id' => (int) $row['message_id'],
                'original_name' => $row['original_name'],
                'mime_type' => $row['mime_type'],
                'file_size' => (int) $row['file_size'],
                'created_at' => $row['created_at'],
            ];
        }, $stmt->fetchAll());
    }

    // ── Helpers ───────────────────────────────

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


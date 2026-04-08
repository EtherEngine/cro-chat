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
     * Optionally filter by channel_id, conversation_id, user_id, date range.
     *
     * Returns rows with: id, body, snippet, user_id, channel_id, conversation_id,
     * thread_id, created_at, author_name, author_color, context.
     */
    public static function messages(
        int $userId,
        string $query,
        ?int $channelId = null,
        ?int $conversationId = null,
        ?int $authorId = null,
        ?string $after = null,
        ?string $before = null,
        int $limit = 30
    ): array {
        $params = [];

        $sql = '
            SELECT m.id, m.body, m.user_id, m.channel_id, m.conversation_id,
                   m.thread_id, m.created_at,
                   u.display_name AS author_name, u.avatar_color AS author_color
            FROM messages m
            JOIN users u ON u.id = m.user_id
            LEFT JOIN channels ch ON ch.id = m.channel_id
            WHERE m.deleted_at IS NULL
              AND MATCH(m.body) AGAINST(? IN BOOLEAN MODE)
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
        // BOOLEAN MODE: prefix each word with + for AND semantics, * for prefix matching
        $ftQuery = self::toFulltextQuery($query);
        $params[] = $ftQuery;
        $params[] = $userId;
        $params[] = $userId;
        $params[] = $userId;

        if ($channelId !== null) {
            $sql .= ' AND m.channel_id = ?';
            $params[] = $channelId;
        }
        if ($conversationId !== null) {
            $sql .= ' AND m.conversation_id = ?';
            $params[] = $conversationId;
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

        // Extract first word from user search for snippet generation
        $highlightTerms = self::extractTerms($query);

        // Batch-load context names (1-2 queries instead of N)
        $channelIds = [];
        $convIds = [];
        foreach ($rows as $row) {
            if ($row['channel_id']) {
                $channelIds[(int) $row['channel_id']] = true;
            } elseif ($row['conversation_id']) {
                $convIds[(int) $row['conversation_id']] = true;
            }
        }

        $channelNames = self::channelNamesBatch(array_keys($channelIds));
        $convLabels = self::conversationLabelsBatch(array_keys($convIds), $userId);

        foreach ($rows as &$row) {
            $row['snippet'] = self::buildSnippet($row['body'], $highlightTerms);
            $row['thread_id'] = $row['thread_id'] ? (int) $row['thread_id'] : null;
            if ($row['channel_id']) {
                $row['context'] = $channelNames[(int) $row['channel_id']] ?? '';
            } else {
                $row['context'] = $convLabels[(int) $row['conversation_id']] ?? '';
            }
        }

        return $rows;
    }

    // ── helpers ─────────────────────────────────

    /**
     * Convert user search input to FULLTEXT BOOLEAN MODE query.
     * Each word becomes +word* (required prefix match).
     */
    private static function toFulltextQuery(string $input): string
    {
        // Strip special FULLTEXT chars
        $clean = preg_replace('/[+\-<>()~*"@]/', ' ', $input);
        $words = preg_split('/\s+/', trim($clean), -1, PREG_SPLIT_NO_EMPTY);
        if (empty($words)) {
            return '';
        }
        return implode(' ', array_map(fn($w) => '+' . $w . '*', $words));
    }

    private static function escapeLike(string $value): string
    {
        return str_replace(['%', '_', '\\'], ['\\%', '\\_', '\\\\'], $value);
    }

    /**
     * Extract search terms from user input for snippet highlighting.
     *
     * @return string[]
     */
    private static function extractTerms(string $input): array
    {
        $clean = preg_replace('/[+\-<>()~*"@]/', ' ', $input);
        return preg_split('/\s+/', trim($clean), -1, PREG_SPLIT_NO_EMPTY);
    }

    /**
     * Build a snippet (~150 chars) around the first matched term occurrence.
     * Returns the snippet with match positions preserved for frontend highlighting.
     */
    private static function buildSnippet(string $body, array $terms, int $radius = 60): string
    {
        $bodyLen = mb_strlen($body);

        // Find the earliest occurrence of any term (case-insensitive)
        $firstPos = $bodyLen; // sentinel
        foreach ($terms as $term) {
            $pos = mb_stripos($body, $term);
            if ($pos !== false && $pos < $firstPos) {
                $firstPos = $pos;
            }
        }

        // If no literal match found (fulltext may match differently), start from 0
        if ($firstPos >= $bodyLen) {
            $firstPos = 0;
        }

        $start = max(0, $firstPos - $radius);
        $end = min($bodyLen, $firstPos + $radius + (int) mb_strlen($terms[0] ?? ''));

        $snippet = mb_substr($body, $start, $end - $start);

        if ($start > 0) {
            $snippet = '…' . $snippet;
        }
        if ($end < $bodyLen) {
            $snippet .= '…';
        }

        return $snippet;
    }

    private static function channelNamesBatch(array $channelIds): array
    {
        if (empty($channelIds))
            return [];
        $placeholders = implode(',', array_fill(0, count($channelIds), '?'));
        $stmt = Database::connection()->prepare("SELECT id, name FROM channels WHERE id IN ($placeholders)");
        $stmt->execute($channelIds);
        $map = [];
        foreach ($stmt->fetchAll() as $row) {
            $map[(int) $row['id']] = '#' . $row['name'];
        }
        return $map;
    }

    private static function conversationLabelsBatch(array $convIds, int $excludeUserId): array
    {
        if (empty($convIds))
            return [];
        $placeholders = implode(',', array_fill(0, count($convIds), '?'));
        $sql = "
            SELECT cm.conversation_id, u.display_name
            FROM conversation_members cm
            JOIN users u ON u.id = cm.user_id
            WHERE cm.conversation_id IN ($placeholders) AND u.id != ?
            ORDER BY u.display_name
        ";
        $params = array_merge($convIds, [$excludeUserId]);
        $stmt = Database::connection()->prepare($sql);
        $stmt->execute($params);

        $map = [];
        foreach ($stmt->fetchAll() as $row) {
            $cid = (int) $row['conversation_id'];
            $map[$cid] = isset($map[$cid]) ? $map[$cid] . ', ' . $row['display_name'] : $row['display_name'];
        }
        return $map;
    }
}

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
                   COUNT(cm2.user_id) AS member_count
            FROM channels c
            JOIN space_members sm ON sm.space_id = c.space_id AND sm.user_id = ?
            LEFT JOIN channel_members cm ON cm.channel_id = c.id AND cm.user_id = ?
            LEFT JOIN channel_members cm2 ON cm2.channel_id = c.id
            WHERE c.name LIKE ?
              AND (c.is_private = 0 OR cm.user_id IS NOT NULL)
            GROUP BY c.id
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

    // ── Advanced: Ranked Search ──────────────────

    /**
     * Ranked message search with FULLTEXT relevance scoring, facets, and highlighting.
     *
     * @param array{
     *   channel_id?: int, conversation_id?: int, author_id?: int,
     *   after?: string, before?: string, has_attachment?: bool,
     *   has_reaction?: bool, in_thread?: bool, sort?: string,
     *   page?: int, per_page?: int
     * } $filters
     * @return array{messages: array, facets: array, total: int, page: int, per_page: int, has_more: bool}
     */
    public static function advancedSearch(int $userId, string $query, array $filters = []): array
    {
        $ftQuery = self::toFulltextQuery($query);
        if ($ftQuery === '') {
            return ['messages' => [], 'facets' => [], 'total' => 0, 'page' => 1, 'per_page' => 30, 'has_more' => false];
        }

        $page = max(1, (int) ($filters['page'] ?? 1));
        $perPage = min(50, max(1, (int) ($filters['per_page'] ?? 30)));
        $offset = ($page - 1) * $perPage;
        $sort = $filters['sort'] ?? 'relevance'; // relevance | newest | oldest

        // ── Build WHERE clause ──
        $where = [];
        $params = [];

        // FULLTEXT match
        $where[] = 'MATCH(m.body) AGAINST(? IN BOOLEAN MODE)';
        $params[] = $ftQuery;

        // Not deleted
        $where[] = 'm.deleted_at IS NULL';

        // Permission filter
        $where[] = '(
            (m.channel_id IS NOT NULL AND (
                (ch.is_private = 0 AND EXISTS (SELECT 1 FROM space_members WHERE space_id = ch.space_id AND user_id = ?))
                OR EXISTS (SELECT 1 FROM channel_members WHERE channel_id = m.channel_id AND user_id = ?)
            ))
            OR (m.conversation_id IS NOT NULL AND EXISTS (SELECT 1 FROM conversation_members WHERE conversation_id = m.conversation_id AND user_id = ?))
        )';
        $params[] = $userId;
        $params[] = $userId;
        $params[] = $userId;

        // Facet filters
        if (isset($filters['channel_id'])) {
            $where[] = 'm.channel_id = ?';
            $params[] = (int) $filters['channel_id'];
        }
        if (isset($filters['conversation_id'])) {
            $where[] = 'm.conversation_id = ?';
            $params[] = (int) $filters['conversation_id'];
        }
        if (isset($filters['author_id'])) {
            $where[] = 'm.user_id = ?';
            $params[] = (int) $filters['author_id'];
        }
        if (isset($filters['after'])) {
            $where[] = 'm.created_at >= ?';
            $params[] = $filters['after'];
        }
        if (isset($filters['before'])) {
            $where[] = 'm.created_at <= ?';
            $params[] = $filters['before'];
        }
        if (!empty($filters['has_attachment'])) {
            $where[] = 'EXISTS (SELECT 1 FROM attachments a WHERE a.message_id = m.id)';
        }
        if (!empty($filters['has_reaction'])) {
            $where[] = 'EXISTS (SELECT 1 FROM reactions r WHERE r.message_id = m.id)';
        }
        if (!empty($filters['in_thread'])) {
            $where[] = 'm.thread_id IS NOT NULL';
        }

        $whereSql = implode(' AND ', $where);

        // ── Count total ──
        $countSql = "
            SELECT COUNT(*) AS total
            FROM messages m
            LEFT JOIN channels ch ON ch.id = m.channel_id
            WHERE {$whereSql}
        ";
        $stmt = Database::connection()->prepare($countSql);
        $stmt->execute($params);
        $total = (int) $stmt->fetch()['total'];

        // ── ORDER BY ──
        $orderSql = match ($sort) {
            'newest' => 'm.created_at DESC',
            'oldest' => 'm.created_at ASC',
            default => 'MATCH(m.body) AGAINST(? IN BOOLEAN MODE) DESC, m.created_at DESC',
        };

        $selectParams = $params;
        if ($sort === 'relevance') {
            // Extra param for the relevance ORDER BY
            $selectParams[] = $ftQuery;
        }
        $selectParams[] = $perPage;
        $selectParams[] = $offset;

        $sql = "
            SELECT m.id, m.body, m.user_id, m.channel_id, m.conversation_id,
                   m.thread_id, m.created_at,
                   u.display_name AS author_name, u.avatar_color AS author_color,
                   MATCH(m.body) AGAINST(? IN BOOLEAN MODE) AS relevance
            FROM messages m
            JOIN users u ON u.id = m.user_id
            LEFT JOIN channels ch ON ch.id = m.channel_id
            WHERE {$whereSql}
            ORDER BY {$orderSql}
            LIMIT ? OFFSET ?
        ";
        // Prepend ftQuery for the SELECT relevance column
        array_unshift($selectParams, $ftQuery);
        $stmt = Database::connection()->prepare($sql);
        $stmt->execute($selectParams);
        $rows = $stmt->fetchAll();

        // ── Enrich results ──
        $highlightTerms = self::extractTerms($query);
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
            $row['highlights'] = self::buildHighlights($row['body'], $highlightTerms);
            $row['relevance'] = round((float) $row['relevance'], 4);
            $row['thread_id'] = $row['thread_id'] ? (int) $row['thread_id'] : null;
            if ($row['channel_id']) {
                $row['context'] = $channelNames[(int) $row['channel_id']] ?? '';
            } else {
                $row['context'] = $convLabels[(int) $row['conversation_id']] ?? '';
            }
        }

        // ── Build facets (from full result set, not just current page) ──
        $facets = self::buildFacets($userId, $ftQuery, $params, $whereSql);

        return [
            'messages' => $rows,
            'facets' => $facets,
            'total' => $total,
            'page' => $page,
            'per_page' => $perPage,
            'has_more' => ($offset + $perPage) < $total,
        ];
    }

    /**
     * Build facet counts for channels, authors, and date ranges.
     */
    private static function buildFacets(int $userId, string $ftQuery, array $baseParams, string $baseWhere): array
    {
        $db = Database::connection();

        // Channel facets (top 10)
        $sql = "
            SELECT ch.id, ch.name, COUNT(*) AS count
            FROM messages m
            LEFT JOIN channels ch ON ch.id = m.channel_id
            WHERE {$baseWhere} AND m.channel_id IS NOT NULL
            GROUP BY ch.id, ch.name
            ORDER BY count DESC
            LIMIT 10
        ";
        $stmt = $db->prepare($sql);
        $stmt->execute($baseParams);
        $channelFacets = $stmt->fetchAll();

        // Author facets (top 10)
        $sql = "
            SELECT u.id, u.display_name, COUNT(*) AS count
            FROM messages m
            JOIN users u ON u.id = m.user_id
            LEFT JOIN channels ch ON ch.id = m.channel_id
            WHERE {$baseWhere}
            GROUP BY u.id, u.display_name
            ORDER BY count DESC
            LIMIT 10
        ";
        $stmt = $db->prepare($sql);
        $stmt->execute($baseParams);
        $authorFacets = $stmt->fetchAll();

        // Date range facets
        $sql = "
            SELECT
                SUM(m.created_at >= DATE_SUB(NOW(), INTERVAL 1 DAY)) AS today,
                SUM(m.created_at >= DATE_SUB(NOW(), INTERVAL 7 DAY)) AS week,
                SUM(m.created_at >= DATE_SUB(NOW(), INTERVAL 30 DAY)) AS month,
                COUNT(*) AS total
            FROM messages m
            LEFT JOIN channels ch ON ch.id = m.channel_id
            WHERE {$baseWhere}
        ";
        $stmt = $db->prepare($sql);
        $stmt->execute($baseParams);
        $dateRow = $stmt->fetch();

        return [
            'channels' => $channelFacets,
            'authors' => $authorFacets,
            'dates' => [
                'today' => (int) ($dateRow['today'] ?? 0),
                'week' => (int) ($dateRow['week'] ?? 0),
                'month' => (int) ($dateRow['month'] ?? 0),
                'total' => (int) ($dateRow['total'] ?? 0),
            ],
        ];
    }

    // ── Saved Searches ───────────────────────────

    public static function createSavedSearch(int $userId, int $spaceId, string $name, string $query, ?array $filters = null, bool $notify = false): array
    {
        $db = Database::connection();
        $stmt = $db->prepare(
            'INSERT INTO saved_searches (user_id, space_id, name, `query`, filters, notify)
             VALUES (?, ?, ?, ?, ?, ?)'
        );
        $stmt->execute([
            $userId,
            $spaceId,
            $name,
            $query,
            $filters !== null ? json_encode($filters, JSON_UNESCAPED_UNICODE) : null,
            $notify ? 1 : 0,
        ]);
        return self::findSavedSearch((int) $db->lastInsertId());
    }

    public static function findSavedSearch(int $id): ?array
    {
        $stmt = Database::connection()->prepare(
            'SELECT * FROM saved_searches WHERE id = ?'
        );
        $stmt->execute([$id]);
        $row = $stmt->fetch();
        return $row ? self::hydrateSavedSearch($row) : null;
    }

    public static function listSavedSearches(int $userId, ?int $spaceId = null): array
    {
        $sql = 'SELECT * FROM saved_searches WHERE user_id = ?';
        $params = [$userId];
        if ($spaceId !== null) {
            $sql .= ' AND space_id = ?';
            $params[] = $spaceId;
        }
        $sql .= ' ORDER BY updated_at DESC';
        $stmt = Database::connection()->prepare($sql);
        $stmt->execute($params);
        return array_map([self::class, 'hydrateSavedSearch'], $stmt->fetchAll());
    }

    public static function updateSavedSearch(int $id, array $data): array
    {
        $sets = [];
        $params = [];
        foreach (['name', 'query', 'notify'] as $field) {
            if (array_key_exists($field, $data)) {
                if ($field === 'query') {
                    $sets[] = '`query` = ?';
                } else {
                    $sets[] = "{$field} = ?";
                }
                $params[] = $field === 'notify' ? ($data[$field] ? 1 : 0) : $data[$field];
            }
        }
        if (array_key_exists('filters', $data)) {
            $sets[] = 'filters = ?';
            $params[] = $data['filters'] !== null ? json_encode($data['filters'], JSON_UNESCAPED_UNICODE) : null;
        }
        if (empty($sets)) {
            return self::findSavedSearch($id);
        }
        $params[] = $id;
        $sql = 'UPDATE saved_searches SET ' . implode(', ', $sets) . ' WHERE id = ?';
        Database::connection()->prepare($sql)->execute($params);
        return self::findSavedSearch($id);
    }

    public static function deleteSavedSearch(int $id): void
    {
        Database::connection()->prepare('DELETE FROM saved_searches WHERE id = ?')->execute([$id]);
    }

    public static function touchSavedSearch(int $id): void
    {
        Database::connection()->prepare('UPDATE saved_searches SET last_run_at = NOW() WHERE id = ?')->execute([$id]);
    }

    private static function hydrateSavedSearch(array $row): array
    {
        return [
            'id' => (int) $row['id'],
            'user_id' => (int) $row['user_id'],
            'space_id' => (int) $row['space_id'],
            'name' => $row['name'],
            'query' => $row['query'],
            'filters' => $row['filters'] ? json_decode($row['filters'], true) : null,
            'notify' => (bool) $row['notify'],
            'last_run_at' => $row['last_run_at'],
            'created_at' => $row['created_at'],
            'updated_at' => $row['updated_at'],
        ];
    }

    // ── Search History ───────────────────────────

    public static function recordHistory(int $userId, string $query, ?array $filters, int $resultCount): void
    {
        Database::connection()->prepare(
            'INSERT INTO search_history (user_id, `query`, filters, result_count) VALUES (?, ?, ?, ?)'
        )->execute([
                    $userId,
                    $query,
                    $filters ? json_encode($filters, JSON_UNESCAPED_UNICODE) : null,
                    $resultCount,
                ]);

        // Keep only the latest 50 entries per user
        Database::connection()->prepare('
            DELETE FROM search_history
            WHERE user_id = ? AND id NOT IN (
                SELECT id FROM (
                    SELECT id FROM search_history WHERE user_id = ? ORDER BY created_at DESC LIMIT 50
                ) AS keep
            )
        ')->execute([$userId, $userId]);
    }

    public static function searchHistory(int $userId, int $limit = 20): array
    {
        $stmt = Database::connection()->prepare(
            'SELECT * FROM search_history WHERE user_id = ? ORDER BY created_at DESC LIMIT ?'
        );
        $stmt->execute([$userId, $limit]);
        return array_map(function (array $row): array {
            return [
                'id' => (int) $row['id'],
                'query' => $row['query'],
                'filters' => $row['filters'] ? json_decode($row['filters'], true) : null,
                'result_count' => (int) $row['result_count'],
                'created_at' => $row['created_at'],
            ];
        }, $stmt->fetchAll());
    }

    public static function clearHistory(int $userId): void
    {
        Database::connection()->prepare('DELETE FROM search_history WHERE user_id = ?')->execute([$userId]);
    }

    // ── Suggest (autocomplete from history) ──────

    public static function suggest(int $userId, string $prefix, int $limit = 5): array
    {
        $stmt = Database::connection()->prepare('
            SELECT `query`, MAX(created_at) AS last_used, COUNT(*) AS use_count
            FROM search_history
            WHERE user_id = ? AND `query` LIKE ?
            GROUP BY `query`
            ORDER BY last_used DESC
            LIMIT ?
        ');
        $stmt->execute([$userId, self::escapeLike($prefix) . '%', $limit]);
        return $stmt->fetchAll();
    }

    // ── helpers ─────────────────────────────────

    /**
     * Convert user search input to FULLTEXT BOOLEAN MODE query.
     * Each word becomes +word* (required prefix match).
     */
    public static function toFulltextQuery(string $input): string
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
    public static function extractTerms(string $input): array
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

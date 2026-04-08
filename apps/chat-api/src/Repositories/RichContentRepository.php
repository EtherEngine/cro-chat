<?php

declare(strict_types=1);

namespace App\Repositories;

use App\Support\Database;

/**
 * Repository for rich content features: Snippets, Link Previews, Shared Drafts.
 */
final class RichContentRepository
{
    // ══════════════════════════════════════════════════════════
    // Markdown / Rendering helpers
    // ══════════════════════════════════════════════════════════

    /**
     * Extract URLs from a message body for link unfurling.
     * Returns unique URLs found in the text.
     */
    public static function extractUrls(string $body): array
    {
        // Match http/https URLs, avoid matching inside markdown image/link syntax targets only
        if (!preg_match_all('#https?://[^\s<>\)\]"\'`]+#i', $body, $matches)) {
            return [];
        }

        $urls = [];
        foreach ($matches[0] as $url) {
            // Trim trailing punctuation that is likely not part of the URL
            $url = rtrim($url, '.,;:!?)>');
            if (filter_var($url, FILTER_VALIDATE_URL) && strlen($url) <= 2048) {
                $urls[] = $url;
            }
        }

        return array_values(array_unique($urls));
    }

    /**
     * Detect code blocks in markdown content.
     * Returns parsed info about each code block found.
     */
    public static function extractCodeBlocks(string $body): array
    {
        $blocks = [];
        // Fenced code blocks: ```lang\n...\n```
        if (preg_match_all('/```(\w*)\n(.*?)```/s', $body, $matches, PREG_SET_ORDER)) {
            foreach ($matches as $m) {
                $blocks[] = [
                    'language' => $m[1] !== '' ? $m[1] : 'text',
                    'code' => $m[2],
                    'type' => 'fenced',
                ];
            }
        }
        return $blocks;
    }

    // ══════════════════════════════════════════════════════════
    // Snippets
    // ══════════════════════════════════════════════════════════

    public static function createSnippet(
        int $spaceId,
        int $userId,
        string $title,
        string $content,
        string $language = 'text',
        string $description = '',
        ?int $channelId = null,
        bool $isPublic = true
    ): array {
        $db = Database::connection();
        $stmt = $db->prepare(
            'INSERT INTO snippets (space_id, user_id, title, content, language, description, channel_id, is_public)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?)'
        );
        $stmt->execute([$spaceId, $userId, $title, $content, $language, $description, $channelId, $isPublic ? 1 : 0]);

        return self::findSnippet((int) $db->lastInsertId());
    }

    public static function findSnippet(int $id): ?array
    {
        $stmt = Database::connection()->prepare(
            'SELECT s.*, u.display_name, u.avatar_color
             FROM snippets s
             JOIN users u ON u.id = s.user_id
             WHERE s.id = ?'
        );
        $stmt->execute([$id]);
        $row = $stmt->fetch();
        return $row ? self::hydrateSnippet($row) : null;
    }

    public static function listSnippets(
        int $spaceId,
        ?string $language = null,
        ?int $channelId = null,
        ?string $search = null,
        int $limit = 50,
        int $offset = 0
    ): array {
        $where = ['s.space_id = ?'];
        $params = [$spaceId];

        if ($language !== null) {
            $where[] = 's.language = ?';
            $params[] = $language;
        }
        if ($channelId !== null) {
            $where[] = 's.channel_id = ?';
            $params[] = $channelId;
        }
        if ($search !== null && $search !== '') {
            $where[] = 'MATCH(s.title, s.content) AGAINST(? IN BOOLEAN MODE)';
            $params[] = $search;
        }

        $whereClause = implode(' AND ', $where);
        $params[] = $limit + 1;
        $params[] = $offset;

        $stmt = Database::connection()->prepare(
            "SELECT s.*, u.display_name, u.avatar_color
             FROM snippets s
             JOIN users u ON u.id = s.user_id
             WHERE $whereClause
             ORDER BY s.created_at DESC
             LIMIT ? OFFSET ?"
        );
        $stmt->execute($params);
        $rows = $stmt->fetchAll();

        $hasMore = count($rows) > $limit;
        if ($hasMore) {
            array_pop($rows);
        }

        return [
            'data' => array_map([self::class, 'hydrateSnippet'], $rows),
            'has_more' => $hasMore,
        ];
    }

    public static function updateSnippet(int $id, array $fields): array
    {
        $allowed = ['title', 'content', 'language', 'description', 'is_public'];
        $sets = [];
        $params = [];

        foreach ($fields as $key => $value) {
            if (in_array($key, $allowed, true)) {
                $sets[] = "`$key` = ?";
                $params[] = $key === 'is_public' ? ($value ? 1 : 0) : $value;
            }
        }

        if (empty($sets)) {
            return self::findSnippet($id);
        }

        $params[] = $id;
        Database::connection()->prepare(
            'UPDATE snippets SET ' . implode(', ', $sets) . ' WHERE id = ?'
        )->execute($params);

        return self::findSnippet($id);
    }

    public static function deleteSnippet(int $id): void
    {
        Database::connection()->prepare('DELETE FROM snippets WHERE id = ?')->execute([$id]);
    }

    private static function hydrateSnippet(array $row): array
    {
        return [
            'id' => (int) $row['id'],
            'space_id' => (int) $row['space_id'],
            'channel_id' => $row['channel_id'] ? (int) $row['channel_id'] : null,
            'user_id' => (int) $row['user_id'],
            'title' => $row['title'],
            'language' => $row['language'],
            'content' => $row['content'],
            'description' => $row['description'],
            'is_public' => (bool) $row['is_public'],
            'created_at' => $row['created_at'],
            'updated_at' => $row['updated_at'],
            'user' => [
                'id' => (int) $row['user_id'],
                'display_name' => $row['display_name'],
                'avatar_color' => $row['avatar_color'],
            ],
        ];
    }

    // ══════════════════════════════════════════════════════════
    // Link Previews
    // ══════════════════════════════════════════════════════════

    public static function createLinkPreview(int $messageId, string $url): array
    {
        $db = Database::connection();
        $stmt = $db->prepare(
            'INSERT INTO link_previews (message_id, url, status) VALUES (?, ?, ?)'
        );
        $stmt->execute([$messageId, $url, 'pending']);

        return self::findLinkPreview((int) $db->lastInsertId());
    }

    public static function findLinkPreview(int $id): ?array
    {
        $stmt = Database::connection()->prepare(
            'SELECT * FROM link_previews WHERE id = ?'
        );
        $stmt->execute([$id]);
        $row = $stmt->fetch();
        return $row ? self::hydrateLinkPreview($row) : null;
    }

    public static function forMessage(int $messageId): array
    {
        $stmt = Database::connection()->prepare(
            'SELECT * FROM link_previews WHERE message_id = ? ORDER BY id ASC'
        );
        $stmt->execute([$messageId]);
        return array_map([self::class, 'hydrateLinkPreview'], $stmt->fetchAll());
    }

    /**
     * Batch-load link previews for multiple messages.
     * Returns [ messageId => [ preview, ... ] ]
     */
    public static function forMessages(array $messageIds): array
    {
        if (empty($messageIds)) {
            return [];
        }

        $placeholders = implode(',', array_fill(0, count($messageIds), '?'));
        $stmt = Database::connection()->prepare(
            "SELECT * FROM link_previews
             WHERE message_id IN ($placeholders) AND status = 'resolved'
             ORDER BY id ASC"
        );
        $stmt->execute($messageIds);

        $map = [];
        foreach ($stmt->fetchAll() as $row) {
            $mid = (int) $row['message_id'];
            $map[$mid][] = self::hydrateLinkPreview($row);
        }
        return $map;
    }

    public static function resolveLinkPreview(int $id, array $metadata): void
    {
        $stmt = Database::connection()->prepare(
            'UPDATE link_previews
             SET title = ?, description = ?, image_url = ?, site_name = ?,
                 content_type = ?, status = ?, fetched_at = NOW()
             WHERE id = ?'
        );
        $stmt->execute([
            $metadata['title'] ?? null,
            $metadata['description'] ?? null,
            $metadata['image_url'] ?? null,
            $metadata['site_name'] ?? null,
            $metadata['content_type'] ?? null,
            'resolved',
            $id,
        ]);
    }

    public static function failLinkPreview(int $id, string $errorMessage): void
    {
        $stmt = Database::connection()->prepare(
            "UPDATE link_previews SET status = 'failed', error_message = ?, fetched_at = NOW() WHERE id = ?"
        );
        $stmt->execute([$errorMessage, $id]);
    }

    public static function pendingPreviews(int $limit = 50): array
    {
        $stmt = Database::connection()->prepare(
            "SELECT * FROM link_previews WHERE status = 'pending' ORDER BY created_at ASC LIMIT ?"
        );
        $stmt->execute([$limit]);
        return array_map([self::class, 'hydrateLinkPreview'], $stmt->fetchAll());
    }

    private static function hydrateLinkPreview(array $row): array
    {
        return [
            'id' => (int) $row['id'],
            'message_id' => (int) $row['message_id'],
            'url' => $row['url'],
            'title' => $row['title'],
            'description' => $row['description'],
            'image_url' => $row['image_url'],
            'site_name' => $row['site_name'],
            'content_type' => $row['content_type'],
            'status' => $row['status'],
            'error_message' => $row['error_message'],
            'fetched_at' => $row['fetched_at'],
            'created_at' => $row['created_at'],
        ];
    }

    // ══════════════════════════════════════════════════════════
    // Shared Drafts
    // ══════════════════════════════════════════════════════════

    public static function createDraft(
        int $spaceId,
        int $userId,
        string $body,
        string $title = '',
        string $format = 'markdown',
        ?int $channelId = null,
        ?int $conversationId = null
    ): array {
        $db = Database::connection();
        $stmt = $db->prepare(
            'INSERT INTO drafts (space_id, user_id, body, title, format, channel_id, conversation_id)
             VALUES (?, ?, ?, ?, ?, ?, ?)'
        );
        $stmt->execute([$spaceId, $userId, $body, $title, $format, $channelId, $conversationId]);

        return self::findDraft((int) $db->lastInsertId());
    }

    public static function findDraft(int $id): ?array
    {
        $stmt = Database::connection()->prepare(
            'SELECT d.*, u.display_name, u.avatar_color
             FROM drafts d
             JOIN users u ON u.id = d.user_id
             WHERE d.id = ?'
        );
        $stmt->execute([$id]);
        $row = $stmt->fetch();
        if (!$row) {
            return null;
        }

        $draft = self::hydrateDraft($row);
        $draft['collaborators'] = self::draftCollaborators($id);
        return $draft;
    }

    public static function listDrafts(int $spaceId, int $userId, bool $sharedOnly = false): array
    {
        if ($sharedOnly) {
            // Show all shared drafts in the space (visible to all space members)
            $stmt = Database::connection()->prepare(
                'SELECT d.*, u.display_name, u.avatar_color
                 FROM drafts d
                 JOIN users u ON u.id = d.user_id
                 WHERE d.space_id = ? AND d.is_shared = 1
                 ORDER BY d.updated_at DESC'
            );
            $stmt->execute([$spaceId]);
        } else {
            // Show user's own drafts
            $stmt = Database::connection()->prepare(
                'SELECT d.*, u.display_name, u.avatar_color
                 FROM drafts d
                 JOIN users u ON u.id = d.user_id
                 WHERE d.space_id = ? AND d.user_id = ?
                 ORDER BY d.updated_at DESC'
            );
            $stmt->execute([$spaceId, $userId]);
        }

        return array_map([self::class, 'hydrateDraft'], $stmt->fetchAll());
    }

    public static function updateDraft(int $id, array $fields): array
    {
        $allowed = ['title', 'body', 'format', 'is_shared'];
        $sets = [];
        $params = [];

        foreach ($fields as $key => $value) {
            if (in_array($key, $allowed, true)) {
                $sets[] = "`$key` = ?";
                $params[] = $key === 'is_shared' ? ($value ? 1 : 0) : $value;
            }
        }

        if (!empty($sets)) {
            // Increment version on body changes
            if (isset($fields['body'])) {
                $sets[] = 'version = version + 1';
            }

            $params[] = $id;
            Database::connection()->prepare(
                'UPDATE drafts SET ' . implode(', ', $sets) . ' WHERE id = ?'
            )->execute($params);
        }

        return self::findDraft($id);
    }

    public static function deleteDraft(int $id): void
    {
        Database::connection()->prepare('DELETE FROM drafts WHERE id = ?')->execute([$id]);
    }

    public static function publishDraft(int $id, int $messageId): void
    {
        Database::connection()->prepare(
            'UPDATE drafts SET published_message_id = ? WHERE id = ?'
        )->execute([$messageId, $id]);
    }

    // ── Draft Collaborators ──────────────────────

    public static function addCollaborator(int $draftId, int $userId, string $permission = 'view'): void
    {
        Database::connection()->prepare(
            'INSERT IGNORE INTO draft_collaborators (draft_id, user_id, permission) VALUES (?, ?, ?)'
        )->execute([$draftId, $userId, $permission]);
    }

    public static function removeCollaborator(int $draftId, int $userId): void
    {
        Database::connection()->prepare(
            'DELETE FROM draft_collaborators WHERE draft_id = ? AND user_id = ?'
        )->execute([$draftId, $userId]);
    }

    public static function updateCollaboratorPermission(int $draftId, int $userId, string $permission): void
    {
        Database::connection()->prepare(
            'UPDATE draft_collaborators SET permission = ? WHERE draft_id = ? AND user_id = ?'
        )->execute([$permission, $draftId, $userId]);
    }

    public static function draftCollaborators(int $draftId): array
    {
        $stmt = Database::connection()->prepare(
            'SELECT dc.*, u.display_name, u.avatar_color
             FROM draft_collaborators dc
             JOIN users u ON u.id = dc.user_id
             WHERE dc.draft_id = ?
             ORDER BY dc.added_at ASC'
        );
        $stmt->execute([$draftId]);

        return array_map(fn(array $row) => [
            'user_id' => (int) $row['user_id'],
            'permission' => $row['permission'],
            'display_name' => $row['display_name'],
            'avatar_color' => $row['avatar_color'],
            'added_at' => $row['added_at'],
        ], $stmt->fetchAll());
    }

    public static function isCollaborator(int $draftId, int $userId): bool
    {
        $stmt = Database::connection()->prepare(
            'SELECT 1 FROM draft_collaborators WHERE draft_id = ? AND user_id = ?'
        );
        $stmt->execute([$draftId, $userId]);
        return (bool) $stmt->fetch();
    }

    public static function collaboratorPermission(int $draftId, int $userId): ?string
    {
        $stmt = Database::connection()->prepare(
            'SELECT permission FROM draft_collaborators WHERE draft_id = ? AND user_id = ?'
        );
        $stmt->execute([$draftId, $userId]);
        $row = $stmt->fetch();
        return $row ? $row['permission'] : null;
    }

    private static function hydrateDraft(array $row): array
    {
        return [
            'id' => (int) $row['id'],
            'space_id' => (int) $row['space_id'],
            'channel_id' => $row['channel_id'] ? (int) $row['channel_id'] : null,
            'conversation_id' => $row['conversation_id'] ? (int) $row['conversation_id'] : null,
            'user_id' => (int) $row['user_id'],
            'title' => $row['title'],
            'body' => $row['body'],
            'format' => $row['format'],
            'is_shared' => (bool) $row['is_shared'],
            'version' => (int) $row['version'],
            'published_message_id' => $row['published_message_id'] ? (int) $row['published_message_id'] : null,
            'created_at' => $row['created_at'],
            'updated_at' => $row['updated_at'],
            'user' => [
                'id' => (int) $row['user_id'],
                'display_name' => $row['display_name'],
                'avatar_color' => $row['avatar_color'],
            ],
        ];
    }
}

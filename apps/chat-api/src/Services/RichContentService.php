<?php

declare(strict_types=1);

namespace App\Services;

use App\Exceptions\ApiException;
use App\Repositories\EventRepository;
use App\Repositories\MessageRepository;
use App\Repositories\RichContentRepository;
use App\Repositories\SpaceRepository;
use App\Support\Database;

/**
 * Business logic for rich content: Markdown rendering, Snippets, Link Previews, Shared Drafts.
 */
final class RichContentService
{
    // Allowed URL schemes for link unfurling
    private const ALLOWED_SCHEMES = ['http', 'https'];

    // Max URLs to unfurl per message
    private const MAX_UNFURL_URLS = 5;

    // Supported snippet languages
    private const SUPPORTED_LANGUAGES = [
        'text',
        'javascript',
        'typescript',
        'php',
        'python',
        'java',
        'csharp',
        'cpp',
        'go',
        'rust',
        'ruby',
        'swift',
        'kotlin',
        'sql',
        'html',
        'css',
        'scss',
        'json',
        'yaml',
        'xml',
        'markdown',
        'bash',
        'powershell',
        'dockerfile',
    ];

    // ══════════════════════════════════════════════════════════
    // Markdown / Rendering Pipeline
    // ══════════════════════════════════════════════════════════

    /**
     * Parse message body and return structured content info.
     * Detects markdown features, code blocks, and URLs for unfurling.
     */
    public static function analyzeContent(string $body): array
    {
        $codeBlocks = RichContentRepository::extractCodeBlocks($body);
        $urls = RichContentRepository::extractUrls($body);

        return [
            'has_markdown' => self::hasMarkdownSyntax($body),
            'has_code_blocks' => !empty($codeBlocks),
            'code_blocks' => $codeBlocks,
            'urls' => $urls,
            'format' => 'markdown',
        ];
    }

    /**
     * Sanitize HTML output from markdown rendering.
     * Strips dangerous tags/attributes while preserving safe formatting.
     */
    public static function sanitizeHtml(string $html): string
    {
        // Strip script, style, iframe, object, embed, form tags completely
        $dangerous = ['script', 'style', 'iframe', 'object', 'embed', 'form', 'input', 'textarea', 'select', 'button'];
        foreach ($dangerous as $tag) {
            $html = preg_replace('#<' . $tag . '\b[^>]*>.*?</' . $tag . '>#si', '', $html);
            $html = preg_replace('#<' . $tag . '\b[^>]*\s*/?>#si', '', $html);
        }

        // Remove event handler attributes (onclick, onerror, onload, etc.)
        $html = preg_replace('/\s+on\w+\s*=\s*["\'][^"\']*["\']/i', '', $html);
        $html = preg_replace('/\s+on\w+\s*=\s*\S+/i', '', $html);

        // Remove javascript: and data: URIs in href/src attributes
        $html = preg_replace('/\b(href|src)\s*=\s*["\']?\s*(javascript|data|vbscript)\s*:/i', '$1="about:blank"', $html);

        return $html;
    }

    /**
     * Render markdown to safe HTML (basic implementation).
     * Returns sanitized HTML suitable for client rendering.
     */
    public static function renderMarkdown(string $body): string
    {
        $html = $body;

        // Fenced code blocks
        $html = preg_replace_callback('/```(\w*)\n(.*?)```/s', function ($m) {
            $lang = htmlspecialchars($m[1] ?: 'text', ENT_QUOTES, 'UTF-8');
            $code = htmlspecialchars($m[2], ENT_QUOTES, 'UTF-8');
            return "<pre><code class=\"language-{$lang}\">{$code}</code></pre>";
        }, $html);

        // Inline code
        $html = preg_replace('/`([^`]+)`/', '<code>$1</code>', $html);

        // Bold
        $html = preg_replace('/\*\*(.+?)\*\*/s', '<strong>$1</strong>', $html);

        // Italic
        $html = preg_replace('/(?<!\*)\*(?!\*)(.+?)(?<!\*)\*(?!\*)/s', '<em>$1</em>', $html);

        // Strikethrough
        $html = preg_replace('/~~(.+?)~~/s', '<del>$1</del>', $html);

        // Links [text](url)
        $html = preg_replace_callback('/\[([^\]]+)\]\(([^)]+)\)/', function ($m) {
            $text = htmlspecialchars($m[1], ENT_QUOTES, 'UTF-8');
            $url = htmlspecialchars($m[2], ENT_QUOTES, 'UTF-8');
            return "<a href=\"{$url}\" rel=\"noopener noreferrer\" target=\"_blank\">{$text}</a>";
        }, $html);

        // Blockquotes
        $html = preg_replace('/^>\s?(.+)$/m', '<blockquote>$1</blockquote>', $html);

        // Line breaks
        $html = nl2br($html);

        return self::sanitizeHtml($html);
    }

    private static function hasMarkdownSyntax(string $body): bool
    {
        return (bool) preg_match('/(\*\*|__|~~|```|`[^`]+`|\[.+\]\(.+\)|^>\s)/m', $body);
    }

    // ══════════════════════════════════════════════════════════
    // Snippets
    // ══════════════════════════════════════════════════════════

    public static function createSnippet(int $spaceId, int $userId, array $input): array
    {
        self::requireSpaceMember($spaceId, $userId);

        $title = trim($input['title'] ?? '');
        if ($title === '' || mb_strlen($title) > 200) {
            throw ApiException::validation('Titel erforderlich (max 200 Zeichen)', 'SNIPPET_TITLE_INVALID');
        }

        $content = $input['content'] ?? '';
        if ($content === '') {
            throw ApiException::validation('Inhalt darf nicht leer sein', 'SNIPPET_CONTENT_EMPTY');
        }
        if (mb_strlen($content) > 100_000) {
            throw ApiException::validation('Inhalt zu lang (max 100.000 Zeichen)', 'SNIPPET_CONTENT_TOO_LONG');
        }

        $language = $input['language'] ?? 'text';
        if (!in_array($language, self::SUPPORTED_LANGUAGES, true)) {
            $language = 'text';
        }

        $description = isset($input['description']) ? trim(mb_substr($input['description'], 0, 500)) : '';
        $channelId = isset($input['channel_id']) ? (int) $input['channel_id'] : null;
        $isPublic = $input['is_public'] ?? true;

        $snippet = RichContentRepository::createSnippet(
            $spaceId,
            $userId,
            $title,
            $content,
            $language,
            $description,
            $channelId,
            (bool) $isPublic
        );

        EventRepository::publish('snippet.created', "space:$spaceId", $snippet);
        return $snippet;
    }

    public static function getSnippet(int $snippetId, int $userId): array
    {
        $snippet = RichContentRepository::findSnippet($snippetId);
        if (!$snippet) {
            throw ApiException::notFound('Snippet nicht gefunden', 'SNIPPET_NOT_FOUND');
        }
        self::requireSpaceMember($snippet['space_id'], $userId);

        if (!$snippet['is_public'] && $snippet['user_id'] !== $userId) {
            throw ApiException::forbidden('Kein Zugriff auf privates Snippet', 'SNIPPET_ACCESS_DENIED');
        }

        return $snippet;
    }

    public static function listSnippets(int $spaceId, int $userId, ?string $language = null, ?int $channelId = null, ?string $search = null): array
    {
        self::requireSpaceMember($spaceId, $userId);
        return RichContentRepository::listSnippets($spaceId, $language, $channelId, $search);
    }

    public static function updateSnippet(int $snippetId, int $userId, array $input): array
    {
        $snippet = RichContentRepository::findSnippet($snippetId);
        if (!$snippet) {
            throw ApiException::notFound('Snippet nicht gefunden', 'SNIPPET_NOT_FOUND');
        }

        if ($snippet['user_id'] !== $userId && !SpaceRepository::isAdminOrOwner($snippet['space_id'], $userId)) {
            throw ApiException::forbidden('Nur eigene Snippets bearbeiten', 'SNIPPET_EDIT_DENIED');
        }

        $fields = [];
        if (isset($input['title'])) {
            $title = trim($input['title']);
            if ($title === '' || mb_strlen($title) > 200) {
                throw ApiException::validation('Titel erforderlich (max 200 Zeichen)', 'SNIPPET_TITLE_INVALID');
            }
            $fields['title'] = $title;
        }
        if (isset($input['content'])) {
            if (mb_strlen($input['content']) > 100_000) {
                throw ApiException::validation('Inhalt zu lang', 'SNIPPET_CONTENT_TOO_LONG');
            }
            $fields['content'] = $input['content'];
        }
        if (isset($input['language'])) {
            $fields['language'] = in_array($input['language'], self::SUPPORTED_LANGUAGES, true) ? $input['language'] : 'text';
        }
        if (isset($input['description'])) {
            $fields['description'] = trim(mb_substr($input['description'], 0, 500));
        }
        if (isset($input['is_public'])) {
            $fields['is_public'] = (bool) $input['is_public'];
        }

        $updated = RichContentRepository::updateSnippet($snippetId, $fields);
        EventRepository::publish('snippet.updated', "space:{$snippet['space_id']}", $updated);
        return $updated;
    }

    public static function deleteSnippet(int $snippetId, int $userId): void
    {
        $snippet = RichContentRepository::findSnippet($snippetId);
        if (!$snippet) {
            throw ApiException::notFound('Snippet nicht gefunden', 'SNIPPET_NOT_FOUND');
        }

        if ($snippet['user_id'] !== $userId && !SpaceRepository::isAdminOrOwner($snippet['space_id'], $userId)) {
            throw ApiException::forbidden('Nur eigene Snippets löschen', 'SNIPPET_DELETE_DENIED');
        }

        RichContentRepository::deleteSnippet($snippetId);
    }

    public static function supportedLanguages(): array
    {
        return self::SUPPORTED_LANGUAGES;
    }
    // ══════════════════════════════════════════════════════════
    // Link Previews / Unfurling
    // ══════════════════════════════════════════════════════════

    /**
     * Process a new message: extract URLs and create pending link previews.
     * Called after message creation to queue unfurling jobs.
     */
    public static function processMessageUrls(int $messageId, string $body): array
    {
        $urls = RichContentRepository::extractUrls($body);
        if (empty($urls)) {
            return [];
        }

        // Limit URLs per message
        $urls = array_slice($urls, 0, self::MAX_UNFURL_URLS);
        $previews = [];

        foreach ($urls as $url) {
            if (!self::isUrlSafe($url)) {
                continue;
            }
            $previews[] = RichContentRepository::createLinkPreview($messageId, $url);
        }

        // Dispatch async unfurl job
        if (!empty($previews)) {
            JobService::dispatch('linkpreview.unfurl', [
                'message_id' => $messageId,
                'preview_ids' => array_column($previews, 'id'),
            ], 'default', 2, 150);
        }

        return $previews;
    }

    /**
     * Get link previews for a message.
     */
    public static function getMessagePreviews(int $messageId): array
    {
        return RichContentRepository::forMessage($messageId);
    }

    /**
     * Unfurl a single link preview: fetch metadata from the URL.
     * Called by the job handler.
     */
    public static function unfurlPreview(int $previewId): void
    {
        $preview = RichContentRepository::findLinkPreview($previewId);
        if (!$preview || $preview['status'] !== 'pending') {
            return;
        }

        try {
            $metadata = self::fetchUrlMetadata($preview['url']);
            RichContentRepository::resolveLinkPreview($previewId, $metadata);

            // Notify clients about the resolved preview
            $message = MessageRepository::find($preview['message_id']);
            if ($message) {
                $room = $message['channel_id']
                    ? "channel:{$message['channel_id']}"
                    : "conversation:{$message['conversation_id']}";
                $resolved = RichContentRepository::findLinkPreview($previewId);
                EventRepository::publish('linkpreview.resolved', $room, [
                    'message_id' => $preview['message_id'],
                    'preview' => $resolved,
                ]);
            }
        } catch (\Throwable $e) {
            RichContentRepository::failLinkPreview($previewId, mb_substr($e->getMessage(), 0, 500));
        }
    }

    /**
     * Process all pending link previews (called by job handler).
     */
    public static function processPendingPreviews(): int
    {
        $pending = RichContentRepository::pendingPreviews(50);
        $count = 0;

        foreach ($pending as $preview) {
            self::unfurlPreview($preview['id']);
            $count++;
        }

        return $count;
    }

    /**
     * Fetch Open Graph / HTML metadata from a URL.
     * Uses stream context with timeouts and safety checks.
     */
    private static function fetchUrlMetadata(string $url): array
    {
        // Validate URL safety before fetching
        if (!self::isUrlSafe($url)) {
            throw new \RuntimeException('URL nicht erlaubt');
        }

        $context = stream_context_create([
            'http' => [
                'method' => 'GET',
                'timeout' => 5,
                'max_redirects' => 3,
                'header' => "User-Agent: CroBot/1.0 (Link Preview)\r\nAccept: text/html,application/xhtml+xml\r\n",
                'ignore_errors' => true,
            ],
            'ssl' => [
                'verify_peer' => true,
                'verify_peer_name' => true,
            ],
        ]);

        $html = @file_get_contents($url, false, $context);
        if ($html === false) {
            throw new \RuntimeException('URL konnte nicht abgerufen werden');
        }

        // Limit to first 100KB to avoid memory issues
        $html = mb_substr($html, 0, 100_000);

        $metadata = [
            'title' => null,
            'description' => null,
            'image_url' => null,
            'site_name' => null,
            'content_type' => null,
        ];

        // Detect content type from response headers
        if (!empty($http_response_header)) {
            foreach ($http_response_header as $header) {
                if (stripos($header, 'Content-Type:') === 0) {
                    $metadata['content_type'] = trim(substr($header, 13));
                    break;
                }
            }
        }

        // Parse Open Graph meta tags
        if (preg_match('/<meta\s+property=["\']og:title["\']\s+content=["\']([^"\']+)["\']/i', $html, $m)) {
            $metadata['title'] = html_entity_decode($m[1], ENT_QUOTES, 'UTF-8');
        }
        if (preg_match('/<meta\s+property=["\']og:description["\']\s+content=["\']([^"\']+)["\']/i', $html, $m)) {
            $metadata['description'] = html_entity_decode($m[1], ENT_QUOTES, 'UTF-8');
        }
        if (preg_match('/<meta\s+property=["\']og:image["\']\s+content=["\']([^"\']+)["\']/i', $html, $m)) {
            $imageUrl = $m[1];
            if (filter_var($imageUrl, FILTER_VALIDATE_URL) && self::isUrlSafe($imageUrl)) {
                $metadata['image_url'] = $imageUrl;
            }
        }
        if (preg_match('/<meta\s+property=["\']og:site_name["\']\s+content=["\']([^"\']+)["\']/i', $html, $m)) {
            $metadata['site_name'] = html_entity_decode($m[1], ENT_QUOTES, 'UTF-8');
        }

        // Fallback to <title> if no OG title
        if ($metadata['title'] === null && preg_match('/<title>([^<]+)<\/title>/i', $html, $m)) {
            $metadata['title'] = html_entity_decode(trim($m[1]), ENT_QUOTES, 'UTF-8');
        }

        // Fallback to meta description
        if ($metadata['description'] === null) {
            if (preg_match('/<meta\s+name=["\']description["\']\s+content=["\']([^"\']+)["\']/i', $html, $m)) {
                $metadata['description'] = html_entity_decode($m[1], ENT_QUOTES, 'UTF-8');
            }
        }

        // Truncate fields for safety
        if ($metadata['title'])
            $metadata['title'] = mb_substr($metadata['title'], 0, 500);
        if ($metadata['description'])
            $metadata['description'] = mb_substr($metadata['description'], 0, 1000);
        if ($metadata['site_name'])
            $metadata['site_name'] = mb_substr($metadata['site_name'], 0, 200);

        return $metadata;
    }

    /**
     * Validate a URL is safe to fetch (no SSRF, no internal IPs).
     */
    private static function isUrlSafe(string $url): bool
    {
        $parsed = parse_url($url);
        if (!$parsed || !isset($parsed['scheme'], $parsed['host'])) {
            return false;
        }

        if (!in_array(strtolower($parsed['scheme']), self::ALLOWED_SCHEMES, true)) {
            return false;
        }

        $host = strtolower($parsed['host']);

        // Block internal/reserved hostnames
        $blocked = ['localhost', '127.0.0.1', '0.0.0.0', '[::1]', 'metadata.google.internal', '169.254.169.254'];
        if (in_array($host, $blocked, true)) {
            return false;
        }

        // Block private IP ranges
        $ip = gethostbyname($host);
        if ($ip !== $host) {
            $flags = FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE;
            if (!filter_var($ip, FILTER_VALIDATE_IP, $flags)) {
                return false;
            }
        }

        return true;
    }
    // ══════════════════════════════════════════════════════════
    // Shared Drafts
    // ══════════════════════════════════════════════════════════

    public static function createDraft(int $spaceId, int $userId, array $input): array
    {
        self::requireSpaceMember($spaceId, $userId);

        $body = trim($input['body'] ?? '');
        if ($body === '') {
            throw ApiException::validation('Entwurf darf nicht leer sein', 'DRAFT_BODY_EMPTY');
        }
        if (mb_strlen($body) > 100_000) {
            throw ApiException::validation('Entwurf zu lang (max 100.000 Zeichen)', 'DRAFT_BODY_TOO_LONG');
        }

        $title = isset($input['title']) ? trim(mb_substr($input['title'], 0, 200)) : '';
        $formatInput = $input['format'] ?? 'markdown';
        $format = in_array($formatInput, ['markdown', 'plaintext'], true) ? $formatInput : 'markdown';
        $channelId = isset($input['channel_id']) ? (int) $input['channel_id'] : null;
        $conversationId = isset($input['conversation_id']) ? (int) $input['conversation_id'] : null;

        $draft = RichContentRepository::createDraft(
            $spaceId,
            $userId,
            $body,
            $title,
            $format,
            $channelId,
            $conversationId
        );

        return $draft;
    }

    public static function getDraft(int $draftId, int $userId): array
    {
        $draft = RichContentRepository::findDraft($draftId);
        if (!$draft) {
            throw ApiException::notFound('Entwurf nicht gefunden', 'DRAFT_NOT_FOUND');
        }

        self::requireDraftAccess($draft, $userId);
        return $draft;
    }

    public static function listDrafts(int $spaceId, int $userId, bool $sharedOnly = false): array
    {
        self::requireSpaceMember($spaceId, $userId);
        return RichContentRepository::listDrafts($spaceId, $userId, $sharedOnly);
    }

    public static function updateDraft(int $draftId, int $userId, array $input): array
    {
        $draft = RichContentRepository::findDraft($draftId);
        if (!$draft) {
            throw ApiException::notFound('Entwurf nicht gefunden', 'DRAFT_NOT_FOUND');
        }

        self::requireDraftEditAccess($draft, $userId);

        $fields = [];
        if (isset($input['title'])) {
            $fields['title'] = trim(mb_substr($input['title'], 0, 200));
        }
        if (isset($input['body'])) {
            $body = trim($input['body']);
            if ($body === '') {
                throw ApiException::validation('Entwurf darf nicht leer sein', 'DRAFT_BODY_EMPTY');
            }
            if (mb_strlen($body) > 100_000) {
                throw ApiException::validation('Entwurf zu lang', 'DRAFT_BODY_TOO_LONG');
            }
            $fields['body'] = $body;
        }
        if (isset($input['format']) && in_array($input['format'], ['markdown', 'plaintext'], true)) {
            $fields['format'] = $input['format'];
        }
        if (isset($input['is_shared'])) {
            $fields['is_shared'] = (bool) $input['is_shared'];
        }

        $updated = RichContentRepository::updateDraft($draftId, $fields);

        // Notify collaborators of changes
        if ($draft['is_shared']) {
            EventRepository::publish('draft.updated', "space:{$draft['space_id']}", [
                'draft_id' => $draftId,
                'updated_by' => $userId,
                'version' => $updated['version'],
            ]);
        }

        return $updated;
    }

    public static function deleteDraft(int $draftId, int $userId): void
    {
        $draft = RichContentRepository::findDraft($draftId);
        if (!$draft) {
            throw ApiException::notFound('Entwurf nicht gefunden', 'DRAFT_NOT_FOUND');
        }

        if ($draft['user_id'] !== $userId && !SpaceRepository::isAdminOrOwner($draft['space_id'], $userId)) {
            throw ApiException::forbidden('Nur eigene Entwürfe löschen', 'DRAFT_DELETE_DENIED');
        }

        RichContentRepository::deleteDraft($draftId);
    }

    /**
     * Publish a draft as a message in its target channel/conversation.
     */
    public static function publishDraft(int $draftId, int $userId): array
    {
        $draft = RichContentRepository::findDraft($draftId);
        if (!$draft) {
            throw ApiException::notFound('Entwurf nicht gefunden', 'DRAFT_NOT_FOUND');
        }

        self::requireDraftEditAccess($draft, $userId);

        if ($draft['published_message_id'] !== null) {
            throw ApiException::conflict('Entwurf wurde bereits veröffentlicht', 'DRAFT_ALREADY_PUBLISHED');
        }

        if ($draft['channel_id'] === null && $draft['conversation_id'] === null) {
            throw ApiException::validation('Entwurf hat kein Ziel (Channel/Konversation)', 'DRAFT_NO_TARGET');
        }

        // Create message via MessageService
        $input = ['body' => $draft['body']];

        if ($draft['channel_id'] !== null) {
            $message = MessageService::createChannel($draft['channel_id'], $userId, $input);
        } else {
            $message = MessageService::createConversation($draft['conversation_id'], $userId, $input);
        }

        RichContentRepository::publishDraft($draftId, $message['id']);
        return $message;
    }

    // ── Draft Collaborators ──────────────────────

    public static function addCollaborator(int $draftId, int $userId, int $collaboratorId, string $permission = 'view'): void
    {
        $draft = RichContentRepository::findDraft($draftId);
        if (!$draft) {
            throw ApiException::notFound('Entwurf nicht gefunden', 'DRAFT_NOT_FOUND');
        }

        if ($draft['user_id'] !== $userId) {
            throw ApiException::forbidden('Nur der Autor kann Mitarbeiter hinzufügen', 'DRAFT_COLLAB_DENIED');
        }

        if (!$draft['is_shared']) {
            throw ApiException::validation('Entwurf muss geteilt sein', 'DRAFT_NOT_SHARED');
        }

        if (!in_array($permission, ['view', 'edit'], true)) {
            $permission = 'view';
        }

        self::requireSpaceMember($draft['space_id'], $collaboratorId);
        RichContentRepository::addCollaborator($draftId, $collaboratorId, $permission);
    }

    public static function removeCollaborator(int $draftId, int $userId, int $collaboratorId): void
    {
        $draft = RichContentRepository::findDraft($draftId);
        if (!$draft) {
            throw ApiException::notFound('Entwurf nicht gefunden', 'DRAFT_NOT_FOUND');
        }

        if ($draft['user_id'] !== $userId) {
            throw ApiException::forbidden('Nur der Autor kann Mitarbeiter entfernen', 'DRAFT_COLLAB_DENIED');
        }

        RichContentRepository::removeCollaborator($draftId, $collaboratorId);
    }

    // ── Helpers ──────────────────────────────────

    private static function requireSpaceMember(int $spaceId, int $userId): void
    {
        if (!SpaceRepository::isMember($spaceId, $userId)) {
            throw ApiException::forbidden('Kein Mitglied dieses Space', 'SPACE_MEMBER_REQUIRED');
        }
    }

    private static function requireDraftAccess(array $draft, int $userId): void
    {
        if ($draft['user_id'] === $userId) {
            return;
        }
        if ($draft['is_shared'] && RichContentRepository::isCollaborator($draft['id'], $userId)) {
            return;
        }
        if ($draft['is_shared'] && SpaceRepository::isMember($draft['space_id'], $userId)) {
            // Shared drafts are visible to all space members
            return;
        }
        throw ApiException::forbidden('Kein Zugriff auf diesen Entwurf', 'DRAFT_ACCESS_DENIED');
    }

    private static function requireDraftEditAccess(array $draft, int $userId): void
    {
        if ($draft['user_id'] === $userId) {
            return;
        }
        $perm = RichContentRepository::collaboratorPermission($draft['id'], $userId);
        if ($perm === 'edit') {
            return;
        }
        throw ApiException::forbidden('Keine Bearbeitungsrechte für diesen Entwurf', 'DRAFT_EDIT_DENIED');
    }
}

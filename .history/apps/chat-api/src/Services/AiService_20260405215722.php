<?php

declare(strict_types=1);

namespace App\Services;

use App\Exceptions\ApiException;
use App\Repositories\AiRepository;
use App\Repositories\MessageRepository;
use App\Repositories\ThreadRepository;
use App\Repositories\ChannelRepository;
use App\Repositories\SpaceRepository;
use App\Support\AiProvider;
use App\Support\Database;
use App\Support\HeuristicAiProvider;
use App\Support\SecretManager;

/**
 * AI Feature Service – Thread/Channel Summaries, Action-Item Extraction,
 * Semantic Search, Reply Suggestions.
 *
 * Delegates actual AI processing to the AiProvider interface.
 * Results are stored separately from primary chat data (ai_* tables).
 * All AI outputs link back to source messages for traceability.
 */
final class AiService
{
    /** Overrideable provider for testing */
    private static ?AiProvider $provider = null;

    public static function setProvider(?AiProvider $provider): void
    {
        self::$provider = $provider;
    }

    public static function getProvider(int $spaceId): AiProvider
    {
        if (self::$provider !== null) {
            return self::$provider;
        }

        // Fallback: heuristic provider (no API key required)
        return new HeuristicAiProvider();
    }

    // ── Provider Config ──────────────────────────────────────

    public static function getConfig(int $spaceId, int $userId): array
    {
        self::requireSpaceMember($spaceId, $userId);
        $config = AiRepository::getConfig($spaceId);
        if (!$config) {
            return [
                'is_enabled' => false,
                'provider' => 'openai',
                'model_summary' => 'gpt-4o-mini',
                'model_embedding' => 'text-embedding-3-small',
                'model_suggest' => 'gpt-4o-mini',
                'has_api_key' => false,
            ];
        }
        // Never expose the encrypted API key
        $result = $config;
        $result['has_api_key'] = !empty($config['api_key_enc']);
        unset($result['api_key_enc']);
        return $result;
    }

    public static function updateConfig(int $spaceId, int $userId, array $input): array
    {
        self::requireSpaceAdmin($spaceId, $userId);

        $data = [];
        if (isset($input['provider'])) {
            $allowed = ['openai', 'azure', 'anthropic', 'local', 'heuristic'];
            if (!in_array($input['provider'], $allowed, true)) {
                throw ApiException::validation('Ungültiger Provider', 'INVALID_PROVIDER');
            }
            $data['provider'] = $input['provider'];
        }
        if (isset($input['api_key']) && $input['api_key'] !== '') {
            SecretManager::init();
            $data['api_key_enc'] = SecretManager::encrypt($input['api_key']);
        }
        foreach (['model_summary', 'model_embedding', 'model_suggest'] as $f) {
            if (isset($input[$f]) && is_string($input[$f]) && $input[$f] !== '') {
                $data[$f] = $input[$f];
            }
        }
        if (isset($input['max_tokens'])) {
            $data['max_tokens'] = max(100, min(8000, (int) $input['max_tokens']));
        }
        if (isset($input['temperature'])) {
            $data['temperature'] = max(0.0, min(2.0, (float) $input['temperature']));
        }
        if (isset($input['is_enabled'])) {
            $data['is_enabled'] = $input['is_enabled'] ? 1 : 0;
        }

        $config = AiRepository::upsertConfig($spaceId, $data);
        $config['has_api_key'] = !empty($config['api_key_enc']);
        unset($config['api_key_enc']);
        return $config;
    }

    // ── Thread Summary ───────────────────────────────────────

    public static function summarizeThread(int $threadId, int $spaceId, int $userId): array
    {
        self::requireSpaceMember($spaceId, $userId);

        $thread = ThreadRepository::find($threadId);
        if (!$thread) {
            throw ApiException::notFound('Thread nicht gefunden');
        }

        $messages = self::loadThreadMessages($threadId);
        if (empty($messages)) {
            throw ApiException::validation('Keine Nachrichten zum Zusammenfassen', 'NO_MESSAGES');
        }

        $provider = self::getProvider($spaceId);
        $start = hrtime(true);
        $result = $provider->summarize($messages, 'thread');
        $processingMs = (int) ((hrtime(true) - $start) / 1_000_000);

        $participantIds = array_values(array_unique(array_map(fn($m) => (int) $m['user_id'], $messages)));
        $firstMsg = $messages[0];
        $lastMsg = end($messages);

        $summary = AiRepository::createSummary([
            'space_id' => $spaceId,
            'scope_type' => 'thread',
            'scope_id' => $threadId,
            'title' => $result['title'] ?? '',
            'summary' => $result['summary'],
            'key_points' => $result['key_points'] ?? [],
            'action_items' => array_map(fn($a) => $a['title'] ?? '', $result['action_items'] ?? []),
            'participants' => $participantIds,
            'message_count' => count($messages),
            'first_message_id' => (int) $firstMsg['id'],
            'last_message_id' => (int) $lastMsg['id'],
            'period_start' => $firstMsg['created_at'],
            'period_end' => $lastMsg['created_at'],
            'model' => $result['model'] ?? 'unknown',
            'tokens_used' => $result['tokens_used'] ?? 0,
            'processing_ms' => $processingMs,
        ]);

        // Link source messages
        $messageIds = array_map(fn($m) => (int) $m['id'], $messages);
        AiRepository::addSummarySources($summary['id'], $messageIds);

        // Create action items as separate records
        foreach ($result['action_items'] ?? [] as $ai) {
            $sourceIdx = $ai['source_index'] ?? null;
            $sourceMsgId = ($sourceIdx !== null && isset($messages[$sourceIdx])) ? (int) $messages[$sourceIdx]['id'] : null;
            AiRepository::createActionItem([
                'space_id' => $spaceId,
                'summary_id' => $summary['id'],
                'source_message_id' => $sourceMsgId,
                'title' => $ai['title'],
                'description' => $ai['description'] ?? null,
                'assignee_hint' => $ai['assignee_hint'] ?? null,
                'due_hint' => $ai['due_hint'] ?? null,
                'confidence' => $ai['confidence'] ?? 0.80,
            ]);
        }

        return $summary;
    }

    // ── Channel Summary ──────────────────────────────────────

    public static function summarizeChannel(int $channelId, int $spaceId, int $userId, ?string $periodStart = null, ?string $periodEnd = null): array
    {
        self::requireSpaceMember($spaceId, $userId);

        $channel = ChannelRepository::find($channelId);
        if (!$channel) {
            throw ApiException::notFound('Channel nicht gefunden');
        }

        if (!$periodStart) {
            $periodStart = date('Y-m-d 00:00:00', strtotime('-1 day'));
        }
        if (!$periodEnd) {
            $periodEnd = date('Y-m-d 23:59:59', strtotime('-1 day'));
        }

        $messages = self::loadChannelMessages($channelId, $periodStart, $periodEnd);
        if (empty($messages)) {
            throw ApiException::validation('Keine Nachrichten im Zeitraum', 'NO_MESSAGES');
        }

        $provider = self::getProvider($spaceId);
        $start = hrtime(true);
        $result = $provider->summarize($messages, 'channel');
        $processingMs = (int) ((hrtime(true) - $start) / 1_000_000);

        $participantIds = array_values(array_unique(array_map(fn($m) => (int) $m['user_id'], $messages)));
        $firstMsg = $messages[0];
        $lastMsg = end($messages);

        $summary = AiRepository::createSummary([
            'space_id' => $spaceId,
            'scope_type' => 'channel',
            'scope_id' => $channelId,
            'title' => $result['title'] ?? 'Channel-Zusammenfassung',
            'summary' => $result['summary'],
            'key_points' => $result['key_points'] ?? [],
            'action_items' => array_map(fn($a) => $a['title'] ?? '', $result['action_items'] ?? []),
            'participants' => $participantIds,
            'message_count' => count($messages),
            'first_message_id' => (int) $firstMsg['id'],
            'last_message_id' => (int) $lastMsg['id'],
            'period_start' => $periodStart,
            'period_end' => $periodEnd,
            'model' => $result['model'] ?? 'unknown',
            'tokens_used' => $result['tokens_used'] ?? 0,
            'processing_ms' => $processingMs,
        ]);

        // Link source messages (sample if too many)
        $msgIds = array_map(fn($m) => (int) $m['id'], $messages);
        if (count($msgIds) > 100) {
            // Sample 100 evenly distributed messages
            $step = count($msgIds) / 100;
            $sampled = [];
            for ($i = 0; $i < 100; $i++) {
                $sampled[] = $msgIds[(int) ($i * $step)];
            }
            $msgIds = $sampled;
        }
        AiRepository::addSummarySources($summary['id'], $msgIds);

        // Create action items
        foreach ($result['action_items'] ?? [] as $ai) {
            $sourceIdx = $ai['source_index'] ?? null;
            $sourceMsgId = ($sourceIdx !== null && isset($messages[$sourceIdx])) ? (int) $messages[$sourceIdx]['id'] : null;
            AiRepository::createActionItem([
                'space_id' => $spaceId,
                'summary_id' => $summary['id'],
                'source_message_id' => $sourceMsgId,
                'title' => $ai['title'],
                'description' => $ai['description'] ?? null,
                'assignee_hint' => $ai['assignee_hint'] ?? null,
                'due_hint' => $ai['due_hint'] ?? null,
                'confidence' => $ai['confidence'] ?? 0.80,
            ]);
        }

        return $summary;
    }

    // ── Action Items ─────────────────────────────────────────

    public static function listActionItems(int $spaceId, int $userId, ?string $status = null): array
    {
        self::requireSpaceMember($spaceId, $userId);
        return AiRepository::listActionItems($spaceId, $status);
    }

    public static function updateActionItemStatus(int $actionItemId, int $userId, string $status): array
    {
        $item = AiRepository::findActionItem($actionItemId);
        if (!$item) {
            throw ApiException::notFound('Action Item nicht gefunden');
        }
        self::requireSpaceMember($item['space_id'], $userId);

        $allowed = ['open', 'done', 'dismissed'];
        if (!in_array($status, $allowed, true)) {
            throw ApiException::validation('Status muss open, done oder dismissed sein', 'INVALID_STATUS');
        }

        AiRepository::updateActionItemStatus($actionItemId, $status);
        return AiRepository::findActionItem($actionItemId);
    }

    // ── Semantic Search ──────────────────────────────────────

    public static function semanticSearch(int $spaceId, int $userId, string $query, int $limit = 20): array
    {
        self::requireSpaceMember($spaceId, $userId);

        if (mb_strlen(trim($query)) < 2) {
            throw ApiException::validation('Suchbegriff zu kurz (min 2 Zeichen)', 'QUERY_TOO_SHORT');
        }

        $provider = self::getProvider($spaceId);
        $queryResult = $provider->embed($query);
        $queryEmbedding = $queryResult['embedding'];

        // Load all embeddings for the space and compute cosine similarity
        $embeddings = AiRepository::getEmbeddingsForSpace($spaceId, 2000);

        if (empty($embeddings)) {
            // Fallback: Use FULLTEXT search
            return self::fallbackTextSearch($spaceId, $query, $limit);
        }

        $results = [];
        foreach ($embeddings as $emb) {
            $stored = self::blobToFloats($emb['embedding'], (int) $emb['dimensions']);
            $similarity = self::cosineSimilarity($queryEmbedding, $stored);
            if ($similarity > 0.1) {
                $results[] = [
                    'message_id' => (int) $emb['message_id'],
                    'body' => $emb['body'],
                    'user_id' => (int) $emb['user_id'],
                    'channel_id' => $emb['channel_id'] !== null ? (int) $emb['channel_id'] : null,
                    'created_at' => $emb['message_created_at'],
                    'similarity' => round($similarity, 4),
                ];
            }
        }

        usort($results, fn($a, $b) => $b['similarity'] <=> $a['similarity']);
        return array_slice($results, 0, $limit);
    }

    public static function embedMessage(int $spaceId, int $messageId, string $body): void
    {
        $provider = self::getProvider($spaceId);
        $result = $provider->embed($body);

        $blob = self::floatsToBlob($result['embedding']);
        AiRepository::upsertEmbedding($spaceId, $messageId, $blob, $result['model'], $result['dimensions']);
    }

    // ── Reply Suggestions ────────────────────────────────────

    public static function suggest(int $spaceId, int $userId, string $scopeType, int $scopeId, ?int $contextMessageId = null): array
    {
        self::requireSpaceMember($spaceId, $userId);

        $allowed = ['thread', 'channel', 'conversation'];
        if (!in_array($scopeType, $allowed, true)) {
            throw ApiException::validation('Scope muss thread, channel oder conversation sein', 'INVALID_SCOPE');
        }

        // Load recent context messages
        $messages = self::loadContextMessages($scopeType, $scopeId, 20);
        if (empty($messages)) {
            throw ApiException::validation('Keine Nachrichten für Vorschläge', 'NO_CONTEXT');
        }

        // Get user display name
        $db = Database::connection();
        $stmt = $db->prepare('SELECT display_name FROM users WHERE id = ?');
        $stmt->execute([$userId]);
        $userName = $stmt->fetchColumn() ?: 'User';

        $provider = self::getProvider($spaceId);
        $result = $provider->suggest($messages, $userName);

        $stored = AiRepository::createSuggestion([
            'space_id' => $spaceId,
            'user_id' => $userId,
            'scope_type' => $scopeType,
            'scope_id' => $scopeId,
            'context_message_id' => $contextMessageId,
            'suggestions' => $result['suggestions'] ?? [],
            'model' => $result['model'] ?? 'unknown',
            'tokens_used' => $result['tokens_used'] ?? 0,
        ]);

        return $stored;
    }

    public static function acceptSuggestion(int $suggestionId, int $userId, int $index): array
    {
        $suggestion = AiRepository::findSuggestion($suggestionId);
        if (!$suggestion) {
            throw ApiException::notFound('Vorschlag nicht gefunden');
        }
        if ($suggestion['user_id'] !== $userId) {
            throw ApiException::forbidden('Nur eigene Vorschläge können akzeptiert werden');
        }

        $count = count($suggestion['suggestions']);
        if ($index < 0 || $index >= $count) {
            throw ApiException::validation("Index muss 0–{$count} sein", 'INVALID_INDEX');
        }

        AiRepository::acceptSuggestion($suggestionId, $index);
        return AiRepository::findSuggestion($suggestionId);
    }

    // ── Summaries CRUD ───────────────────────────────────────

    public static function listSummaries(int $spaceId, int $userId, ?string $scopeType = null, ?int $scopeId = null): array
    {
        self::requireSpaceMember($spaceId, $userId);
        return AiRepository::listSummaries($spaceId, $scopeType, $scopeId);
    }

    public static function getSummary(int $summaryId, int $userId): array
    {
        $summary = AiRepository::findSummary($summaryId);
        if (!$summary) {
            throw ApiException::notFound('Zusammenfassung nicht gefunden');
        }
        self::requireSpaceMember($summary['space_id'], $userId);

        $summary['sources'] = AiRepository::summarySourceMessages($summaryId);
        $summary['action_items_detail'] = AiRepository::listActionItems($summary['space_id'], null, $summaryId);
        return $summary;
    }

    public static function deleteSummary(int $summaryId, int $userId): void
    {
        $summary = AiRepository::findSummary($summaryId);
        if (!$summary) {
            throw ApiException::notFound('Zusammenfassung nicht gefunden');
        }
        self::requireSpaceAdmin($summary['space_id'], $userId);
        AiRepository::deleteSummary($summaryId);
    }

    // ── Async Job Triggers ───────────────────────────────────

    public static function requestThreadSummary(int $threadId, int $userId): array
    {
        $thread = ThreadRepository::find($threadId);
        if (!$thread) {
            throw ApiException::notFound('Thread nicht gefunden');
        }
        $channelId = (int) $thread['channel_id'];
        $channel = ChannelRepository::find($channelId);
        $spaceId = (int) $channel['space_id'];
        self::requireSpaceMember($spaceId, $userId);

        return JobService::dispatch(
            'ai.summarize_thread',
            ['thread_id' => $threadId, 'space_id' => $spaceId, 'user_id' => $userId],
            'default',
            3,
            100,
            "ai.thread.{$threadId}"
        );
    }

    public static function requestChannelSummary(int $channelId, int $userId): array
    {
        $channel = ChannelRepository::find($channelId);
        if (!$channel) {
            throw ApiException::notFound('Channel nicht gefunden');
        }
        $spaceId = (int) $channel['space_id'];
        self::requireSpaceMember($spaceId, $userId);

        $date = date('Y-m-d');
        return JobService::dispatch(
            'ai.summarize_channel',
            ['channel_id' => $channelId, 'space_id' => $spaceId, 'user_id' => $userId],
            'default',
            3,
            100,
            "ai.channel.{$channelId}.{$date}"
        );
    }

    public static function requestExtraction(int $spaceId, int $userId, ?int $channelId = null): array
    {
        self::requireSpaceAdmin($spaceId, $userId);

        $date = date('Y-m-d');
        $scope = $channelId ? "channel.{$channelId}" : 'space';
        return JobService::dispatch(
            'ai.extract',
            ['space_id' => $spaceId, 'channel_id' => $channelId, 'user_id' => $userId],
            'default',
            3,
            100,
            "ai.extract.{$spaceId}.{$scope}.{$date}"
        );
    }

    public static function requestEmbedding(int $spaceId, int $userId, ?int $channelId = null): array
    {
        self::requireSpaceAdmin($spaceId, $userId);

        $date = date('Y-m-d');
        $scope = $channelId ? "channel.{$channelId}" : 'space';
        return JobService::dispatch(
            'ai.embed',
            ['space_id' => $spaceId, 'channel_id' => $channelId, 'user_id' => $userId],
            'default',
            3,
            100,
            "ai.embed.{$spaceId}.{$scope}.{$date}"
        );
    }

    // ── Stats ────────────────────────────────────────────────

    public static function stats(int $spaceId, int $userId): array
    {
        self::requireSpaceMember($spaceId, $userId);

        $db = Database::connection();

        $summaryCount = $db->prepare('SELECT COUNT(*) FROM ai_summaries WHERE space_id = ?');
        $summaryCount->execute([$spaceId]);

        $actionCount = $db->prepare('SELECT COUNT(*) FROM ai_action_items WHERE space_id = ?');
        $actionCount->execute([$spaceId]);

        $openActions = $db->prepare('SELECT COUNT(*) FROM ai_action_items WHERE space_id = ? AND status = ?');
        $openActions->execute([$spaceId, 'open']);

        $embeddingCount = AiRepository::countEmbeddings($spaceId);

        $suggestionCount = $db->prepare('SELECT COUNT(*) FROM ai_suggestions WHERE space_id = ?');
        $suggestionCount->execute([$spaceId]);

        return [
            'summaries' => (int) $summaryCount->fetchColumn(),
            'action_items' => (int) $actionCount->fetchColumn(),
            'action_items_open' => (int) $openActions->fetchColumn(),
            'embeddings' => $embeddingCount,
            'suggestions' => (int) $suggestionCount->fetchColumn(),
        ];
    }

    // ── Private Helpers ──────────────────────────────────────

    private static function loadThreadMessages(int $threadId, int $limit = 200): array
    {
        $db = Database::connection();
        $stmt = $db->prepare(
            'SELECT m.id, m.body, m.user_id, m.created_at, u.display_name
             FROM messages m
             JOIN users u ON u.id = m.user_id
             WHERE m.thread_id = ? AND m.deleted_at IS NULL
             ORDER BY m.id ASC LIMIT ?'
        );
        $stmt->execute([$threadId, $limit]);
        return $stmt->fetchAll();
    }

    private static function loadChannelMessages(int $channelId, string $from, string $to, int $limit = 500): array
    {
        $db = Database::connection();
        $stmt = $db->prepare(
            'SELECT m.id, m.body, m.user_id, m.created_at, u.display_name
             FROM messages m
             JOIN users u ON u.id = m.user_id
             WHERE m.channel_id = ? AND m.thread_id IS NULL AND m.deleted_at IS NULL
               AND m.created_at >= ? AND m.created_at <= ?
             ORDER BY m.id ASC LIMIT ?'
        );
        $stmt->execute([$channelId, $from, $to, $limit]);
        return $stmt->fetchAll();
    }

    private static function loadContextMessages(string $scopeType, int $scopeId, int $limit): array
    {
        $db = Database::connection();

        if ($scopeType === 'thread') {
            $stmt = $db->prepare(
                'SELECT m.id, m.body, m.user_id, m.created_at, u.display_name
                 FROM messages m JOIN users u ON u.id = m.user_id
                 WHERE m.thread_id = ? AND m.deleted_at IS NULL
                 ORDER BY m.id DESC LIMIT ?'
            );
            $stmt->execute([$scopeId, $limit]);
        } elseif ($scopeType === 'channel') {
            $stmt = $db->prepare(
                'SELECT m.id, m.body, m.user_id, m.created_at, u.display_name
                 FROM messages m JOIN users u ON u.id = m.user_id
                 WHERE m.channel_id = ? AND m.thread_id IS NULL AND m.deleted_at IS NULL
                 ORDER BY m.id DESC LIMIT ?'
            );
            $stmt->execute([$scopeId, $limit]);
        } else {
            $stmt = $db->prepare(
                'SELECT m.id, m.body, m.user_id, m.created_at, u.display_name
                 FROM messages m JOIN users u ON u.id = m.user_id
                 WHERE m.conversation_id = ? AND m.deleted_at IS NULL
                 ORDER BY m.id DESC LIMIT ?'
            );
            $stmt->execute([$scopeId, $limit]);
        }

        $rows = $stmt->fetchAll();
        return array_reverse($rows); // chronological order
    }

    private static function fallbackTextSearch(int $spaceId, string $query, int $limit): array
    {
        $db = Database::connection();
        $stmt = $db->prepare(
            "SELECT m.id AS message_id, m.body, m.user_id, m.channel_id, m.created_at,
                    MATCH(m.body) AGAINST(? IN BOOLEAN MODE) AS similarity
             FROM messages m
             JOIN channels c ON c.id = m.channel_id
             WHERE c.space_id = ? AND m.deleted_at IS NULL
               AND MATCH(m.body) AGAINST(? IN BOOLEAN MODE)
             ORDER BY similarity DESC
             LIMIT ?"
        );
        $stmt->execute([$query, $spaceId, $query, $limit]);
        $rows = $stmt->fetchAll();
        foreach ($rows as &$row) {
            $row['message_id'] = (int) $row['message_id'];
            $row['user_id'] = (int) $row['user_id'];
            $row['channel_id'] = $row['channel_id'] !== null ? (int) $row['channel_id'] : null;
            $row['similarity'] = round((float) $row['similarity'], 4);
        }
        return $rows;
    }

    private static function floatsToBlob(array $floats): string
    {
        return pack('f*', ...$floats);
    }

    private static function blobToFloats(string $blob, int $dimensions): array
    {
        $values = unpack("f{$dimensions}", $blob);
        return $values ? array_values($values) : [];
    }

    private static function cosineSimilarity(array $a, array $b): float
    {
        $len = min(count($a), count($b));
        if ($len === 0)
            return 0.0;

        $dot = 0.0;
        $normA = 0.0;
        $normB = 0.0;

        for ($i = 0; $i < $len; $i++) {
            $dot += $a[$i] * $b[$i];
            $normA += $a[$i] * $a[$i];
            $normB += $b[$i] * $b[$i];
        }

        $denom = sqrt($normA) * sqrt($normB);
        return $denom > 0 ? $dot / $denom : 0.0;
    }

    private static function requireSpaceMember(int $spaceId, int $userId): void
    {
        $db = Database::connection();
        $stmt = $db->prepare('SELECT 1 FROM space_members WHERE space_id = ? AND user_id = ?');
        $stmt->execute([$spaceId, $userId]);
        if (!$stmt->fetch()) {
            throw ApiException::forbidden('Kein Mitglied dieses Space');
        }
    }

    private static function requireSpaceAdmin(int $spaceId, int $userId): void
    {
        $db = Database::connection();
        $stmt = $db->prepare('SELECT role FROM space_members WHERE space_id = ? AND user_id = ?');
        $stmt->execute([$spaceId, $userId]);
        $row = $stmt->fetch();
        if (!$row || !in_array($row['role'], ['owner', 'admin'], true)) {
            throw ApiException::forbidden('Admin-Berechtigung erforderlich');
        }
    }
}

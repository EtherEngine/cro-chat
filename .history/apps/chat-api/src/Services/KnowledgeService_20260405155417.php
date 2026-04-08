<?php

declare(strict_types=1);

namespace App\Services;

use App\Exceptions\ApiException;
use App\Repositories\KnowledgeRepository;
use App\Repositories\MessageRepository;
use App\Repositories\ChannelRepository;
use App\Repositories\ThreadRepository;
use App\Repositories\SpaceRepository;
use App\Support\Database;

/**
 * Knowledge Layer – extracts structured knowledge from chat data.
 *
 * Provides thread summaries, channel summaries, topic/decision extraction,
 * and links knowledge back to source messages.
 */
final class KnowledgeService
{
    // ── Topic Management ─────────────────────────────────

    public static function createTopic(int $spaceId, int $userId, array $input): array
    {
        self::requireSpaceMember($spaceId, $userId);

        $name = trim($input['name'] ?? '');
        if ($name === '' || mb_strlen($name) > 200) {
            throw ApiException::validation('Topic-Name erforderlich (max 200 Zeichen)', 'TOPIC_NAME_INVALID');
        }

        $slug = self::slugify($name);
        $existing = KnowledgeRepository::findTopicBySlug($spaceId, $slug);
        if ($existing) {
            throw ApiException::conflict('Topic mit diesem Namen existiert bereits', 'TOPIC_EXISTS');
        }

        return KnowledgeRepository::createTopic([
            'space_id' => $spaceId,
            'channel_id' => isset($input['channel_id']) ? (int) $input['channel_id'] : null,
            'name' => $name,
            'slug' => $slug,
            'description' => isset($input['description']) ? trim($input['description']) : null,
        ]);
    }

    public static function listTopics(int $spaceId, int $userId, ?int $channelId = null): array
    {
        self::requireSpaceMember($spaceId, $userId);
        return KnowledgeRepository::listTopics($spaceId, $channelId);
    }

    public static function getTopic(int $topicId, int $userId): array
    {
        $topic = KnowledgeRepository::findTopic($topicId);
        if (!$topic) {
            throw ApiException::notFound('Topic nicht gefunden', 'TOPIC_NOT_FOUND');
        }
        self::requireSpaceMember($topic['space_id'], $userId);
        return $topic;
    }

    public static function updateTopic(int $topicId, int $userId, array $input): array
    {
        $topic = KnowledgeRepository::findTopic($topicId);
        if (!$topic) {
            throw ApiException::notFound('Topic nicht gefunden', 'TOPIC_NOT_FOUND');
        }
        self::requireSpaceAdmin($topic['space_id'], $userId);

        $data = [];
        if (isset($input['name'])) {
            $name = trim($input['name']);
            if ($name === '' || mb_strlen($name) > 200) {
                throw ApiException::validation('Topic-Name ungültig', 'TOPIC_NAME_INVALID');
            }
            $data['name'] = $name;
            $data['slug'] = self::slugify($name);
        }
        if (isset($input['description'])) {
            $data['description'] = trim($input['description']);
        }

        KnowledgeRepository::updateTopic($topicId, $data);
        return KnowledgeRepository::findTopic($topicId);
    }

    public static function deleteTopic(int $topicId, int $userId): void
    {
        $topic = KnowledgeRepository::findTopic($topicId);
        if (!$topic) {
            throw ApiException::notFound('Topic nicht gefunden', 'TOPIC_NOT_FOUND');
        }
        self::requireSpaceAdmin($topic['space_id'], $userId);
        KnowledgeRepository::deleteTopic($topicId);
    }

    // ── Decision Management ──────────────────────────────

    public static function createDecision(int $spaceId, int $userId, array $input): array
    {
        self::requireSpaceMember($spaceId, $userId);

        $title = trim($input['title'] ?? '');
        if ($title === '' || mb_strlen($title) > 500) {
            throw ApiException::validation('Entscheidungs-Titel erforderlich (max 500 Zeichen)', 'DECISION_TITLE_INVALID');
        }

        $status = $input['status'] ?? 'accepted';
        if (!in_array($status, ['proposed', 'accepted', 'rejected', 'superseded'], true)) {
            throw ApiException::validation('Ungültiger Status', 'DECISION_STATUS_INVALID');
        }

        return KnowledgeRepository::createDecision([
            'space_id' => $spaceId,
            'channel_id' => isset($input['channel_id']) ? (int) $input['channel_id'] : null,
            'topic_id' => isset($input['topic_id']) ? (int) $input['topic_id'] : null,
            'title' => $title,
            'description' => isset($input['description']) ? trim($input['description']) : null,
            'status' => $status,
            'decided_at' => $input['decided_at'] ?? date('Y-m-d H:i:s'),
            'decided_by' => $userId,
            'source_message_id' => isset($input['source_message_id']) ? (int) $input['source_message_id'] : null,
        ]);
    }

    public static function listDecisions(int $spaceId, int $userId, ?int $topicId = null, ?string $status = null): array
    {
        self::requireSpaceMember($spaceId, $userId);
        return KnowledgeRepository::listDecisions($spaceId, $topicId, $status);
    }

    public static function updateDecision(int $decisionId, int $userId, array $input): array
    {
        $decision = KnowledgeRepository::findDecision($decisionId);
        if (!$decision) {
            throw ApiException::notFound('Entscheidung nicht gefunden', 'DECISION_NOT_FOUND');
        }
        self::requireSpaceMember($decision['space_id'], $userId);

        $data = [];
        if (isset($input['title'])) {
            $t = trim($input['title']);
            if ($t === '' || mb_strlen($t) > 500) {
                throw ApiException::validation('Titel ungültig', 'DECISION_TITLE_INVALID');
            }
            $data['title'] = $t;
        }
        if (isset($input['description'])) {
            $data['description'] = trim($input['description']);
        }
        if (isset($input['status'])) {
            if (!in_array($input['status'], ['proposed', 'accepted', 'rejected', 'superseded'], true)) {
                throw ApiException::validation('Ungültiger Status', 'DECISION_STATUS_INVALID');
            }
            $data['status'] = $input['status'];
        }
        if (isset($input['topic_id'])) {
            $data['topic_id'] = (int) $input['topic_id'];
        }

        KnowledgeRepository::updateDecision($decisionId, $data);
        return KnowledgeRepository::findDecision($decisionId);
    }

    public static function deleteDecision(int $decisionId, int $userId): void
    {
        $decision = KnowledgeRepository::findDecision($decisionId);
        if (!$decision) {
            throw ApiException::notFound('Entscheidung nicht gefunden', 'DECISION_NOT_FOUND');
        }
        self::requireSpaceAdmin($decision['space_id'], $userId);
        KnowledgeRepository::deleteDecision($decisionId);
    }

    // ── Summaries ────────────────────────────────────────

    public static function listSummaries(int $spaceId, int $userId, ?string $scopeType = null, ?int $scopeId = null): array
    {
        self::requireSpaceMember($spaceId, $userId);
        return KnowledgeRepository::listSummaries($spaceId, $scopeType, $scopeId);
    }

    public static function getSummary(int $summaryId, int $userId): array
    {
        $summary = KnowledgeRepository::findSummary($summaryId);
        if (!$summary) {
            throw ApiException::notFound('Zusammenfassung nicht gefunden', 'SUMMARY_NOT_FOUND');
        }
        self::requireSpaceMember($summary['space_id'], $userId);

        $sources = KnowledgeRepository::sourcesForSummary($summaryId);
        $summary['sources'] = $sources;
        return $summary;
    }

    public static function deleteSummary(int $summaryId, int $userId): void
    {
        $summary = KnowledgeRepository::findSummary($summaryId);
        if (!$summary) {
            throw ApiException::notFound('Zusammenfassung nicht gefunden', 'SUMMARY_NOT_FOUND');
        }
        self::requireSpaceAdmin($summary['space_id'], $userId);
        KnowledgeRepository::deleteSummary($summaryId);
    }

    // ── Knowledge Entries ────────────────────────────────

    public static function createEntry(int $spaceId, int $userId, array $input): array
    {
        self::requireSpaceMember($spaceId, $userId);

        $title = trim($input['title'] ?? '');
        if ($title === '' || mb_strlen($title) > 500) {
            throw ApiException::validation('Titel erforderlich (max 500 Zeichen)', 'ENTRY_TITLE_INVALID');
        }
        $content = trim($input['content'] ?? '');
        if ($content === '') {
            throw ApiException::validation('Inhalt erforderlich', 'ENTRY_CONTENT_EMPTY');
        }

        $entryType = $input['entry_type'] ?? 'fact';
        $validTypes = ['fact', 'howto', 'link', 'reference', 'definition', 'action_item'];
        if (!in_array($entryType, $validTypes, true)) {
            throw ApiException::validation('Ungültiger Entry-Typ', 'ENTRY_TYPE_INVALID');
        }

        $entry = KnowledgeRepository::createEntry([
            'space_id' => $spaceId,
            'topic_id' => isset($input['topic_id']) ? (int) $input['topic_id'] : null,
            'entry_type' => $entryType,
            'title' => $title,
            'content' => $content,
            'tags' => $input['tags'] ?? [],
            'confidence' => (float) ($input['confidence'] ?? 1.00),
            'extracted_by' => 'manual',
            'created_by' => $userId,
            'source_message_id' => isset($input['source_message_id']) ? (int) $input['source_message_id'] : null,
        ]);

        // Link source message if provided
        if (isset($input['source_message_id'])) {
            KnowledgeRepository::addSource([
                'entry_id' => $entry['id'],
                'message_id' => (int) $input['source_message_id'],
            ]);
        }

        return $entry;
    }

    public static function listEntries(int $spaceId, int $userId, ?int $topicId = null, ?string $type = null): array
    {
        self::requireSpaceMember($spaceId, $userId);
        return KnowledgeRepository::listEntries($spaceId, $topicId, $type);
    }

    public static function getEntry(int $entryId, int $userId): array
    {
        $entry = KnowledgeRepository::findEntry($entryId);
        if (!$entry) {
            throw ApiException::notFound('Knowledge-Eintrag nicht gefunden', 'ENTRY_NOT_FOUND');
        }
        self::requireSpaceMember($entry['space_id'], $userId);

        $sources = KnowledgeRepository::sourcesForEntry($entryId);
        $entry['sources'] = $sources;
        return $entry;
    }

    public static function updateEntry(int $entryId, int $userId, array $input): array
    {
        $entry = KnowledgeRepository::findEntry($entryId);
        if (!$entry) {
            throw ApiException::notFound('Knowledge-Eintrag nicht gefunden', 'ENTRY_NOT_FOUND');
        }
        self::requireSpaceMember($entry['space_id'], $userId);

        $data = [];
        if (isset($input['title'])) {
            $t = trim($input['title']);
            if ($t === '' || mb_strlen($t) > 500) {
                throw ApiException::validation('Titel ungültig', 'ENTRY_TITLE_INVALID');
            }
            $data['title'] = $t;
        }
        if (isset($input['content'])) {
            $data['content'] = trim($input['content']);
        }
        if (isset($input['entry_type'])) {
            $validTypes = ['fact', 'howto', 'link', 'reference', 'definition', 'action_item'];
            if (!in_array($input['entry_type'], $validTypes, true)) {
                throw ApiException::validation('Ungültiger Entry-Typ', 'ENTRY_TYPE_INVALID');
            }
            $data['entry_type'] = $input['entry_type'];
        }
        if (isset($input['topic_id'])) {
            $data['topic_id'] = (int) $input['topic_id'];
        }
        if (isset($input['tags'])) {
            $data['tags'] = $input['tags'];
        }
        if (isset($input['confidence'])) {
            $data['confidence'] = (float) $input['confidence'];
        }

        KnowledgeRepository::updateEntry($entryId, $data);
        return KnowledgeRepository::findEntry($entryId);
    }

    public static function deleteEntry(int $entryId, int $userId): void
    {
        $entry = KnowledgeRepository::findEntry($entryId);
        if (!$entry) {
            throw ApiException::notFound('Knowledge-Eintrag nicht gefunden', 'ENTRY_NOT_FOUND');
        }
        self::requireSpaceAdmin($entry['space_id'], $userId);
        KnowledgeRepository::deleteEntry($entryId);
    }

    // ── Search ───────────────────────────────────────────

    public static function search(int $spaceId, int $userId, string $query): array
    {
        self::requireSpaceMember($spaceId, $userId);

        if (mb_strlen($query) < 2) {
            throw ApiException::validation('Suchbegriff zu kurz (min 2 Zeichen)', 'QUERY_TOO_SHORT');
        }

        return KnowledgeRepository::searchEntries($spaceId, $query);
    }

    // ── Knowledge for a message ──────────────────────────

    public static function forMessage(int $messageId, int $userId): array
    {
        $msg = MessageRepository::findBasic($messageId);
        if (!$msg) {
            throw ApiException::notFound('Nachricht nicht gefunden', 'MESSAGE_NOT_FOUND');
        }
        return KnowledgeRepository::knowledgeForMessage($messageId);
    }

    // ── Async Generation (dispatches jobs) ───────────────

    /**
     * Dispatch async thread summary generation.
     */
    public static function requestThreadSummary(int $threadId, int $userId): array
    {
        $thread = ThreadRepository::find($threadId);
        if (!$thread) {
            throw ApiException::notFound('Thread nicht gefunden', 'THREAD_NOT_FOUND');
        }

        $spaceId = self::spaceIdForThread($thread);
        self::requireSpaceMember($spaceId, $userId);

        JobService::dispatch(
            'knowledge.summarize_thread',
            [
                'thread_id' => $threadId,
                'space_id' => $spaceId,
                'user_id' => $userId,
            ],
            'default',
            3,
            80,
            "knowledge.thread.{$threadId}"
        );

        return ['status' => 'queued', 'thread_id' => $threadId];
    }

    /**
     * Dispatch async channel summary generation.
     */
    public static function requestChannelSummary(int $channelId, int $userId, ?string $periodStart = null, ?string $periodEnd = null): array
    {
        $channel = ChannelRepository::find($channelId);
        if (!$channel) {
            throw ApiException::notFound('Channel nicht gefunden', 'CHANNEL_NOT_FOUND');
        }
        self::requireSpaceMember($channel['space_id'], $userId);

        $payload = [
            'channel_id' => $channelId,
            'space_id' => $channel['space_id'],
            'user_id' => $userId,
        ];
        if ($periodStart)
            $payload['period_start'] = $periodStart;
        if ($periodEnd)
            $payload['period_end'] = $periodEnd;

        $idempotencyKey = "knowledge.channel.{$channelId}." . date('Y-m-d');

        JobService::dispatch(
            'knowledge.summarize_channel',
            $payload,
            'default',
            3,
            80,
            $idempotencyKey
        );

        return ['status' => 'queued', 'channel_id' => $channelId];
    }

    /**
     * Dispatch async knowledge extraction from recent messages.
     */
    public static function requestExtraction(int $spaceId, int $userId, ?int $channelId = null): array
    {
        self::requireSpaceAdmin($spaceId, $userId);

        $payload = [
            'space_id' => $spaceId,
            'user_id' => $userId,
        ];
        if ($channelId)
            $payload['channel_id'] = $channelId;

        $scope = $channelId ? "channel.{$channelId}" : "space.{$spaceId}";
        JobService::dispatch(
            'knowledge.extract',
            $payload,
            'default',
            3,
            90,
            "knowledge.extract.{$scope}." . date('Y-m-d')
        );

        return ['status' => 'queued', 'scope' => $scope];
    }

    // ── Helpers ──────────────────────────────────────────

    private static function slugify(string $text): string
    {
        $text = mb_strtolower($text);
        $text = preg_replace('/[^\p{L}\p{N}\s-]/u', '', $text);
        $text = preg_replace('/[\s-]+/', '-', $text);
        return trim($text, '-');
    }

    private static function requireSpaceMember(int $spaceId, int $userId): void
    {
        $db = Database::connection();
        $stmt = $db->prepare('SELECT 1 FROM space_members WHERE space_id = ? AND user_id = ?');
        $stmt->execute([$spaceId, $userId]);
        if (!$stmt->fetch()) {
            throw ApiException::forbidden('Kein Mitglied dieses Space', 'SPACE_MEMBER_REQUIRED');
        }
    }

    private static function requireSpaceAdmin(int $spaceId, int $userId): void
    {
        $db = Database::connection();
        $stmt = $db->prepare("SELECT role FROM space_members WHERE space_id = ? AND user_id = ?");
        $stmt->execute([$spaceId, $userId]);
        $row = $stmt->fetch();
        if (!$row || !in_array($row['role'], ['owner', 'admin'], true)) {
            throw ApiException::forbidden('Admin-Berechtigung erforderlich', 'ADMIN_REQUIRED');
        }
    }

    private static function spaceIdForThread(array $thread): int
    {
        if (!empty($thread['channel_id'])) {
            $ch = ChannelRepository::find($thread['channel_id']);
            return $ch ? (int) $ch['space_id'] : 0;
        }
        return 0;
    }
}

<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Services\KnowledgeService;
use App\Support\Request;
use App\Support\Response;

/**
 * REST endpoints for the Knowledge Layer.
 *
 * Topics, Decisions, Summaries, Entries, Search, and async generation triggers.
 */
final class KnowledgeController
{
    // ── Topics ───────────────────────────────────────────

    /** GET /api/spaces/{spaceId}/knowledge/topics */
    public function listTopics(int $spaceId): void
    {
        $userId = Request::requireUserId();
        $channelId = Request::queryInt('channel_id') ?: null;
        Response::json(KnowledgeService::listTopics($spaceId, $userId, $channelId));
    }

    /** POST /api/spaces/{spaceId}/knowledge/topics */
    public function createTopic(int $spaceId): void
    {
        $userId = Request::requireUserId();
        $topic = KnowledgeService::createTopic($spaceId, $userId, Request::json());
        Response::json($topic, 201);
    }

    /** GET /api/knowledge/topics/{topicId} */
    public function getTopic(int $topicId): void
    {
        $userId = Request::requireUserId();
        Response::json(KnowledgeService::getTopic($topicId, $userId));
    }

    /** PUT /api/knowledge/topics/{topicId} */
    public function updateTopic(int $topicId): void
    {
        $userId = Request::requireUserId();
        Response::json(KnowledgeService::updateTopic($topicId, $userId, Request::json()));
    }

    /** DELETE /api/knowledge/topics/{topicId} */
    public function deleteTopic(int $topicId): void
    {
        $userId = Request::requireUserId();
        KnowledgeService::deleteTopic($topicId, $userId);
        Response::json(['deleted' => true]);
    }

    // ── Decisions ────────────────────────────────────────

    /** GET /api/spaces/{spaceId}/knowledge/decisions */
    public function listDecisions(int $spaceId): void
    {
        $userId = Request::requireUserId();
        $topicId = Request::queryInt('topic_id') ?: null;
        $status = Request::query('status');
        Response::json(KnowledgeService::listDecisions($spaceId, $userId, $topicId, $status));
    }

    /** POST /api/spaces/{spaceId}/knowledge/decisions */
    public function createDecision(int $spaceId): void
    {
        $userId = Request::requireUserId();
        $decision = KnowledgeService::createDecision($spaceId, $userId, Request::json());
        Response::json($decision, 201);
    }

    /** PUT /api/knowledge/decisions/{decisionId} */
    public function updateDecision(int $decisionId): void
    {
        $userId = Request::requireUserId();
        Response::json(KnowledgeService::updateDecision($decisionId, $userId, Request::json()));
    }

    /** DELETE /api/knowledge/decisions/{decisionId} */
    public function deleteDecision(int $decisionId): void
    {
        $userId = Request::requireUserId();
        KnowledgeService::deleteDecision($decisionId, $userId);
        Response::json(['deleted' => true]);
    }

    // ── Summaries ────────────────────────────────────────

    /** GET /api/spaces/{spaceId}/knowledge/summaries */
    public function listSummaries(int $spaceId): void
    {
        $userId = Request::requireUserId();
        $scopeType = Request::query('scope_type');
        $scopeId = Request::queryInt('scope_id') ?: null;
        Response::json(KnowledgeService::listSummaries($spaceId, $userId, $scopeType, $scopeId));
    }

    /** GET /api/knowledge/summaries/{summaryId} */
    public function getSummary(int $summaryId): void
    {
        $userId = Request::requireUserId();
        Response::json(KnowledgeService::getSummary($summaryId, $userId));
    }

    /** DELETE /api/knowledge/summaries/{summaryId} */
    public function deleteSummary(int $summaryId): void
    {
        $userId = Request::requireUserId();
        KnowledgeService::deleteSummary($summaryId, $userId);
        Response::json(['deleted' => true]);
    }

    // ── Knowledge Entries ────────────────────────────────

    /** GET /api/spaces/{spaceId}/knowledge/entries */
    public function listEntries(int $spaceId): void
    {
        $userId = Request::requireUserId();
        $topicId = Request::queryInt('topic_id') ?: null;
        $type = Request::query('type');
        Response::json(KnowledgeService::listEntries($spaceId, $userId, $topicId, $type));
    }

    /** POST /api/spaces/{spaceId}/knowledge/entries */
    public function createEntry(int $spaceId): void
    {
        $userId = Request::requireUserId();
        $entry = KnowledgeService::createEntry($spaceId, $userId, Request::json());
        Response::json($entry, 201);
    }

    /** GET /api/knowledge/entries/{entryId} */
    public function getEntry(int $entryId): void
    {
        $userId = Request::requireUserId();
        Response::json(KnowledgeService::getEntry($entryId, $userId));
    }

    /** PUT /api/knowledge/entries/{entryId} */
    public function updateEntry(int $entryId): void
    {
        $userId = Request::requireUserId();
        Response::json(KnowledgeService::updateEntry($entryId, $userId, Request::json()));
    }

    /** DELETE /api/knowledge/entries/{entryId} */
    public function deleteEntry(int $entryId): void
    {
        $userId = Request::requireUserId();
        KnowledgeService::deleteEntry($entryId, $userId);
        Response::json(['deleted' => true]);
    }

    // ── Search ───────────────────────────────────────────

    /** GET /api/spaces/{spaceId}/knowledge/search?q=... */
    public function search(int $spaceId): void
    {
        $userId = Request::requireUserId();
        $q = trim(Request::query('q', ''));
        Response::json(KnowledgeService::search($spaceId, $userId, $q));
    }

    // ── Message knowledge link ───────────────────────────

    /** GET /api/messages/{messageId}/knowledge */
    public function forMessage(int $messageId): void
    {
        $userId = Request::requireUserId();
        Response::json(KnowledgeService::forMessage($messageId, $userId));
    }

    // ── Async Generation Triggers ────────────────────────

    /** POST /api/threads/{threadId}/knowledge/summarize */
    public function summarizeThread(int $threadId): void
    {
        $userId = Request::requireUserId();
        Response::json(KnowledgeService::requestThreadSummary($threadId, $userId), 202);
    }

    /** POST /api/channels/{channelId}/knowledge/summarize */
    public function summarizeChannel(int $channelId): void
    {
        $userId = Request::requireUserId();
        $input = Request::json();
        $periodStart = $input['period_start'] ?? null;
        $periodEnd = $input['period_end'] ?? null;
        Response::json(KnowledgeService::requestChannelSummary($channelId, $userId, $periodStart, $periodEnd), 202);
    }

    /** POST /api/spaces/{spaceId}/knowledge/extract */
    public function extract(int $spaceId): void
    {
        $userId = Request::requireUserId();
        $channelId = isset(Request::json()['channel_id']) ? (int) Request::json()['channel_id'] : null;
        Response::json(KnowledgeService::requestExtraction($spaceId, $userId, $channelId), 202);
    }
}

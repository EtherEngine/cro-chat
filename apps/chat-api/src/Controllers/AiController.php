<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Services\AiService;
use App\Support\Request;
use App\Support\Response;

/**
 * REST endpoints for AI Features.
 *
 * Summaries, Action Items, Semantic Search, Reply Suggestions, Config.
 */
final class AiController
{
    // ── Config ───────────────────────────────────────────────

    /** GET /api/spaces/{spaceId}/ai/config */
    public function getConfig(int $spaceId): void
    {
        $userId = Request::requireUserId();
        Response::json(AiService::getConfig($spaceId, $userId));
    }

    /** PUT /api/spaces/{spaceId}/ai/config */
    public function updateConfig(int $spaceId): void
    {
        $userId = Request::requireUserId();
        Response::json(AiService::updateConfig($spaceId, $userId, Request::json()));
    }

    // ── Summaries ────────────────────────────────────────────

    /** GET /api/spaces/{spaceId}/ai/summaries */
    public function listSummaries(int $spaceId): void
    {
        $userId = Request::requireUserId();
        $scopeType = Request::query('scope_type') ?: null;
        $scopeId = Request::queryInt('scope_id') ?: null;
        Response::json(AiService::listSummaries($spaceId, $userId, $scopeType, $scopeId));
    }

    /** GET /api/ai/summaries/{summaryId} */
    public function getSummary(int $summaryId): void
    {
        $userId = Request::requireUserId();
        Response::json(AiService::getSummary($summaryId, $userId));
    }

    /** DELETE /api/ai/summaries/{summaryId} */
    public function deleteSummary(int $summaryId): void
    {
        $userId = Request::requireUserId();
        AiService::deleteSummary($summaryId, $userId);
        Response::json(['deleted' => true]);
    }

    // ── Sync Summarize (direct, non-async) ───────────────────

    /** POST /api/threads/{threadId}/ai/summarize */
    public function summarizeThread(int $threadId): void
    {
        $userId = Request::requireUserId();
        $input = Request::json();
        $spaceId = (int) ($input['space_id'] ?? 0);
        if ($spaceId <= 0) {
            // Resolve space from thread → channel
            $thread = \App\Repositories\ThreadRepository::find($threadId);
            $channel = $thread ? \App\Repositories\ChannelRepository::find((int) $thread['channel_id']) : null;
            $spaceId = $channel ? (int) $channel['space_id'] : 0;
        }
        $summary = AiService::summarizeThread($threadId, $spaceId, $userId);
        Response::json($summary, 201);
    }

    /** POST /api/channels/{channelId}/ai/summarize */
    public function summarizeChannel(int $channelId): void
    {
        $userId = Request::requireUserId();
        $input = Request::json();
        $spaceId = (int) ($input['space_id'] ?? 0);
        if ($spaceId <= 0) {
            $channel = \App\Repositories\ChannelRepository::find($channelId);
            $spaceId = $channel ? (int) $channel['space_id'] : 0;
        }
        $periodStart = $input['period_start'] ?? null;
        $periodEnd = $input['period_end'] ?? null;
        $summary = AiService::summarizeChannel($channelId, $spaceId, $userId, $periodStart, $periodEnd);
        Response::json($summary, 201);
    }

    // ── Async Triggers (202 Accepted) ────────────────────────

    /** POST /api/threads/{threadId}/ai/summarize-async */
    public function summarizeThreadAsync(int $threadId): void
    {
        $userId = Request::requireUserId();
        $job = AiService::requestThreadSummary($threadId, $userId);
        Response::json(['queued' => true, 'job' => $job], 202);
    }

    /** POST /api/channels/{channelId}/ai/summarize-async */
    public function summarizeChannelAsync(int $channelId): void
    {
        $userId = Request::requireUserId();
        $job = AiService::requestChannelSummary($channelId, $userId);
        Response::json(['queued' => true, 'job' => $job], 202);
    }

    /** POST /api/spaces/{spaceId}/ai/extract */
    public function extract(int $spaceId): void
    {
        $userId = Request::requireUserId();
        $input = Request::json();
        $channelId = isset($input['channel_id']) ? (int) $input['channel_id'] : null;
        $job = AiService::requestExtraction($spaceId, $userId, $channelId);
        Response::json(['queued' => true, 'job' => $job], 202);
    }

    /** POST /api/spaces/{spaceId}/ai/embed */
    public function embed(int $spaceId): void
    {
        $userId = Request::requireUserId();
        $input = Request::json();
        $channelId = isset($input['channel_id']) ? (int) $input['channel_id'] : null;
        $job = AiService::requestEmbedding($spaceId, $userId, $channelId);
        Response::json(['queued' => true, 'job' => $job], 202);
    }

    // ── Action Items ─────────────────────────────────────────

    /** GET /api/spaces/{spaceId}/ai/action-items */
    public function listActionItems(int $spaceId): void
    {
        $userId = Request::requireUserId();
        $status = Request::query('status') ?: null;
        Response::json(AiService::listActionItems($spaceId, $userId, $status));
    }

    /** PUT /api/ai/action-items/{actionItemId} */
    public function updateActionItem(int $actionItemId): void
    {
        $userId = Request::requireUserId();
        $input = Request::json();
        $status = $input['status'] ?? '';
        Response::json(AiService::updateActionItemStatus($actionItemId, $userId, $status));
    }

    // ── Semantic Search ──────────────────────────────────────

    /** GET /api/spaces/{spaceId}/ai/search */
    public function search(int $spaceId): void
    {
        $userId = Request::requireUserId();
        $query = Request::query('q') ?? '';
        $limit = Request::queryInt('limit', 20);
        Response::json(AiService::semanticSearch($spaceId, $userId, $query, $limit));
    }

    // ── Reply Suggestions ────────────────────────────────────

    /** POST /api/spaces/{spaceId}/ai/suggest */
    public function suggest(int $spaceId): void
    {
        $userId = Request::requireUserId();
        $input = Request::json();
        $scopeType = $input['scope_type'] ?? '';
        $scopeId = (int) ($input['scope_id'] ?? 0);
        $contextMessageId = isset($input['context_message_id']) ? (int) $input['context_message_id'] : null;
        Response::json(AiService::suggest($spaceId, $userId, $scopeType, $scopeId, $contextMessageId));
    }

    /** POST /api/ai/suggestions/{suggestionId}/accept */
    public function acceptSuggestion(int $suggestionId): void
    {
        $userId = Request::requireUserId();
        $input = Request::json();
        $index = (int) ($input['index'] ?? 0);
        Response::json(AiService::acceptSuggestion($suggestionId, $userId, $index));
    }

    // ── Stats ────────────────────────────────────────────────

    /** GET /api/spaces/{spaceId}/ai/stats */
    public function stats(int $spaceId): void
    {
        $userId = Request::requireUserId();
        Response::json(AiService::stats($spaceId, $userId));
    }
}

<?php

namespace App\Controllers;

use App\Repositories\SearchRepository;
use App\Services\SearchService;
use App\Support\Request;
use App\Support\Response;

final class SearchController
{
    /**
     * GET /api/search?q=...&type=all|channels|users|messages
     *     &channel_id=...&conversation_id=...&user_id=...&after=...&before=...
     */
    public function search(): void
    {
        $userId = Request::requireUserId();

        $q = trim($_GET['q'] ?? '');
        if ($q === '' || mb_strlen($q) < 2) {
            Response::json(['channels' => [], 'users' => [], 'messages' => []]);
            return;
        }

        $type = $_GET['type'] ?? 'all';
        $result = [];

        if ($type === 'all' || $type === 'channels') {
            $result['channels'] = SearchRepository::channels($userId, $q);
        }

        if ($type === 'all' || $type === 'users') {
            $result['users'] = SearchRepository::users($userId, $q);
        }

        if ($type === 'all' || $type === 'messages') {
            $channelId = isset($_GET['channel_id']) ? (int) $_GET['channel_id'] : null;
            $conversationId = isset($_GET['conversation_id']) ? (int) $_GET['conversation_id'] : null;
            $authorId = isset($_GET['user_id']) ? (int) $_GET['user_id'] : null;
            $after = isset($_GET['after']) ? $_GET['after'] : null;
            $before = isset($_GET['before']) ? $_GET['before'] : null;

            $result['messages'] = SearchRepository::messages(
                $userId,
                $q,
                $channelId,
                $conversationId,
                $authorId,
                $after,
                $before
            );
        }

        Response::json($result);
    }

    // ── Advanced Search ──────────────────────────

    /**
     * GET /api/search/advanced?q=...&channel_id=...&author_id=...&after=...&before=...
     *     &has_attachment=1&has_reaction=1&in_thread=1&sort=relevance|newest|oldest
     *     &page=1&per_page=30
     */
    public function advanced(): void
    {
        $userId = Request::requireUserId();
        $q = trim($_GET['q'] ?? '');

        $filters = [];
        foreach (['channel_id', 'conversation_id', 'author_id'] as $key) {
            if (isset($_GET[$key])) {
                $filters[$key] = (int) $_GET[$key];
            }
        }
        foreach (['after', 'before', 'sort'] as $key) {
            if (isset($_GET[$key])) {
                $filters[$key] = $_GET[$key];
            }
        }
        foreach (['has_attachment', 'has_reaction', 'in_thread'] as $key) {
            if (!empty($_GET[$key])) {
                $filters[$key] = true;
            }
        }
        if (isset($_GET['page'])) {
            $filters['page'] = (int) $_GET['page'];
        }
        if (isset($_GET['per_page'])) {
            $filters['per_page'] = (int) $_GET['per_page'];
        }

        $result = SearchService::advancedSearch($userId, $q, $filters);
        Response::json($result);
    }

    // ── Saved Searches ───────────────────────────

    /** GET /api/search/saved?space_id=... */
    public function listSaved(): void
    {
        $userId = Request::requireUserId();
        $spaceId = isset($_GET['space_id']) ? (int) $_GET['space_id'] : null;
        Response::json(['saved_searches' => SearchService::listSavedSearches($userId, $spaceId)]);
    }

    /** POST /api/search/saved  body: { space_id, name, query, filters?, notify? } */
    public function createSaved(): void
    {
        $userId = Request::requireUserId();
        $input = Request::json();
        $spaceId = (int) ($input['space_id'] ?? 0);
        $saved = SearchService::createSavedSearch($userId, $spaceId, $input);
        Response::json(['saved_search' => $saved], 201);
    }

    /** GET /api/search/saved/{savedSearchId} */
    public function getSaved(array $params): void
    {
        $userId = Request::requireUserId();
        $saved = SearchService::getSavedSearch((int) $params['savedSearchId'], $userId);
        Response::json(['saved_search' => $saved]);
    }

    /** PUT /api/search/saved/{savedSearchId} */
    public function updateSaved(array $params): void
    {
        $userId = Request::requireUserId();
        $input = Request::json();
        $saved = SearchService::updateSavedSearch((int) $params['savedSearchId'], $userId, $input);
        Response::json(['saved_search' => $saved]);
    }

    /** DELETE /api/search/saved/{savedSearchId} */
    public function deleteSaved(array $params): void
    {
        $userId = Request::requireUserId();
        SearchService::deleteSavedSearch((int) $params['savedSearchId'], $userId);
        Response::json(['deleted' => true]);
    }

    /** POST /api/search/saved/{savedSearchId}/execute?page=...&sort=... */
    public function executeSaved(array $params): void
    {
        $userId = Request::requireUserId();
        $overrides = [];
        if (isset($_GET['page'])) {
            $overrides['page'] = (int) $_GET['page'];
        }
        if (isset($_GET['sort'])) {
            $overrides['sort'] = $_GET['sort'];
        }
        $result = SearchService::executeSavedSearch((int) $params['savedSearchId'], $userId, $overrides);
        Response::json($result);
    }

    // ── History & Suggest ────────────────────────

    /** GET /api/search/history?limit=20 */
    public function history(): void
    {
        $userId = Request::requireUserId();
        $limit = isset($_GET['limit']) ? (int) $_GET['limit'] : 20;
        Response::json(['history' => SearchService::history($userId, $limit)]);
    }

    /** DELETE /api/search/history */
    public function clearHistory(): void
    {
        $userId = Request::requireUserId();
        SearchService::clearHistory($userId);
        Response::json(['cleared' => true]);
    }

    /** GET /api/search/suggest?q=... */
    public function suggest(): void
    {
        $userId = Request::requireUserId();
        $q = trim($_GET['q'] ?? '');
        Response::json(['suggestions' => SearchService::suggest($userId, $q)]);
    }
}

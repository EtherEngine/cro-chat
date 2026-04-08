<?php

namespace App\Controllers;

use App\Repositories\SearchRepository;
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
}

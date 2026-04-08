<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Exceptions\ApiException;
use App\Repositories\SpaceRepository;
use App\Services\MentionService;
use App\Support\Request;
use App\Support\Response;

final class MentionController
{
    /**
     * GET /api/mentions/search?q=...&space_id=...&channel_id=...&conversation_id=...
     */
    public function search(): void
    {
        $userId = Request::requireUserId();

        $q = trim($_GET['q'] ?? '');
        if ($q === '' || mb_strlen($q) < 1) {
            Response::json([]);
            return;
        }

        $spaceId = isset($_GET['space_id']) ? (int) $_GET['space_id'] : null;
        $channelId = isset($_GET['channel_id']) ? (int) $_GET['channel_id'] : null;
        $conversationId = isset($_GET['conversation_id']) ? (int) $_GET['conversation_id'] : null;

        if ($spaceId === null && $channelId === null && $conversationId === null) {
            throw ApiException::validation('space_id, channel_id oder conversation_id erforderlich', 'MENTION_CONTEXT_MISSING');
        }

        // Ensure caller is a space member (basic authorization)
        if ($spaceId !== null && !SpaceRepository::isMember($spaceId, $userId)) {
            throw ApiException::forbidden('Kein Space-Mitglied', 'SPACE_ACCESS_DENIED');
        }

        $limit = isset($_GET['limit']) ? min((int) $_GET['limit'], 20) : 10;

        $users = MentionService::searchUsers(
            $q,
            $spaceId ?? 0,
            $channelId,
            $conversationId,
            $limit
        );

        Response::json($users);
    }
}

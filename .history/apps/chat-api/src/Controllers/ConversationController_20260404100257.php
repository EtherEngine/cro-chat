<?php

namespace App\Controllers;

use App\Services\ConversationService;
use App\Support\Request;
use App\Support\Response;
use App\Support\Validator;

final class ConversationController
{
    /** GET /api/conversations */
    public function index(): void
    {
        $userId = Request::requireUserId();
        $conversations = ConversationService::listForUser($userId);
        Response::json(['conversations' => $conversations]);
    }

    /** GET /api/conversations/{conversationId} */
    public function show(array $params): void
    {
        $userId = Request::requireUserId();
        $conversation = ConversationService::show((int) $params['conversationId'], $userId);
        Response::json(['conversation' => $conversation]);
    }

    /** GET /api/conversations/{conversationId}/members */
    public function members(array $params): void
    {
        $userId = Request::requireUserId();
        $members = ConversationService::members((int) $params['conversationId'], $userId);
        Response::json(['members' => $members]);
    }

    /**
     * POST /api/conversations
     * Body: { user_id, space_id }           → 1:1 DM (get-or-create)
     * Body: { user_ids[], space_id }         → group DM
     */
    public function create(): void
    {
        $userId = Request::requireUserId();
        $input = Request::json();
        (new Validator($input))->required('space_id')->validate();

        $spaceId = (int) $input['space_id'];

        // Group DM
        if (isset($input['user_ids']) && is_array($input['user_ids'])) {
            $conversation = ConversationService::createGroup(
                $spaceId,
                $userId,
                array_map('intval', $input['user_ids'])
            );
            Response::json(['conversation' => $conversation], 201);
        }

        // 1:1 DM
        if (!isset($input['user_id'])) {
            Response::error('user_id oder user_ids[] ist erforderlich', 422);
        }

        $conversation = ConversationService::getOrCreateDirect(
            $spaceId,
            $userId,
            (int) $input['user_id']
        );
        Response::json(['conversation' => $conversation], 201);
    }
}

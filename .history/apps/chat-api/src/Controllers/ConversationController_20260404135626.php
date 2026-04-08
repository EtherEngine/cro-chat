<?php

namespace App\Controllers;

use App\Exceptions\ApiException;
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
            $title = isset($input['title']) ? trim((string) $input['title']) : '';
            $conversation = ConversationService::createGroup(
                $spaceId,
                $userId,
                array_map('intval', $input['user_ids']),
                $title
            );
            Response::json(['conversation' => $conversation], 201);
        }

        // 1:1 DM
        if (!isset($input['user_id'])) {
            throw ApiException::validation('user_id oder user_ids[] ist erforderlich', 'MISSING_USER_ID');
        }

        $conversation = ConversationService::getOrCreateDirect(
            $spaceId,
            $userId,
            (int) $input['user_id']
        );
        Response::json(['conversation' => $conversation], 201);
    }

    /** PUT /api/conversations/{conversationId} */
    public function update(array $params): void
    {
        $userId = Request::requireUserId();
        $input = Request::json();
        $convId = (int) $params['conversationId'];

        if (isset($input['title'])) {
            (new Validator($input))->maxLength('title', 150)->validate();
            $conversation = ConversationService::rename($convId, $userId, trim((string) $input['title']));
            Response::json(['conversation' => $conversation]);
            return;
        }

        if (isset($input['avatar_url'])) {
            (new Validator($input))->maxLength('avatar_url', 500)->validate();
            $conversation = ConversationService::updateAvatar($convId, $userId, trim((string) $input['avatar_url']));
            Response::json(['conversation' => $conversation]);
            return;
        }

        throw ApiException::validation('title oder avatar_url ist erforderlich', 'MISSING_FIELD');
    }

    /** POST /api/conversations/{conversationId}/members */
    public function addMember(array $params): void
    {
        $userId = Request::requireUserId();
        $input = Request::json();
        (new Validator($input))->required('user_id')->validate();

        $conversation = ConversationService::addMember(
            (int) $params['conversationId'],
            $userId,
            (int) $input['user_id']
        );
        Response::json(['conversation' => $conversation]);
    }

    /** DELETE /api/conversations/{conversationId}/members/{userId} */
    public function removeMember(array $params): void
    {
        $actingUserId = Request::requireUserId();
        $result = ConversationService::removeMember(
            (int) $params['conversationId'],
            $actingUserId,
            (int) $params['userId']
        );
        Response::json($result);
    }

    /** POST /api/conversations/{conversationId}/leave */
    public function leave(array $params): void
    {
        $userId = Request::requireUserId();
        $result = ConversationService::removeMember(
            (int) $params['conversationId'],
            $userId,
            $userId
        );
        Response::json($result);
    }
}

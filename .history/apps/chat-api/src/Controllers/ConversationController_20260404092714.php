<?php

namespace App\Controllers;

use App\Services\ConversationService;
use App\Support\Request;
use App\Support\Response;
use App\Support\Validator;

final class ConversationController
{
    public function index(): void
    {
        $userId = Request::requireUserId();
        $conversations = ConversationService::listForUser($userId);
        Response::json(['conversations' => $conversations]);
    }

    public function show(array $params): void
    {
        $userId = Request::requireUserId();
        $conversation = ConversationService::show((int) $params['conversationId'], $userId);
        Response::json(['conversation' => $conversation]);
    }

    public function create(): void
    {
        $userId = Request::requireUserId();
        $input = Request::json();
        (new Validator($input))->required('user_id')->required('space_id')->validate();

        $conversation = ConversationService::create(
            (int) $input['space_id'],
            $userId,
            [(int) $input['user_id']]
        );
        Response::json(['conversation' => $conversation], 201);
    }
}

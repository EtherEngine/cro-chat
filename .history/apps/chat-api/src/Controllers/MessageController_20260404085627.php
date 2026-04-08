<?php

namespace App\Controllers;

use App\Repositories\MessageRepository;
use App\Support\Request;
use App\Support\Response;

final class MessageController
{
    public function listChannelMessages(array $params): void
    {
        $userId = Request::userId();
        if (!$userId)
            Response::error('Nicht eingeloggt', 401);

        $channelId = (int) $params['channelId'];
        $messages = MessageRepository::forChannel($channelId);
        Response::json(['messages' => $messages]);
    }

    public function createChannelMessage(array $params): void
    {
        $userId = Request::userId();
        if (!$userId)
            Response::error('Nicht eingeloggt', 401);

        $input = Request::json();
        $body = trim($input['body'] ?? '');

        if ($body === '') {
            Response::error('Nachricht darf nicht leer sein', 422);
        }

        $channelId = (int) $params['channelId'];
        $message = MessageRepository::create($userId, $body, $channelId, null);
        Response::json(['message' => $message]);
    }

    public function listConversationMessages(array $params): void
    {
        $userId = Request::userId();
        if (!$userId)
            Response::error('Nicht eingeloggt', 401);

        $conversationId = (int) $params['conversationId'];
        $messages = MessageRepository::forConversation($conversationId);
        Response::json(['messages' => $messages]);
    }

    public function createConversationMessage(array $params): void
    {
        $userId = Request::userId();
        if (!$userId)
            Response::error('Nicht eingeloggt', 401);

        $input = Request::json();
        $body = trim($input['body'] ?? '');

        if ($body === '') {
            Response::error('Nachricht darf nicht leer sein', 422);
        }

        $conversationId = (int) $params['conversationId'];
        $message = MessageRepository::create($userId, $body, null, $conversationId);
        Response::json(['message' => $message]);
    }
}
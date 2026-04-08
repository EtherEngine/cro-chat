<?php

namespace App\Controllers;

use App\Services\MessageService;
use App\Support\Request;
use App\Support\Response;
use App\Support\Validator;

final class MessageController
{
    public function listChannel(array $params): void
    {
        $userId = Request::requireUserId();
        $channelId = (int) $params['channelId'];
        $before = Request::query('before') ? (int) Request::query('before') : null;
        $messages = MessageService::listChannel($channelId, $userId, $before);
        Response::json(['messages' => $messages]);
    }

    public function createChannel(array $params): void
    {
        $userId = Request::requireUserId();
        $input = Request::json();
        (new Validator($input))->required('body')->maxLength('body', 10000)->validate();

        $channelId = (int) $params['channelId'];
        $message = MessageService::createChannel($channelId, $userId, trim($input['body']));
        Response::json(['message' => $message], 201);
    }

    public function listConversation(array $params): void
    {
        $userId = Request::requireUserId();
        $conversationId = (int) $params['conversationId'];
        $before = Request::query('before') ? (int) Request::query('before') : null;
        $messages = MessageService::listConversation($conversationId, $userId, $before);
        Response::json(['messages' => $messages]);
    }

    public function createConversation(array $params): void
    {
        $userId = Request::requireUserId();
        $input = Request::json();
        (new Validator($input))->required('body')->maxLength('body', 10000)->validate();

        $conversationId = (int) $params['conversationId'];
        $message = MessageService::createConversation($conversationId, $userId, trim($input['body']));
        Response::json(['message' => $message], 201);
    }

    public function update(array $params): void
    {
        $userId = Request::requireUserId();
        $input = Request::json();
        (new Validator($input))->required('body')->maxLength('body', 10000)->validate();

        $message = MessageService::update((int) $params['messageId'], $userId, trim($input['body']));
        Response::json(['message' => $message]);
    }

    public function delete(array $params): void
    {
        $userId = Request::requireUserId();
        MessageService::delete((int) $params['messageId'], $userId);
        Response::json(['ok' => true]);
    }
}
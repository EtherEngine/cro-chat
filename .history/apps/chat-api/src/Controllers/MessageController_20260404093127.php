<?php

namespace App\Controllers;

use App\Services\MessageService;
use App\Support\Request;
use App\Support\Response;

final class MessageController
{
    /**
     * GET /channels/{channelId}/messages?before=&after=&limit=
     * Returns cursor-paginated messages.
     */
    public function listChannel(array $params): void
    {
        $userId  = Request::requireUserId();
        $before  = Request::query('before') ? (int) Request::query('before') : null;
        $after   = Request::query('after')  ? (int) Request::query('after')  : null;
        $limit   = Request::query('limit')  ? (int) Request::query('limit')  : 50;

        $result = MessageService::listChannel(
            (int) $params['channelId'], $userId, $before, $after, $limit
        );
        Response::json($result);
    }

    /**
     * POST /channels/{channelId}/messages
     * Body: { body, reply_to_id?, idempotency_key? }
     */
    public function createChannel(array $params): void
    {
        $userId = Request::requireUserId();
        $input  = Request::json();

        $message = MessageService::createChannel(
            (int) $params['channelId'], $userId, $input
        );
        Response::json(['message' => $message], 201);
    }

    /**
     * GET /conversations/{conversationId}/messages?before=&after=&limit=
     */
    public function listConversation(array $params): void
    {
        $userId = Request::requireUserId();
        $before = Request::query('before') ? (int) Request::query('before') : null;
        $after  = Request::query('after')  ? (int) Request::query('after')  : null;
        $limit  = Request::query('limit')  ? (int) Request::query('limit')  : 50;

        $result = MessageService::listConversation(
            (int) $params['conversationId'], $userId, $before, $after, $limit
        );
        Response::json($result);
    }

    /**
     * POST /conversations/{conversationId}/messages
     * Body: { body, reply_to_id?, idempotency_key? }
     */
    public function createConversation(array $params): void
    {
        $userId = Request::requireUserId();
        $input  = Request::json();

        $message = MessageService::createConversation(
            (int) $params['conversationId'], $userId, $input
        );
        Response::json(['message' => $message], 201);
    }

    /**
     * PUT /messages/{messageId}
     * Body: { body }
     */
    public function update(array $params): void
    {
        $userId  = Request::requireUserId();
        $input   = Request::json();

        $message = MessageService::update(
            (int) $params['messageId'], $userId, $input
        );
        Response::json(['message' => $message]);
    }

    /**
     * DELETE /messages/{messageId}
     */
    public function delete(array $params): void
    {
        $userId = Request::requireUserId();
        MessageService::delete((int) $params['messageId'], $userId);
        Response::json(['ok' => true]);
    }

    /**
     * GET /messages/{messageId}/history
     * Returns the edit history for a message.
     */
    public function history(array $params): void
    {
        $userId  = Request::requireUserId();
        $history = MessageService::editHistory((int) $params['messageId'], $userId);
        Response::json(['edits' => $history]);
    }
}
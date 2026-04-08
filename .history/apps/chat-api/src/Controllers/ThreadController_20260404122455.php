<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Middleware\RateLimitMiddleware;
use App\Services\ThreadService;
use App\Support\Request;
use App\Support\Response;
use App\Support\Validator;

final class ThreadController
{
    /**
     * POST /messages/{messageId}/thread
     * Body: { body }
     * Starts or continues a thread on a message.
     */
    public function startThread(array $params): void
    {
        $userId = Request::requireUserId();
        RateLimitMiddleware::check('message', (string) $userId, maxAttempts: 30, windowSeconds: 60);
        $input = Request::json();

        $result = ThreadService::startThread(
            (int) $params['messageId'],
            $userId,
            $input
        );
        Response::json($result, 201);
    }

    /**
     * GET /threads/{threadId}?before=&after=&limit=
     * Returns thread info + paginated replies.
     */
    public function show(array $params): void
    {
        $userId = Request::requireUserId();
        $before = Request::query('before') ? (int) Request::query('before') : null;
        $after = Request::query('after') ? (int) Request::query('after') : null;
        $limit = Request::query('limit') ? (int) Request::query('limit') : 50;

        $result = ThreadService::getThread(
            (int) $params['threadId'],
            $userId,
            $before,
            $after,
            $limit
        );
        Response::json($result);
    }

    /**
     * POST /threads/{threadId}/replies
     * Body: { body }
     */
    public function createReply(array $params): void
    {
        $userId = Request::requireUserId();
        RateLimitMiddleware::check('message', (string) $userId, maxAttempts: 30, windowSeconds: 60);
        $input = Request::json();

        $message = ThreadService::createReply(
            (int) $params['threadId'],
            $userId,
            $input
        );
        Response::json(['message' => $message], 201);
    }

    /**
     * POST /threads/{threadId}/read
     * Body: { message_id }
     */
    public function markRead(array $params): void
    {
        $userId = Request::requireUserId();
        $input = Request::json();
        (new Validator($input))->required('message_id')->validate();

        ThreadService::markRead(
            (int) $params['threadId'],
            $userId,
            (int) $input['message_id']
        );
        Response::json(['ok' => true]);
    }
}

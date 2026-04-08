<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Middleware\RateLimitMiddleware;
use App\Services\ReactionService;
use App\Support\Request;
use App\Support\Response;

final class ReactionController
{
    /**
     * POST /messages/{messageId}/reactions
     * Body: { emoji }
     * Returns aggregated reactions for the message.
     */
    public function add(array $params): void
    {
        $userId = Request::requireUserId();
        RateLimitMiddleware::check('reaction', (string) $userId, maxAttempts: 60, windowSeconds: 60);
        $input = Request::json();

        $reactions = ReactionService::add(
            (int) $params['messageId'],
            $userId,
            $input
        );
        Response::json(['reactions' => $reactions], 201);
    }

    /**
     * DELETE /messages/{messageId}/reactions
     * Body: { emoji }
     * Returns aggregated reactions for the message.
     */
    public function remove(array $params): void
    {
        $userId = Request::requireUserId();
        $input = Request::json();

        $reactions = ReactionService::remove(
            (int) $params['messageId'],
            $userId,
            $input
        );
        Response::json(['reactions' => $reactions]);
    }

    /**
     * GET /messages/{messageId}/reactions
     * Returns aggregated reactions for the message.
     */
    public function list(array $params): void
    {
        $userId = Request::requireUserId();

        $reactions = ReactionService::list(
            (int) $params['messageId'],
            $userId
        );
        Response::json(['reactions' => $reactions]);
    }
}

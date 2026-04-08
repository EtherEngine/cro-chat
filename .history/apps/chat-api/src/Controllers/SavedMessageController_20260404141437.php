<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Services\SavedMessageService;
use App\Support\Request;
use App\Support\Response;

final class SavedMessageController
{
    /** POST /api/messages/{messageId}/save */
    public function save(array $params): void
    {
        $userId = Request::requireUserId();
        $result = SavedMessageService::save((int) $params['messageId'], $userId);
        Response::json($result);
    }

    /** DELETE /api/messages/{messageId}/save */
    public function unsave(array $params): void
    {
        $userId = Request::requireUserId();
        $result = SavedMessageService::unsave((int) $params['messageId'], $userId);
        Response::json($result);
    }

    /** GET /api/saved-messages */
    public function index(): void
    {
        $userId = Request::requireUserId();
        $saved = SavedMessageService::forUser($userId);
        Response::json(['saved_messages' => $saved]);
    }
}

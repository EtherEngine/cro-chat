<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Services\PinService;
use App\Support\Request;
use App\Support\Response;

final class PinController
{
    /** POST /api/messages/{messageId}/pin */
    public function pin(array $params): void
    {
        $userId = Request::requireUserId();
        $result = PinService::pin((int) $params['messageId'], $userId);
        Response::json($result);
    }

    /** DELETE /api/messages/{messageId}/pin */
    public function unpin(array $params): void
    {
        $userId = Request::requireUserId();
        $result = PinService::unpin((int) $params['messageId'], $userId);
        Response::json($result);
    }

    /** GET /api/channels/{channelId}/pins */
    public function channelPins(array $params): void
    {
        $userId = Request::requireUserId();
        $pins = PinService::forChannel((int) $params['channelId'], $userId);
        Response::json(['pins' => $pins]);
    }

    /** GET /api/conversations/{conversationId}/pins */
    public function conversationPins(array $params): void
    {
        $userId = Request::requireUserId();
        $pins = PinService::forConversation((int) $params['conversationId'], $userId);
        Response::json(['pins' => $pins]);
    }
}

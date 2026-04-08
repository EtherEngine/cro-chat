<?php

namespace App\Controllers;

use App\Repositories\ChannelRepository;
use App\Repositories\ReadReceiptRepository;
use App\Services\ChannelService;
use App\Services\ConversationService;
use App\Support\Request;
use App\Support\Response;
use App\Support\Validator;

final class ReadReceiptController
{
    public function markChannelRead(array $params): void
    {
        $userId = Request::requireUserId();
        $channelId = (int) $params['channelId'];

        $channel = ChannelRepository::find($channelId);
        if (!$channel) {
            Response::error('Channel nicht gefunden', 404);
        }
        ChannelService::requireAccess($channel, $userId);

        $input = Request::json();
        (new Validator($input))->required('message_id')->validate();

        ReadReceiptRepository::markChannelRead($userId, $channelId, (int) $input['message_id']);
        Response::json(['ok' => true]);
    }

    public function markConversationRead(array $params): void
    {
        $userId = Request::requireUserId();
        $conversationId = (int) $params['conversationId'];

        ConversationService::requireMember($conversationId, $userId);

        $input = Request::json();
        (new Validator($input))->required('message_id')->validate();

        ReadReceiptRepository::markConversationRead($userId, $conversationId, (int) $input['message_id']);
        Response::json(['ok' => true]);
    }

    public function unreadCounts(): void
    {
        $userId = Request::requireUserId();
        $counts = ReadReceiptRepository::unreadCounts($userId);
        Response::json($counts);
    }
}

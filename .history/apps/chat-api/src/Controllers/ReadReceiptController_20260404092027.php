<?php

namespace App\Controllers;

use App\Repositories\ReadReceiptRepository;
use App\Support\Request;
use App\Support\Response;
use App\Support\Validator;

final class ReadReceiptController
{
    public function markChannelRead(array $params): void
    {
        $userId = Request::requireUserId();
        $input = Request::json();
        (new Validator($input))->required('message_id')->validate();

        ReadReceiptRepository::markChannelRead(
            $userId,
            (int) $params['channelId'],
            (int) $input['message_id']
        );
        Response::json(['ok' => true]);
    }

    public function markConversationRead(array $params): void
    {
        $userId = Request::requireUserId();
        $input = Request::json();
        (new Validator($input))->required('message_id')->validate();

        ReadReceiptRepository::markConversationRead(
            $userId,
            (int) $params['conversationId'],
            (int) $input['message_id']
        );
        Response::json(['ok' => true]);
    }

    public function unreadCounts(): void
    {
        $userId = Request::requireUserId();
        $counts = ReadReceiptRepository::unreadCounts($userId);
        Response::json($counts);
    }
}

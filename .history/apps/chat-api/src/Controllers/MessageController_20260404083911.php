<?php

namespace App\Controllers;

use App\Support\Request;
use App\Support\Response;

final class MessageController
{
    public function listChannelMessages(array $params): void
    {
        Response::json([
            'messages' => []
        ]);
    }

    public function createChannelMessage(array $params): void
    {
        $input = Request::json();

        if (trim($input['body'] ?? '') === '') {
            Response::error('Empty message', 422);
        }

        Response::json([
            'message' => [
                'id' => 1,
                'body' => $input['body']
            ]
        ]);
    }
}
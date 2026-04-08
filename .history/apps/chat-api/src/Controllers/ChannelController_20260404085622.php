<?php

namespace App\Controllers;

use App\Repositories\ChannelRepository;
use App\Repositories\ConversationRepository;
use App\Support\Request;
use App\Support\Response;

final class ChannelController
{
    public function index(): void
    {
        $userId = Request::userId();
        if (!$userId) Response::error('Nicht eingeloggt', 401);

        $channels = ChannelRepository::forUser($userId);
        Response::json(['channels' => $channels]);
    }

    public function members(array $params): void
    {
        $userId = Request::userId();
        if (!$userId) Response::error('Nicht eingeloggt', 401);

        $channelId = (int)$params['channelId'];
        $members = ChannelRepository::members($channelId);
        Response::json(['members' => $members]);
    }

    public function conversations(): void
    {
        $userId = Request::userId();
        if (!$userId) Response::error('Nicht eingeloggt', 401);

        $conversations = ConversationRepository::forUser($userId);
        Response::json(['conversations' => $conversations]);
    }
}


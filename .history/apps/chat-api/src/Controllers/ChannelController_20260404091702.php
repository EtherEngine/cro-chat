<?php

namespace App\Controllers;

use App\Services\ChannelService;
use App\Support\Request;
use App\Support\Response;
use App\Support\Validator;

final class ChannelController
{
    public function index(array $params): void
    {
        $userId = Request::requireUserId();
        $spaceId = (int) $params['spaceId'];
        $channels = ChannelService::listForSpace($spaceId, $userId);
        Response::json(['channels' => $channels]);
    }

    public function show(array $params): void
    {
        $userId = Request::requireUserId();
        $channel = ChannelService::show((int) $params['channelId'], $userId);
        Response::json(['channel' => $channel]);
    }

    public function create(array $params): void
    {
        $userId = Request::requireUserId();
        $spaceId = (int) $params['spaceId'];
        $input = Request::json();
        (new Validator($input))->required('name')->maxLength('name', 100)->validate();

        $channel = ChannelService::create(
            $spaceId,
            $input['name'],
            $input['description'] ?? '',
            $input['color'] ?? '#7C3AED',
            (bool) ($input['is_private'] ?? false),
            $userId
        );
        Response::json(['channel' => $channel], 201);
    }

    public function update(array $params): void
    {
        $userId = Request::requireUserId();
        $channel = ChannelService::update((int) $params['channelId'], Request::json(), $userId);
        Response::json(['channel' => $channel]);
    }

    public function delete(array $params): void
    {
        $userId = Request::requireUserId();
        ChannelService::delete((int) $params['channelId'], $userId);
        Response::json(['ok' => true]);
    }

    public function members(array $params): void
    {
        $userId = Request::requireUserId();
        $members = ChannelService::members((int) $params['channelId'], $userId);
        Response::json(['members' => $members]);
    }

    public function join(array $params): void
    {
        $userId = Request::requireUserId();
        ChannelService::join((int) $params['channelId'], $userId);
        Response::json(['ok' => true]);
    }

    public function addMember(array $params): void
    {
        $userId = Request::requireUserId();
        $input = Request::json();
        (new Validator($input))->required('user_id')->validate();
        ChannelService::addMember((int) $params['channelId'], (int) $input['user_id'], $userId);
        Response::json(['ok' => true]);
    }

    public function removeMember(array $params): void
    {
        $userId = Request::requireUserId();
        ChannelService::removeMember((int) $params['channelId'], (int) $params['userId'], $userId);
        Response::json(['ok' => true]);
    }
}


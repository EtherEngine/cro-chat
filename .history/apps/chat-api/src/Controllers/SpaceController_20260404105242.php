<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Services\SpaceService;
use App\Support\Request;
use App\Support\Response;
use App\Support\Validator;

final class SpaceController
{
    public function index(): void
    {
        $userId = Request::requireUserId();
        Response::json(['spaces' => SpaceService::listForUser($userId)]);
    }

    public function show(array $params): void
    {
        $userId = Request::requireUserId();
        Response::json(['space' => SpaceService::show((int) $params['spaceId'], $userId)]);
    }

    public function create(): void
    {
        $userId = Request::requireUserId();
        $input = Request::json();
        (new Validator($input))->required('name')->maxLength('name', 100)->validate();

        $space = SpaceService::create(
            $input['name'],
            $input['slug'] ?? '',
            $input['description'] ?? '',
            $userId
        );
        Response::json(['space' => $space], 201);
    }

    public function members(array $params): void
    {
        $userId = Request::requireUserId();
        Response::json(['members' => SpaceService::members((int) $params['spaceId'], $userId)]);
    }

    public function addMember(array $params): void
    {
        $userId = Request::requireUserId();
        $input = Request::json();
        (new Validator($input))->required('user_id')->validate();

        SpaceService::addMember(
            (int) $params['spaceId'],
            (int) $input['user_id'],
            $userId,
            $input['role'] ?? 'member'
        );
        Response::json(['ok' => true]);
    }

    public function updateMember(array $params): void
    {
        $userId = Request::requireUserId();
        $input = Request::json();
        (new Validator($input))->required('role')->validate();

        SpaceService::updateMemberRole(
            (int) $params['spaceId'],
            (int) $params['userId'],
            $input['role'],
            $userId
        );
        Response::json(['ok' => true]);
    }

    public function removeMember(array $params): void
    {
        $userId = Request::requireUserId();
        SpaceService::removeMember(
            (int) $params['spaceId'],
            (int) $params['userId'],
            $userId
        );
        Response::json(['ok' => true]);
    }
}

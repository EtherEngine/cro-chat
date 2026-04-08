<?php

namespace App\Controllers;

use App\Exceptions\ApiException;
use App\Repositories\SpaceRepository;
use App\Repositories\UserRepository;
use App\Support\Request;
use App\Support\Response;

final class UserController
{
    public function index(): void
    {
        $userId = Request::requireUserId();
        $users = UserRepository::coMembers($userId);
        Response::json(['users' => $users]);
    }

    public function show(array $params): void
    {
        $userId = Request::requireUserId();
        $targetId = (int) $params['userId'];

        if ($targetId !== $userId && !SpaceRepository::sharesSpace($userId, $targetId)) {
            throw ApiException::notFound('User not found', 'USER_NOT_FOUND');
        }

        $user = UserRepository::find($targetId);
        if (!$user) {
            throw ApiException::notFound('User not found', 'USER_NOT_FOUND');
        }
        Response::json(['user' => $user]);
    }
}


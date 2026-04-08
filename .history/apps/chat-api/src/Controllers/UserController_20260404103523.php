<?php

namespace App\Controllers;

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
            Response::error('User not found', 404);
        }

        $user = UserRepository::find($targetId);
        if (!$user) {
            Response::error('User not found', 404);
        }
        Response::json(['user' => $user]);
    }
}


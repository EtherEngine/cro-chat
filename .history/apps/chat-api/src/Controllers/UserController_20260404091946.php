<?php

namespace App\Controllers;

use App\Repositories\UserRepository;
use App\Support\Request;
use App\Support\Response;

final class UserController
{
    public function index(): void
    {
        Request::requireUserId();
        $users = UserRepository::all();
        Response::json(['users' => $users]);
    }

    public function show(array $params): void
    {
        Request::requireUserId();
        $user = UserRepository::find((int) $params['userId']);
        if (!$user) {
            Response::error('User not found', 404);
        }
        Response::json(['user' => $user]);
    }
}


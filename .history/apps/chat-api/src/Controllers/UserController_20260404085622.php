<?php

namespace App\Controllers;

use App\Repositories\UserRepository;
use App\Support\Request;
use App\Support\Response;

final class UserController
{
    public function index(): void
    {
        $userId = Request::userId();
        if (!$userId) Response::error('Nicht eingeloggt', 401);

        $users = UserRepository::all();
        Response::json(['users' => $users]);
    }
}


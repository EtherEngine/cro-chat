<?php

namespace App\Controllers;

use App\Services\AuthService;
use App\Support\Request;
use App\Support\Response;
use App\Support\Validator;

final class AuthController
{
    public function login(): void
    {
        $input = Request::json();
        (new Validator($input))->required('email', 'password')->email('email')->validate();

        $user = AuthService::login($input['email'], $input['password']);
        Response::json(['user' => $user]);
    }

    public function me(): void
    {
        $userId = Request::requireUserId();
        $user = AuthService::currentUser($userId);
        Response::json(['user' => $user]);
    }

    public function logout(): void
    {
        $userId = Request::userId();
        if ($userId) {
            AuthService::logout($userId);
        }
        Response::json(['ok' => true]);
    }
}
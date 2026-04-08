<?php

namespace App\Controllers;

use App\Support\Request;
use App\Support\Response;

final class AuthController
{
    public function login(): void
    {
        $input = Request::json();

        if (($input['email'] ?? '') === '') {
            Response::error('Email fehlt', 422);
        }

        $_SESSION['user_id'] = 1;

        Response::json(['user' => ['id' => 1]]);
    }

    public function me(): void
    {
        $id = Request::userId();

        if (!$id) {
            Response::error('Nicht eingeloggt', 401);
        }

        Response::json(['user' => ['id' => $id]]);
    }
}
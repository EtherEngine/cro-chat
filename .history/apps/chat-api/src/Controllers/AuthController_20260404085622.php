<?php

namespace App\Controllers;

use App\Repositories\UserRepository;
use App\Support\Request;
use App\Support\Response;

final class AuthController
{
    public function login(): void
    {
        $input = Request::json();
        $email = trim($input['email'] ?? '');

        if ($email === '') {
            Response::error('Email fehlt', 422);
        }

        $user = UserRepository::findByEmail($email);

        if (!$user) {
            Response::error('Benutzer nicht gefunden', 404);
        }

        $_SESSION['user_id'] = $user['id'];
        UserRepository::updateStatus((int)$user['id'], 'online');

        Response::json(['user' => $user]);
    }

    public function me(): void
    {
        $id = Request::userId();

        if (!$id) {
            Response::error('Nicht eingeloggt', 401);
        }

        $user = UserRepository::find($id);

        if (!$user) {
            Response::error('Benutzer nicht gefunden', 404);
        }

        Response::json(['user' => $user]);
    }

    public function logout(): void
    {
        $id = Request::userId();
        if ($id) {
            UserRepository::updateStatus($id, 'offline');
        }
        session_destroy();
        Response::json(['ok' => true]);
    }
}
<?php

namespace App\Controllers;

use App\Exceptions\ApiException;
use App\Repositories\SpaceRepository;
use App\Repositories\UserRepository;
use App\Support\Database;
use App\Support\Request;
use App\Support\Response;
use App\Support\Validator;

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

    public function updateProfile(): void
    {
        $userId = Request::requireUserId();
        $data = Request::json();

        (new Validator($data))
            ->required('display_name')
            ->string('display_name', 'title')
            ->maxLength('display_name', 50)
            ->maxLength('title', 100)
            ->validate();

        $displayName = trim($data['display_name']);
        $title = trim($data['title'] ?? '');

        $stmt = Database::connection()->prepare(
            'UPDATE users SET display_name = ?, title = ? WHERE id = ?'
        );
        $stmt->execute([$displayName, $title, $userId]);

        $user = UserRepository::find($userId);
        Response::json(['user' => $user]);
    }

    public function changePassword(): void
    {
        $userId = Request::requireUserId();
        $data = Request::json();

        (new Validator($data))
            ->required('current_password', 'new_password')
            ->string('current_password', 'new_password')
            ->minLength('new_password', 8)
            ->validate();

        $row = Database::connection()->prepare(
            'SELECT password_hash FROM users WHERE id = ?'
        );
        $row->execute([$userId]);
        $user = $row->fetch();

        if (!$user || !password_verify($data['current_password'], $user['password_hash'])) {
            throw ApiException::forbidden('Current password is incorrect', 'INVALID_PASSWORD');
        }

        $hash = password_hash($data['new_password'], PASSWORD_BCRYPT);
        UserRepository::updatePasswordHash($userId, $hash);

        Response::json(['ok' => true]);
    }
}


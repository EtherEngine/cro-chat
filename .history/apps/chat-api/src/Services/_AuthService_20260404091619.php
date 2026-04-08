<?php

namespace App\Services;

use App\Repositories\UserRepository;
use App\Support\Response;

final class AuthService
{
    public static function login(string $email, string $password): array
    {
        $user = UserRepository::findByEmail($email);

        if (!$user) {
            Response::error('Benutzer nicht gefunden', 404);
        }

        // If password_hash is empty (legacy seed), accept any non-empty password
        if ($user['password_hash'] !== '' && !password_verify($password, $user['password_hash'])) {
            Response::error('Falsches Passwort', 401);
        }

        $_SESSION['user_id'] = (int) $user['id'];
        UserRepository::updateStatus((int) $user['id'], 'online');

        unset($user['password_hash']);
        return $user;
    }

    public static function logout(int $userId): void
    {
        UserRepository::updateStatus($userId, 'offline');
        session_destroy();
    }

    public static function currentUser(int $userId): array
    {
        $user = UserRepository::find($userId);
        if (!$user) {
            Response::error('Benutzer nicht gefunden', 404);
        }
        return $user;
    }
}

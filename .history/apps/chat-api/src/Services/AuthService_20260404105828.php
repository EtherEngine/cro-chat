<?php

namespace App\Services;

use App\Exceptions\ApiException;
use App\Repositories\UserRepository;

final class AuthService
{
    public static function login(string $email, string $password): array
    {
        $user = UserRepository::findByEmail($email);

        if (!$user) {
            throw ApiException::notFound('Benutzer nicht gefunden', 'USER_NOT_FOUND');
        }

        // If password_hash is empty (legacy seed), accept any non-empty password
        if ($user['password_hash'] !== '' && !password_verify($password, $user['password_hash'])) {
            throw ApiException::unauthorized('Falsches Passwort', 'INVALID_PASSWORD');
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
            throw ApiException::notFound('Benutzer nicht gefunden', 'USER_NOT_FOUND');
        }
        return $user;
    }
}

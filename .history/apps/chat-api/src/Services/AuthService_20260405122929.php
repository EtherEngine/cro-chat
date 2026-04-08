<?php

namespace App\Services;

use App\Exceptions\ApiException;
use App\Repositories\UserRepository;
use App\Support\Logger;
use App\Support\Metrics;

final class AuthService
{
    public static function login(string $email, string $password): array
    {
        $user = UserRepository::findByEmail($email);

        if (!$user) {
            Metrics::inc('login.failed');
            Logger::warning('Login failed: user not found', ['email' => $email]);
            throw ApiException::notFound('Benutzer nicht gefunden', 'USER_NOT_FOUND');
        }

        if ($user['password_hash'] === '' || !password_verify($password, $user['password_hash'])) {
            Metrics::inc('login.failed');
            Logger::warning('Login failed: invalid password', ['user_id' => $user['id']]);
            throw ApiException::unauthorized('Falsches Passwort', 'INVALID_PASSWORD');
        }

        // Rehash if algorithm/cost changed (future-proof)
        if (password_needs_rehash($user['password_hash'], PASSWORD_BCRYPT, ['cost' => 12])) {
            UserRepository::updatePasswordHash((int) $user['id'], password_hash($password, PASSWORD_BCRYPT, ['cost' => 12]));
        }

        // Session fixation protection
        if (session_status() === PHP_SESSION_ACTIVE) {
            session_regenerate_id(true);
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

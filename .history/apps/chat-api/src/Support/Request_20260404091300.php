<?php

namespace App\Support;

final class Request
{
    private static ?array $parsedBody = null;

    public static function json(): array
    {
        if (self::$parsedBody === null) {
            $raw = file_get_contents('php://input');
            self::$parsedBody = json_decode($raw ?: '{}', true) ?? [];
        }
        return self::$parsedBody;
    }

    public static function userId(): ?int
    {
        return $_SESSION['user_id'] ?? null;
    }

    public static function requireUserId(): int
    {
        $id = self::userId();
        if (!$id) {
            Response::error('Nicht eingeloggt', 401);
        }
        return $id;
    }

    public static function query(string $key, mixed $default = null): mixed
    {
        return $_GET[$key] ?? $default;
    }
}
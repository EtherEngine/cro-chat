<?php

declare(strict_types=1);

namespace App\Support;

use App\Exceptions\ApiException;

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

    /** @throws ApiException */
    public static function requireUserId(): int
    {
        $id = self::userId();
        if (!$id) {
            throw ApiException::unauthorized();
        }
        return $id;
    }

    public static function query(string $key, mixed $default = null): mixed
    {
        return $_GET[$key] ?? $default;
    }

    public static function queryInt(string $key, int $default = 0): int
    {
        $val = $_GET[$key] ?? null;
        return $val !== null && is_numeric($val) ? (int) $val : $default;
    }
}
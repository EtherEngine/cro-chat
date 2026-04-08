<?php

namespace App\Support;

final class Request
{
    public static function json(): array
    {
        $raw = file_get_contents('php://input');
        return json_decode($raw ?: '{}', true) ?? [];
    }

    public static function userId(): ?int
    {
        return $_SESSION['user_id'] ?? null;
    }
}
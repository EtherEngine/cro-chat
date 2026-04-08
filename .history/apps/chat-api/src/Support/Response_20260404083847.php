<?php

namespace App\Support;

final class Response
{
    public static function json(array $data, int $status = 200): never
    {
        http_response_code($status);
        echo json_encode($data);
        exit;
    }

    public static function error(string $msg, int $status = 400): never
    {
        self::json(['message' => $msg], $status);
    }
}
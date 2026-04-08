<?php

declare(strict_types=1);

namespace App\Support;

use App\Exceptions\ApiException;

/**
 * HTTP response helpers.
 *
 * - json()  → final output from Controller (still calls exit).
 * - error() → throws ApiException (caught by ErrorHandler).
 */
final class Response
{
    /**
     * Send a JSON success response and terminate.
     * Should only be called from Controllers.
     */
    public static function json(array $data, int $status = 200): never
    {
        http_response_code($status);
        echo json_encode($data, JSON_UNESCAPED_UNICODE);
        exit;
    }

    /**
     * Throw an ApiException. Preserved as a convenience shortcut
     * so that existing code keeps working during the migration.
     *
     * @throws ApiException
     */
    public static function error(string $message, int $status = 400, string $errorCode = ''): never
    {
        $code = $errorCode !== '' ? $errorCode : self::statusToCode($status);
        throw new ApiException($message, $status, $code);
    }

    private static function statusToCode(int $status): string
    {
        return match ($status) {
            400 => 'BAD_REQUEST',
            401 => 'UNAUTHORIZED',
            403 => 'FORBIDDEN',
            404 => 'NOT_FOUND',
            409 => 'CONFLICT',
            422 => 'VALIDATION_ERROR',
            500 => 'INTERNAL_ERROR',
            default => 'ERROR',
        };
    }
}
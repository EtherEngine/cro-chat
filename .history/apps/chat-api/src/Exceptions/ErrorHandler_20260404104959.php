<?php

declare(strict_types=1);

namespace App\Exceptions;

use App\Support\Logger;

/**
 * Global error / exception handler.
 * Registered once in index.php — catches everything and returns structured JSON.
 */
final class ErrorHandler
{
    public static function register(): void
    {
        set_exception_handler([self::class, 'handleException']);
        set_error_handler([self::class, 'handleError']);
    }

    public static function handleException(\Throwable $e): void
    {
        if ($e instanceof ApiException) {
            $status = $e->statusCode;
            $payload = [
                'error' => $e->errorCode,
                'message' => $e->getMessage(),
            ];
            if ($e->errors !== null) {
                $payload['errors'] = $e->errors;
            }

            // Log 5xx as error, 4xx as info
            if ($status >= 500) {
                Logger::error($e->getMessage(), ['code' => $e->errorCode, 'trace' => $e->getTraceAsString()]);
            } else {
                Logger::info("HTTP $status: {$e->getMessage()}", ['code' => $e->errorCode]);
            }
        } else {
            // Unexpected exception — always 500
            $status = 500;
            $debug = ($GLOBALS['app_config']['debug'] ?? false);
            $payload = [
                'error' => 'INTERNAL_ERROR',
                'message' => $debug ? $e->getMessage() : 'Interner Serverfehler',
            ];
            if ($debug) {
                $payload['trace'] = explode("\n", $e->getTraceAsString());
            }

            Logger::error('Uncaught: ' . $e->getMessage(), [
                'exception' => get_class($e),
                'file' => $e->getFile() . ':' . $e->getLine(),
                'trace' => $e->getTraceAsString(),
            ]);
        }

        http_response_code($status);
        echo json_encode($payload, JSON_UNESCAPED_UNICODE);
        exit;
    }

    public static function handleError(int $severity, string $message, string $file, int $line): bool
    {
        // Convert PHP errors/warnings/notices to ErrorException
        throw new \ErrorException($message, 0, $severity, $file, $line);
    }
}

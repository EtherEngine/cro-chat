<?php

declare(strict_types=1);

namespace App\Exceptions;

use App\Support\Logger;
use App\Support\Metrics;

/**
 * Global error / exception handler.
 * Registered once in index.php — catches everything and returns structured JSON.
 */
final class ErrorHandler
{
    private static ?float $requestStartNs = null;

    public static function register(?float $requestStartNs = null): void
    {
        self::$requestStartNs = $requestStartNs;
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

            if ($status >= 500) {
                Logger::error($e->getMessage(), ['code' => $e->errorCode, 'trace' => $e->getTraceAsString()]);
            } else {
                Logger::info("HTTP $status: {$e->getMessage()}", ['code' => $e->errorCode]);
            }
        } else {
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

        // Log the request even on error paths
        if (self::$requestStartNs !== null) {
            $durationMs = (hrtime(true) - self::$requestStartNs) / 1_000_000;
            $method = $_SERVER['REQUEST_METHOD'] ?? 'GET';
            $path = parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH) ?: '/';
            $basePath = '/chat-api/public';
            if (str_starts_with($path, $basePath)) {
                $path = substr($path, strlen($basePath)) ?: '/';
            }
            Logger::request($method, $path, $status, $durationMs);
            Metrics::timing('http.request', $durationMs);
            Metrics::flush();
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

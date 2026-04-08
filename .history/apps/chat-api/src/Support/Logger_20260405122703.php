<?php

declare(strict_types=1);

namespace App\Support;

/**
 * Structured JSON logger with request-scoped context.
 * Writes to storage/logs/app-YYYY-MM-DD.log.
 */
final class Logger
{
    private const LEVELS = ['debug' => 0, 'info' => 1, 'warning' => 2, 'error' => 3];

    private static ?string $minLevel = null;

    /** Request-scoped context injected by RequestLifecycle */
    private static array $requestContext = [];

    private static function logDir(): string
    {
        return dirname(__DIR__, 2) . '/storage/logs';
    }

    private static function minLevel(): int
    {
        if (self::$minLevel === null) {
            self::$minLevel = strtolower($GLOBALS['app_config']['log_level'] ?? 'info');
        }
        return self::LEVELS[self::$minLevel] ?? 1;
    }

    /** Set request-scoped fields included in every log entry. */
    public static function setRequestContext(array $ctx): void
    {
        self::$requestContext = $ctx;
    }

    public static function getRequestId(): ?string
    {
        return self::$requestContext['request_id'] ?? null;
    }

    public static function log(string $level, string $message, array $context = []): void
    {
        if ((self::LEVELS[$level] ?? 0) < self::minLevel()) {
            return;
        }

        $dir = self::logDir();
        if (!is_dir($dir)) {
            mkdir($dir, 0755, true);
        }

        $entry = [
            'ts' => date('c'),
            'level' => $level,
            'message' => $message,
        ];

        // Merge request-scoped context
        if (self::$requestContext !== []) {
            $entry += self::$requestContext;
        }

        if ($context !== []) {
            $entry['context'] = $context;
        }

        $file = $dir . '/app-' . date('Y-m-d') . '.log';
        file_put_contents(
            $file,
            json_encode($entry, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) . "\n",
            FILE_APPEND | LOCK_EX
        );
    }

    /**
     * Log a completed HTTP request (called by RequestLifecycle).
     */
    public static function request(
        string $method,
        string $route,
        int $statusCode,
        float $durationMs,
        array $extra = []
    ): void {
        $entry = [
            'ts' => date('c'),
            'level' => 'info',
            'message' => 'http_request',
            'method' => $method,
            'route' => $route,
            'status_code' => $statusCode,
            'duration_ms' => round($durationMs, 2),
        ];

        if (self::$requestContext !== []) {
            $entry += self::$requestContext;
        }

        $entry += $extra;

        $dir = self::logDir();
        if (!is_dir($dir)) {
            mkdir($dir, 0755, true);
        }

        $file = $dir . '/app-' . date('Y-m-d') . '.log';
        file_put_contents(
            $file,
            json_encode($entry, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) . "\n",
            FILE_APPEND | LOCK_EX
        );
    }

    public static function debug(string $msg, array $ctx = []): void
    {
        self::log('debug', $msg, $ctx);
    }
    public static function info(string $msg, array $ctx = []): void
    {
        self::log('info', $msg, $ctx);
    }
    public static function warning(string $msg, array $ctx = []): void
    {
        self::log('warning', $msg, $ctx);
    }
    public static function error(string $msg, array $ctx = []): void
    {
        self::log('error', $msg, $ctx);
    }

    /** Reset for testing */
    public static function resetContext(): void
    {
        self::$requestContext = [];
        self::$minLevel = null;
    }
}

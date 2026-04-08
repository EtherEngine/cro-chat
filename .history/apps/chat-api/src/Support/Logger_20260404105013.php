<?php

declare(strict_types=1);

namespace App\Support;

/**
 * Simple file-based logger.
 * Writes JSON-structured lines to storage/logs/app-YYYY-MM-DD.log.
 */
final class Logger
{
    private const LEVELS = ['debug' => 0, 'info' => 1, 'warning' => 2, 'error' => 3];

    private static ?string $minLevel = null;

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
            'time'    => date('Y-m-d H:i:s'),
            'level'   => $level,
            'message' => $message,
        ];
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

    public static function debug(string $msg, array $ctx = []): void { self::log('debug', $msg, $ctx); }
    public static function info(string $msg, array $ctx = []): void  { self::log('info', $msg, $ctx); }
    public static function warning(string $msg, array $ctx = []): void { self::log('warning', $msg, $ctx); }
    public static function error(string $msg, array $ctx = []): void { self::log('error', $msg, $ctx); }
}

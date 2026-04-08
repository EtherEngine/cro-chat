<?php

declare(strict_types=1);

namespace App\Support;

/**
 * Minimal .env file loader.
 * Reads KEY=VALUE lines into $_ENV + putenv().
 */
final class Env
{
    private static bool $loaded = false;

    public static function load(string $path): void
    {
        if (self::$loaded) {
            return;
        }

        if (!is_readable($path)) {
            return; // No .env file — rely on real env vars
        }

        $lines = file($path, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
        foreach ($lines as $line) {
            $line = trim($line);
            if ($line === '' || $line[0] === '#') {
                continue;
            }
            if (!str_contains($line, '=')) {
                continue;
            }
            [$key, $value] = explode('=', $line, 2);
            $key = trim($key);
            $value = trim($value);

            // Strip surrounding quotes
            if (strlen($value) >= 2 && $value[0] === '"' && $value[-1] === '"') {
                $value = substr($value, 1, -1);
            }

            $_ENV[$key] = $value;
            putenv("$key=$value");
        }

        self::$loaded = true;
    }

    public static function get(string $key, string $default = ''): string
    {
        return $_ENV[$key] ?? getenv($key) ?: $default;
    }

    public static function int(string $key, int $default = 0): int
    {
        $val = self::get($key);
        return $val !== '' ? (int) $val : $default;
    }

    public static function bool(string $key, bool $default = false): bool
    {
        $val = strtolower(self::get($key));
        if ($val === '') {
            return $default;
        }
        return in_array($val, ['true', '1', 'yes', 'on'], true);
    }
}

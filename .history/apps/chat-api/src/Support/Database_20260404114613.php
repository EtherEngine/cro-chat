<?php

declare(strict_types=1);

namespace App\Support;

use PDO;

final class Database
{
    private static ?PDO $pdo = null;
    private static int $queryCount = 0;
    private static float $queryTimeMs = 0.0;
    private static array $slowQueries = [];

    /** Queries slower than this (ms) get logged */
    private static float $slowThresholdMs = 50.0;

    public static function init(array $config): void
    {
        if (self::$pdo !== null) {
            return;
        }

        $dsn = sprintf(
            'mysql:host=%s;port=%d;dbname=%s;charset=%s',
            $config['host'],
            $config['port'],
            $config['dbname'],
            $config['charset']
        );

        self::$pdo = new ProfiledPDO(
            $dsn,
            $config['username'],
            $config['password'],
            [
                PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
                PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
                PDO::ATTR_EMULATE_PREPARES => false,
            ]
        );
    }

    public static function connection(): PDO
    {
        return self::$pdo;
    }

    // ── Query profiling ───────────────────────

    public static function trackQuery(float $durationMs, string $sql = ''): void
    {
        self::$queryCount++;
        self::$queryTimeMs += $durationMs;

        if ($durationMs >= self::$slowThresholdMs) {
            self::$slowQueries[] = [
                'sql' => mb_substr($sql, 0, 200),
                'ms' => round($durationMs, 2),
            ];
            Logger::warning("Slow query ({$durationMs}ms): " . mb_substr($sql, 0, 200));
        }
    }

    public static function getQueryCount(): int
    {
        return self::$queryCount;
    }

    public static function getQueryTimeMs(): float
    {
        return round(self::$queryTimeMs, 2);
    }

    public static function getSlowQueries(): array
    {
        return self::$slowQueries;
    }

    public static function resetCounters(): void
    {
        self::$queryCount = 0;
        self::$queryTimeMs = 0.0;
        self::$slowQueries = [];
    }

    /** @internal For testing only */
    public static function reset(): void
    {
        self::$pdo = null;
    }

    /**
     * Run a callback inside a database transaction.
     * Commits on success, rolls back on any exception and re-throws.
     *
     * @template T
     * @param callable(): T $callback
     * @return T
     */
    public static function transaction(callable $callback): mixed
    {
        $pdo = self::connection();
        $pdo->beginTransaction();
        try {
            $result = $callback();
            $pdo->commit();
            return $result;
        } catch (\Throwable $e) {
            $pdo->rollBack();
            throw $e;
        }
    }
}
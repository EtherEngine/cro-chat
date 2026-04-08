<?php

declare(strict_types=1);

namespace App\Support;

/**
 * Lightweight in-process metrics collector.
 * Tracks counters and timing histograms per request and flushes to a daily metrics log.
 */
final class Metrics
{
    private static array $counters = [];
    private static array $timers = [];

    /** Increment a counter (e.g. 'message.created', 'login.failed'). */
    public static function inc(string $name, int $amount = 1): void
    {
        self::$counters[$name] = (self::$counters[$name] ?? 0) + $amount;
    }

    /** Record a duration in milliseconds (e.g. 'db.query', 'message.create'). */
    public static function timing(string $name, float $ms): void
    {
        self::$timers[$name][] = round($ms, 2);
    }

    /** Start a timer; returns a closure that stops it and records the metric. */
    public static function startTimer(string $name): \Closure
    {
        $start = hrtime(true);
        return static function () use ($name, $start): float {
            $ms = (hrtime(true) - $start) / 1_000_000;
            self::timing($name, $ms);
            return round($ms, 2);
        };
    }

    /** Flush collected metrics to the metrics log file. */
    public static function flush(): void
    {
        if (self::$counters === [] && self::$timers === []) {
            return;
        }

        $entry = [
            'ts' => date('c'),
            'request_id' => Logger::getRequestId(),
        ];

        if (self::$counters !== []) {
            $entry['counters'] = self::$counters;
        }

        if (self::$timers !== []) {
            $summary = [];
            foreach (self::$timers as $name => $values) {
                sort($values);
                $count = count($values);
                $summary[$name] = [
                    'count' => $count,
                    'sum_ms' => round(array_sum($values), 2),
                    'avg_ms' => round(array_sum($values) / $count, 2),
                    'p95_ms' => $values[(int) floor($count * 0.95)] ?? $values[$count - 1],
                ];
            }
            $entry['timers'] = $summary;
        }

        $dir = dirname(__DIR__, 2) . '/storage/logs';
        if (!is_dir($dir)) {
            mkdir($dir, 0755, true);
        }

        $file = $dir . '/metrics-' . date('Y-m-d') . '.log';
        file_put_contents(
            $file,
            json_encode($entry, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) . "\n",
            FILE_APPEND | LOCK_EX
        );

        self::reset();
    }

    /** Get current counters (for health/metrics endpoint). */
    public static function getCounters(): array
    {
        return self::$counters;
    }

    /** Get current timers (for health/metrics endpoint). */
    public static function getTimers(): array
    {
        return self::$timers;
    }

    public static function reset(): void
    {
        self::$counters = [];
        self::$timers = [];
    }
}

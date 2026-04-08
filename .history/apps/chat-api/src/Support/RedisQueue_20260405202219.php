<?php

declare(strict_types=1);

namespace App\Support;

/**
 * Redis-backed queue for high-throughput job processing.
 *
 * Falls back to DB-based queue (JobRepository) when Redis unavailable.
 * Uses BRPOPLPUSH for reliable processing with visibility timeout.
 *
 * Queues:
 *   cro:queue:{name}          – pending jobs (LIST)
 *   cro:queue:{name}:delayed  – delayed jobs (SORTED SET, score = run_at timestamp)
 *   cro:queue:{name}:reserved – processing jobs (SORTED SET, score = expire_at)
 *   cro:queue:failed           – failed jobs (LIST)
 */
final class RedisQueue
{
    private static ?\Redis $redis = null;
    private static bool $connected = false;
    private static string $prefix = 'cro:queue:';

    // ── Connection ───────────────────────────────

    public static function init(?array $config = null): void
    {
        if (self::$connected) {
            return;
        }

        $config = $config ?? [
            'host' => Env::get('REDIS_HOST', '127.0.0.1'),
            'port' => Env::int('REDIS_PORT', 6379),
            'password' => Env::get('REDIS_PASSWORD', ''),
            'database' => Env::int('REDIS_QUEUE_DATABASE', 2),
            'timeout' => 2.0,
            'read_timeout' => 5.0,
        ];

        if (!class_exists(\Redis::class)) {
            return;
        }

        try {
            self::$redis = new \Redis();
            self::$redis->connect(
                $config['host'],
                $config['port'],
                $config['timeout'] ?? 2.0,
                null,
                0,
                $config['read_timeout'] ?? 5.0
            );

            if (!empty($config['password'])) {
                self::$redis->auth($config['password']);
            }

            if (($config['database'] ?? 2) !== 0) {
                self::$redis->select($config['database']);
            }

            self::$connected = true;
            Logger::info('queue.redis_connected');
        } catch (\Throwable $e) {
            self::$redis = null;
            Logger::info('queue.redis_unavailable', ['error' => $e->getMessage()]);
        }
    }

    public static function isConnected(): bool
    {
        return self::$connected && self::$redis !== null;
    }

    // ── Push ─────────────────────────────────────

    /**
     * Push a job onto a queue.
     *
     * @param string $queue        Queue name (default, notifications, maintenance)
     * @param string $type         Job type (notification.dispatch, etc.)
     * @param array  $payload      Job payload
     * @param int    $maxAttempts  Max retry attempts
     * @param int    $priority     Lower = higher priority (unused in Redis, FIFO)
     * @param string|null $idempotencyKey  Prevent duplicate dispatch
     * @return string Job ID
     */
    public static function push(
        string $queue,
        string $type,
        array $payload,
        int $maxAttempts = 3,
        int $priority = 100,
        ?string $idempotencyKey = null
    ): string {
        if (!self::$connected) {
            throw new \RuntimeException('Redis queue not connected');
        }

        $jobId = self::generateId();

        // Idempotency check
        if ($idempotencyKey !== null) {
            $lockKey = self::$prefix . 'idem:' . $idempotencyKey;
            $isNew = self::$redis->set($lockKey, $jobId, ['NX', 'EX' => 86400]);
            if (!$isNew) {
                return self::$redis->get($lockKey); // Return existing job ID
            }
        }

        $job = json_encode([
            'id' => $jobId,
            'type' => $type,
            'payload' => $payload,
            'queue' => $queue,
            'max_attempts' => $maxAttempts,
            'attempts' => 0,
            'priority' => $priority,
            'created_at' => date('c'),
            'idempotency_key' => $idempotencyKey,
        ], JSON_UNESCAPED_UNICODE);

        self::$redis->lPush(self::$prefix . $queue, $job);
        Metrics::inc('queue.pushed');

        return $jobId;
    }

    /**
     * Push a delayed job (runs after $delaySeconds).
     */
    public static function later(
        string $queue,
        string $type,
        array $payload,
        int $delaySeconds,
        int $maxAttempts = 3
    ): string {
        if (!self::$connected) {
            throw new \RuntimeException('Redis queue not connected');
        }

        $jobId = self::generateId();
        $runAt = time() + $delaySeconds;

        $job = json_encode([
            'id' => $jobId,
            'type' => $type,
            'payload' => $payload,
            'queue' => $queue,
            'max_attempts' => $maxAttempts,
            'attempts' => 0,
            'created_at' => date('c'),
            'run_at' => $runAt,
        ], JSON_UNESCAPED_UNICODE);

        self::$redis->zAdd(self::$prefix . $queue . ':delayed', $runAt, $job);
        Metrics::inc('queue.delayed');

        return $jobId;
    }

    // ── Pop / Claim ──────────────────────────────

    /**
     * Pop the next job from a queue. Moves it to the reserved set.
     *
     * @param string $queue
     * @param int    $reservationTtl  Seconds before the job is considered abandoned
     * @return array|null  Decoded job or null if empty
     */
    public static function pop(string $queue, int $reservationTtl = 600): ?array
    {
        if (!self::$connected) {
            return null;
        }

        // First, migrate any due delayed jobs
        self::migrateDelayed($queue);

        // Pop from queue
        $raw = self::$redis->rPop(self::$prefix . $queue);
        if ($raw === false || $raw === null) {
            return null;
        }

        $job = json_decode($raw, true);
        if (!$job) {
            return null;
        }

        // Track in reserved set with expiry score
        $job['attempts']++;
        $job['reserved_at'] = date('c');
        $reserved = json_encode($job, JSON_UNESCAPED_UNICODE);
        self::$redis->zAdd(
            self::$prefix . $queue . ':reserved',
            time() + $reservationTtl,
            $reserved
        );

        Metrics::inc('queue.popped');
        return $job;
    }

    /**
     * Blocking pop — waits up to $timeout seconds for a job.
     */
    public static function bPop(string $queue, int $timeout = 5, int $reservationTtl = 600): ?array
    {
        if (!self::$connected) {
            return null;
        }

        self::migrateDelayed($queue);

        $result = self::$redis->brPop([self::$prefix . $queue], $timeout);
        if ($result === false || $result === null || !isset($result[1])) {
            return null;
        }

        $job = json_decode($result[1], true);
        if (!$job) {
            return null;
        }

        $job['attempts']++;
        $job['reserved_at'] = date('c');
        $reserved = json_encode($job, JSON_UNESCAPED_UNICODE);
        self::$redis->zAdd(
            self::$prefix . $queue . ':reserved',
            time() + $reservationTtl,
            $reserved
        );

        Metrics::inc('queue.popped');
        return $job;
    }

    // ── Complete / Fail ──────────────────────────

    /**
     * Mark a job as completed (remove from reserved set).
     */
    public static function complete(string $queue, array $job): void
    {
        if (!self::$connected) {
            return;
        }

        // Remove from reserved by matching job ID
        self::removeFromReserved($queue, $job['id']);
        Metrics::inc('queue.completed');
    }

    /**
     * Mark a job as failed. Retries or moves to failed queue.
     */
    public static function fail(string $queue, array $job, string $error): void
    {
        if (!self::$connected) {
            return;
        }

        self::removeFromReserved($queue, $job['id']);

        if ($job['attempts'] < ($job['max_attempts'] ?? 3)) {
            // Retry with exponential backoff
            $delay = (int) (30 * pow(4, $job['attempts'] - 1));
            $job['last_error'] = $error;
            $retryJob = json_encode($job, JSON_UNESCAPED_UNICODE);

            self::$redis->zAdd(
                self::$prefix . $queue . ':delayed',
                time() + $delay,
                $retryJob
            );
            Metrics::inc('queue.retried');
        } else {
            // Move to failed queue
            $job['failed_at'] = date('c');
            $job['last_error'] = $error;
            self::$redis->lPush(
                self::$prefix . 'failed',
                json_encode($job, JSON_UNESCAPED_UNICODE)
            );
            Metrics::inc('queue.failed');
        }
    }

    // ── Delayed Job Migration ────────────────────

    /**
     * Move delayed jobs whose run_at has passed to the main queue.
     */
    private static function migrateDelayed(string $queue): void
    {
        $key = self::$prefix . $queue . ':delayed';
        $now = time();

        // Get all due jobs
        $jobs = self::$redis->zRangeByScore($key, '-inf', (string) $now);
        if (empty($jobs)) {
            return;
        }

        foreach ($jobs as $raw) {
            // Remove from delayed, push to main queue
            if (self::$redis->zRem($key, $raw) > 0) {
                self::$redis->lPush(self::$prefix . $queue, $raw);
            }
        }
    }

    /**
     * Recover abandoned reservations (stale lock recovery).
     */
    public static function recoverAbandoned(string $queue): int
    {
        if (!self::$connected) {
            return 0;
        }

        $key = self::$prefix . $queue . ':reserved';
        $now = time();
        $count = 0;

        $expired = self::$redis->zRangeByScore($key, '-inf', (string) $now);
        foreach ($expired as $raw) {
            if (self::$redis->zRem($key, $raw) > 0) {
                // Re-queue the job
                self::$redis->lPush(self::$prefix . $queue, $raw);
                $count++;
            }
        }

        if ($count > 0) {
            Logger::warning('queue.recovered_abandoned', ['queue' => $queue, 'count' => $count]);
        }
        return $count;
    }

    // ── Stats ────────────────────────────────────

    /**
     * Get queue statistics.
     */
    public static function stats(string $queue = 'default'): array
    {
        if (!self::$connected) {
            return ['driver' => 'none', 'connected' => false];
        }

        return [
            'driver' => 'redis',
            'connected' => true,
            'pending' => (int) self::$redis->lLen(self::$prefix . $queue),
            'reserved' => (int) self::$redis->zCard(self::$prefix . $queue . ':reserved'),
            'delayed' => (int) self::$redis->zCard(self::$prefix . $queue . ':delayed'),
            'failed' => (int) self::$redis->lLen(self::$prefix . 'failed'),
        ];
    }

    /**
     * Get all queue names with pending jobs.
     */
    public static function activeQueues(): array
    {
        if (!self::$connected) {
            return [];
        }

        $queues = [];
        $iterator = null;
        while (($keys = self::$redis->scan($iterator, self::$prefix . '*', 100)) !== false) {
            foreach ($keys as $key) {
                $name = str_replace(self::$prefix, '', $key);
                if (!str_contains($name, ':')) {
                    $queues[] = $name;
                }
            }
        }
        return array_unique($queues);
    }

    // ── Cleanup ──────────────────────────────────

    /**
     * Flush the failed queue.
     */
    public static function flushFailed(): int
    {
        if (!self::$connected) {
            return 0;
        }

        $count = (int) self::$redis->lLen(self::$prefix . 'failed');
        self::$redis->del(self::$prefix . 'failed');
        return $count;
    }

    /**
     * Retry all failed jobs by moving them back to their original queues.
     */
    public static function retryFailed(): int
    {
        if (!self::$connected) {
            return 0;
        }

        $count = 0;
        while (($raw = self::$redis->rPop(self::$prefix . 'failed')) !== false) {
            $job = json_decode($raw, true);
            if ($job) {
                $job['attempts'] = 0;
                unset($job['failed_at'], $job['last_error']);
                self::$redis->lPush(
                    self::$prefix . ($job['queue'] ?? 'default'),
                    json_encode($job, JSON_UNESCAPED_UNICODE)
                );
                $count++;
            }
        }
        return $count;
    }

    // ── Helpers ──────────────────────────────────

    private static function generateId(): string
    {
        return bin2hex(random_bytes(16));
    }

    /**
     * Remove a specific job from the reserved set by ID.
     */
    private static function removeFromReserved(string $queue, string $jobId): void
    {
        $key = self::$prefix . $queue . ':reserved';

        // Scan reserved set to find the matching job
        $members = self::$redis->zRange($key, 0, -1);
        foreach ($members as $raw) {
            $decoded = json_decode($raw, true);
            if ($decoded && ($decoded['id'] ?? '') === $jobId) {
                self::$redis->zRem($key, $raw);
                break;
            }
        }
    }

    /** @internal For testing */
    public static function flush(): void
    {
        if (self::$connected && self::$redis) {
            $iterator = null;
            while (($keys = self::$redis->scan($iterator, self::$prefix . '*', 100)) !== false) {
                if (!empty($keys)) {
                    self::$redis->del(...$keys);
                }
            }
        }
    }

    /** @internal Reset for testing */
    public static function reset(): void
    {
        self::$redis = null;
        self::$connected = false;
    }
}

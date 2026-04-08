<?php

declare(strict_types=1);

namespace App\Services;

use App\Repositories\JobRepository;

/**
 * High-level dispatcher for async jobs.
 *
 * Usage:
 *   JobService::dispatch('notification.dispatch', ['notification_id' => 42]);
 *   JobService::later('search.reindex', ['message_id' => 7], delay: 10);
 */
final class JobService
{
    /**
     * Dispatch a job to be processed as soon as possible.
     *
     * @param string      $type           Job handler type key (e.g. 'notification.dispatch')
     * @param array       $payload        JSON-serialisable data for the handler
     * @param string      $queue          Queue name (default, notifications, maintenance)
     * @param int         $maxAttempts    Max retries before marking as failed
     * @param int         $priority       Lower = higher priority (default 100)
     * @param string|null $idempotencyKey Prevents duplicate dispatch for same key
     */
    public static function dispatch(
        string $type,
        array $payload,
        string $queue = 'default',
        int $maxAttempts = 3,
        int $priority = 100,
        ?string $idempotencyKey = null
    ): ?array {
        return JobRepository::dispatch($type, $payload, $queue, $maxAttempts, $priority, $idempotencyKey);
    }

    /**
     * Dispatch a delayed job (available after $delaySeconds).
     */
    public static function later(
        string $type,
        array $payload,
        int $delaySeconds,
        string $queue = 'default',
        int $maxAttempts = 3,
        int $priority = 100,
        ?string $idempotencyKey = null
    ): ?array {
        $availableAt = date('Y-m-d H:i:s', time() + $delaySeconds);
        return JobRepository::dispatch($type, $payload, $queue, $maxAttempts, $priority, $idempotencyKey, $availableAt);
    }
}

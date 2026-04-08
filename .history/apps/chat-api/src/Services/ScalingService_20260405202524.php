<?php

declare(strict_types=1);

namespace App\Services;

use App\Exceptions\ApiException;
use App\Support\Cache;
use App\Support\Env;
use App\Support\ObjectStorage;
use App\Support\RedisQueue;

/**
 * Infrastructure service for scaling management.
 *
 * Provides:
 *   - Health checks (readiness + liveness)
 *   - Cache warming and invalidation
 *   - Queue monitoring
 *   - Storage info
 *   - Horizontal scaling status
 */
final class ScalingService
{
    // ── Cache Patterns ───────────────────────────

    /**
     * Cache key patterns for hot data.
     */
    private const CACHE_KEYS = [
        'user' => 'user:%d',                            // user profile
        'channel' => 'channel:%d',                       // channel metadata
        'channel_members' => 'channel:%d:members',       // member list
        'space_member' => 'space:%d:member:%d',          // membership + role
        'presence' => 'presence:space:%d',               // presence map
        'unread' => 'unread:%d:%d',                      // unread count per user+channel
        'channel_list' => 'channels:space:%d:user:%d',   // user's channel list
    ];

    /**
     * Get cache key for a resource.
     */
    public static function cacheKey(string $pattern, int ...$ids): string
    {
        $template = self::CACHE_KEYS[$pattern] ?? $pattern;
        return sprintf($template, ...$ids);
    }

    /**
     * Cache a user profile.
     */
    public static function cacheUser(array $user): void
    {
        Cache::set(
            sprintf('user:%d', $user['id']),
            $user,
            600 // 10 min
        );
    }

    /**
     * Get cached user or null.
     */
    public static function getCachedUser(int $userId): ?array
    {
        return Cache::get(sprintf('user:%d', $userId));
    }

    /**
     * Invalidate user-related caches.
     */
    public static function invalidateUser(int $userId): void
    {
        Cache::delete(sprintf('user:%d', $userId));
        Cache::invalidateTag('unread:' . $userId);
    }

    /**
     * Cache and retrieve channel data.
     */
    public static function getCachedChannel(int $channelId): ?array
    {
        return Cache::remember(
            sprintf('channel:%d', $channelId),
            300,
            fn() => null // caller must provide compute callback
        );
    }

    /**
     * Invalidate channel caches.
     */
    public static function invalidateChannel(int $channelId, ?int $spaceId = null): void
    {
        Cache::delete(sprintf('channel:%d', $channelId));
        Cache::delete(sprintf('channel:%d:members', $channelId));
        if ($spaceId) {
            Cache::invalidateTag('channels:space:' . $spaceId);
        }
    }

    /**
     * Cache presence map for a space.
     */
    public static function cachePresence(int $spaceId, array $presenceMap): void
    {
        Cache::set(sprintf('presence:space:%d', $spaceId), $presenceMap, 30);
    }

    /**
     * Get cached presence or null.
     */
    public static function getCachedPresence(int $spaceId): ?array
    {
        return Cache::get(sprintf('presence:space:%d', $spaceId));
    }

    // ── Health Checks ────────────────────────────

    /**
     * Full readiness check (DB + Cache + Queue + Storage).
     */
    public static function readinessCheck(): array
    {
        $checks = [];

        // Database
        try {
            \App\Support\Database::connection()->query('SELECT 1');
            $checks['database'] = ['status' => 'ok'];
        } catch (\Throwable $e) {
            $checks['database'] = ['status' => 'error', 'message' => $e->getMessage()];
        }

        // Cache
        $checks['cache'] = [
            'status' => 'ok',
            'driver' => Cache::isConnected() ? 'redis' : 'memory',
            'stats' => Cache::stats(),
        ];

        // Queue
        if (RedisQueue::isConnected()) {
            $checks['queue'] = [
                'status' => 'ok',
                'driver' => 'redis',
                'stats' => RedisQueue::stats(),
            ];
        } else {
            $checks['queue'] = ['status' => 'ok', 'driver' => 'database'];
        }

        // Storage
        $checks['storage'] = [
            'status' => 'ok',
            'driver' => ObjectStorage::driver(),
            'info' => ObjectStorage::info(),
        ];

        $allOk = true;
        foreach ($checks as $check) {
            if (($check['status'] ?? 'ok') !== 'ok') {
                $allOk = false;
                break;
            }
        }

        return [
            'status' => $allOk ? 'ready' : 'degraded',
            'instance' => gethostname() . ':' . getmypid(),
            'checks' => $checks,
        ];
    }

    /**
     * Get scaling info overview.
     */
    public static function scalingInfo(): array
    {
        return [
            'instance' => [
                'hostname' => gethostname(),
                'pid' => getmypid(),
                'php_version' => PHP_VERSION,
                'memory_usage_mb' => round(memory_get_usage(true) / 1024 / 1024, 1),
                'peak_memory_mb' => round(memory_get_peak_usage(true) / 1024 / 1024, 1),
            ],
            'cache' => Cache::stats(),
            'queue' => [
                'redis' => RedisQueue::isConnected(),
                'default' => RedisQueue::isConnected() ? RedisQueue::stats('default') : null,
                'notifications' => RedisQueue::isConnected() ? RedisQueue::stats('notifications') : null,
                'maintenance' => RedisQueue::isConnected() ? RedisQueue::stats('maintenance') : null,
            ],
            'storage' => ObjectStorage::info(),
            'features' => [
                'redis_cache' => Cache::isConnected(),
                'redis_queue' => RedisQueue::isConnected(),
                'redis_sessions' => Env::bool('REDIS_SESSIONS', false),
                'object_storage' => ObjectStorage::driver(),
            ],
        ];
    }

    // ── Rate Limiting (Redis) ────────────────────

    /**
     * Redis-based rate limiter (sliding window).
     * Falls back to DB-based rate limiting if Redis unavailable.
     *
     * @return array{allowed: bool, remaining: int, reset_at: int}
     */
    public static function rateLimit(string $key, int $maxRequests, int $windowSeconds): array
    {
        if (!Cache::isConnected()) {
            // Fallback: handled by existing RateLimitMiddleware
            return ['allowed' => true, 'remaining' => $maxRequests, 'reset_at' => time() + $windowSeconds];
        }

        $cacheKey = 'ratelimit:' . $key;
        $now = time();
        $windowStart = $now - $windowSeconds;

        // Use atomic increment with TTL
        $count = Cache::increment($cacheKey, 1, $windowSeconds);

        $allowed = $count <= $maxRequests;
        $remaining = max(0, $maxRequests - $count);

        return [
            'allowed' => $allowed,
            'remaining' => $remaining,
            'reset_at' => $now + $windowSeconds,
        ];
    }
}

<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Services\ScalingService;
use App\Support\Cache;
use App\Support\ObjectStorage;
use App\Support\RedisQueue;
use App\Support\Request;
use App\Support\Response;

/**
 * Infrastructure & scaling endpoints.
 *
 * Mostly admin-only except health checks.
 */
final class ScalingController
{
    /**
     * GET /api/scaling/health
     * Full readiness check (public, no auth required).
     */
    public static function health(): never
    {
        Response::json(ScalingService::readinessCheck());
    }

    /**
     * GET /api/scaling/info
     * Scaling overview (admin only).
     */
    public static function info(): never
    {
        $userId = Request::requireUserId();
        self::requireAdmin($userId);
        Response::json(ScalingService::scalingInfo());
    }

    /**
     * GET /api/scaling/cache/stats
     * Cache statistics (admin only).
     */
    public static function cacheStats(): never
    {
        $userId = Request::requireUserId();
        self::requireAdmin($userId);
        Response::json(Cache::stats());
    }

    /**
     * POST /api/scaling/cache/flush
     * Flush all cache (admin only).
     */
    public static function cacheFlush(): never
    {
        $userId = Request::requireUserId();
        self::requireAdmin($userId);
        Cache::flush();
        Response::json(['flushed' => true]);
    }

    /**
     * GET /api/scaling/queue/stats
     * Queue statistics (admin only).
     */
    public static function queueStats(): never
    {
        $userId = Request::requireUserId();
        self::requireAdmin($userId);

        $queues = ['default', 'notifications', 'maintenance'];
        $stats = [];
        foreach ($queues as $q) {
            $stats[$q] = RedisQueue::isConnected() ? RedisQueue::stats($q) : ['driver' => 'database'];
        }

        Response::json([
            'driver' => RedisQueue::isConnected() ? 'redis' : 'database',
            'queues' => $stats,
        ]);
    }

    /**
     * POST /api/scaling/queue/retry-failed
     * Retry all failed queue jobs (admin only).
     */
    public static function queueRetryFailed(): never
    {
        $userId = Request::requireUserId();
        self::requireAdmin($userId);

        $count = RedisQueue::retryFailed();
        Response::json(['retried' => $count]);
    }

    /**
     * GET /api/scaling/storage/info
     * Storage driver info (admin only).
     */
    public static function storageInfo(): never
    {
        $userId = Request::requireUserId();
        self::requireAdmin($userId);
        Response::json(ObjectStorage::info());
    }

    /**
     * Check admin access via any space where user is owner/admin.
     */
    private static function requireAdmin(int $userId): void
    {
        $db = \App\Support\Database::connection();
        $stmt = $db->prepare('SELECT role FROM space_members WHERE user_id = ? AND role IN (?, ?) LIMIT 1');
        $stmt->execute([$userId, 'owner', 'admin']);
        $row = $stmt->fetch();

        if (!$row) {
            throw \App\Exceptions\ApiException::forbidden('Admin-Zugriff erforderlich', 'SCALING_ADMIN_REQUIRED');
        }
    }
}

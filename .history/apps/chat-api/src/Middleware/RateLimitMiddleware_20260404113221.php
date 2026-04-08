<?php

declare(strict_types=1);

namespace App\Middleware;

use App\Exceptions\ApiException;
use App\Support\Database;

/**
 * Sliding-window rate limiter backed by a DB table.
 *
 * Usage: RateLimitMiddleware::check('login', $ip, maxAttempts: 5, windowSeconds: 300);
 */
final class RateLimitMiddleware
{
    /**
     * @throws ApiException (429) if limit exceeded
     */
    public static function check(
        string $action,
        string $key,
        int $maxAttempts,
        int $windowSeconds
    ): void {
        $db = Database::connection();

        // Purge expired entries
        $db->prepare(
            'DELETE FROM rate_limits WHERE action = ? AND `key` = ? AND attempted_at < DATE_SUB(NOW(), INTERVAL ? SECOND)'
        )->execute([$action, $key, $windowSeconds]);

        // Count current window
        $stmt = $db->prepare(
            'SELECT COUNT(*) FROM rate_limits WHERE action = ? AND `key` = ?'
        );
        $stmt->execute([$action, $key]);
        $count = (int) $stmt->fetchColumn();

        if ($count >= $maxAttempts) {
            throw new ApiException(
                'Zu viele Anfragen. Bitte warte einen Moment.',
                429,
                'RATE_LIMIT_EXCEEDED'
            );
        }

        // Record this attempt
        $db->prepare(
            'INSERT INTO rate_limits (action, `key`, attempted_at) VALUES (?, ?, NOW())'
        )->execute([$action, $key]);
    }

    /**
     * Clear rate limit entries for a given action+key (e.g. after successful login).
     */
    public static function clear(string $action, string $key): void
    {
        Database::connection()->prepare(
            'DELETE FROM rate_limits WHERE action = ? AND `key` = ?'
        )->execute([$action, $key]);
    }
}

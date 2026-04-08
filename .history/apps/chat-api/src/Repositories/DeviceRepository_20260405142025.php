<?php

declare(strict_types=1);

namespace App\Repositories;

use App\Support\Database;

/**
 * Repository for push subscriptions, sync cursors, and VAPID keys.
 */
final class DeviceRepository
{
    // ── Push Subscriptions ──────────────────────────────────────────

    /**
     * Register or update a push subscription for a device.
     */
    public static function upsertSubscription(
        int $userId,
        int $spaceId,
        string $deviceId,
        string $platform,
        ?string $deviceName,
        ?string $endpoint,
        ?string $p256dhKey,
        ?string $authKey,
        ?string $pushToken
    ): array {
        $db = Database::connection();

        $stmt = $db->prepare(
            'INSERT INTO push_subscriptions
                (user_id, space_id, device_id, platform, device_name, endpoint, p256dh_key, auth_key, push_token, active, last_used_at)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, 1, NOW())
             ON DUPLICATE KEY UPDATE
                platform = VALUES(platform),
                device_name = VALUES(device_name),
                endpoint = VALUES(endpoint),
                p256dh_key = VALUES(p256dh_key),
                auth_key = VALUES(auth_key),
                push_token = VALUES(push_token),
                active = 1,
                last_used_at = NOW()'
        );
        $stmt->execute([$userId, $spaceId, $deviceId, $platform, $deviceName, $endpoint, $p256dhKey, $authKey, $pushToken]);

        return self::findByDevice($userId, $deviceId, $spaceId);
    }

    /**
     * Find a subscription by user + device + space.
     */
    public static function findByDevice(int $userId, string $deviceId, int $spaceId): ?array
    {
        $db = Database::connection();
        $stmt = $db->prepare(
            'SELECT * FROM push_subscriptions WHERE user_id = ? AND device_id = ? AND space_id = ?'
        );
        $stmt->execute([$userId, $deviceId, $spaceId]);
        $row = $stmt->fetch(\PDO::FETCH_ASSOC);
        return $row ?: null;
    }

    /**
     * Get all active subscriptions for a user (across all spaces).
     */
    public static function activeForUser(int $userId): array
    {
        $db = Database::connection();
        $stmt = $db->prepare(
            'SELECT * FROM push_subscriptions WHERE user_id = ? AND active = 1 ORDER BY last_used_at DESC'
        );
        $stmt->execute([$userId]);
        return $stmt->fetchAll(\PDO::FETCH_ASSOC);
    }

    /**
     * Get all active subscriptions for a user in a specific space.
     */
    public static function activeForUserInSpace(int $userId, int $spaceId): array
    {
        $db = Database::connection();
        $stmt = $db->prepare(
            'SELECT * FROM push_subscriptions WHERE user_id = ? AND space_id = ? AND active = 1'
        );
        $stmt->execute([$userId, $spaceId]);
        return $stmt->fetchAll(\PDO::FETCH_ASSOC);
    }

    /**
     * List all devices registered by the current user (all spaces).
     */
    public static function listDevices(int $userId): array
    {
        $db = Database::connection();
        $stmt = $db->prepare(
            'SELECT id, device_id, device_name, platform, active, last_used_at, space_id, created_at
             FROM push_subscriptions
             WHERE user_id = ?
             ORDER BY last_used_at DESC'
        );
        $stmt->execute([$userId]);
        return $stmt->fetchAll(\PDO::FETCH_ASSOC);
    }

    /**
     * Deactivate a specific subscription.
     */
    public static function deactivate(int $id, int $userId): bool
    {
        $db = Database::connection();
        $stmt = $db->prepare(
            'UPDATE push_subscriptions SET active = 0 WHERE id = ? AND user_id = ?'
        );
        $stmt->execute([$id, $userId]);
        return $stmt->rowCount() > 0;
    }

    /**
     * Remove a subscription entirely.
     */
    public static function remove(int $id, int $userId): bool
    {
        $db = Database::connection();
        $stmt = $db->prepare(
            'DELETE FROM push_subscriptions WHERE id = ? AND user_id = ?'
        );
        $stmt->execute([$id, $userId]);
        return $stmt->rowCount() > 0;
    }

    /**
     * Touch last_used_at timestamp.
     */
    public static function touch(int $id): void
    {
        $db = Database::connection();
        $stmt = $db->prepare('UPDATE push_subscriptions SET last_used_at = NOW() WHERE id = ?');
        $stmt->execute([$id]);
    }

    // ── Push Delivery Log ───────────────────────────────────────────

    /**
     * Log a push delivery attempt.
     */
    public static function logDelivery(int $subscriptionId, ?int $notificationId, string $status, ?string $error = null): int
    {
        $db = Database::connection();
        $stmt = $db->prepare(
            'INSERT INTO push_delivery_log (subscription_id, notification_id, status, error)
             VALUES (?, ?, ?, ?)'
        );
        $stmt->execute([$subscriptionId, $notificationId, $status, $error]);
        return (int) $db->lastInsertId();
    }

    /**
     * Update delivery status.
     */
    public static function updateDeliveryStatus(int $logId, string $status, ?string $error = null): void
    {
        $db = Database::connection();
        $stmt = $db->prepare(
            'UPDATE push_delivery_log SET status = ?, error = ? WHERE id = ?'
        );
        $stmt->execute([$status, $error, $logId]);
    }

    // ── Sync Cursors ────────────────────────────────────────────────

    /**
     * Get or create the sync cursor for a device.
     */
    public static function getSyncCursor(int $userId, string $deviceId, int $spaceId): int
    {
        $db = Database::connection();
        $stmt = $db->prepare(
            'SELECT last_event_id FROM sync_cursors WHERE user_id = ? AND device_id = ? AND space_id = ?'
        );
        $stmt->execute([$userId, $deviceId, $spaceId]);
        $row = $stmt->fetch(\PDO::FETCH_ASSOC);
        return $row ? (int) $row['last_event_id'] : 0;
    }

    /**
     * Update the sync cursor (advance to the given event ID).
     */
    public static function updateSyncCursor(int $userId, string $deviceId, int $spaceId, int $lastEventId): void
    {
        $db = Database::connection();
        $stmt = $db->prepare(
            'INSERT INTO sync_cursors (user_id, device_id, space_id, last_event_id)
             VALUES (?, ?, ?, ?)
             ON DUPLICATE KEY UPDATE last_event_id = GREATEST(last_event_id, VALUES(last_event_id))'
        );
        $stmt->execute([$userId, $deviceId, $spaceId, $lastEventId]);
    }

    /**
     * Get missed events for a device since its last cursor position.
     */
    public static function getMissedEvents(int $userId, string $deviceId, int $spaceId, int $limit = 200): array
    {
        $lastEventId = self::getSyncCursor($userId, $deviceId, $spaceId);

        $db = Database::connection();
        // Fetch events the user should see (in rooms they belong to)
        $stmt = $db->prepare(
            'SELECT de.id, de.type, de.room, de.payload, de.created_at
             FROM domain_events de
             WHERE de.id > ?
               AND de.space_id = ?
               AND (
                 de.room LIKE CONCAT("user:", ?)
                 OR de.room IN (
                   SELECT CONCAT("channel:", cm.channel_id)
                   FROM channel_members cm
                   INNER JOIN channels c ON c.id = cm.channel_id AND c.space_id = ?
                   WHERE cm.user_id = ?
                 )
                 OR de.room IN (
                   SELECT CONCAT("conversation:", cvm.conversation_id)
                   FROM conversation_members cvm
                   INNER JOIN conversations cv ON cv.id = cvm.conversation_id AND cv.space_id = ?
                   WHERE cvm.user_id = ?
                 )
               )
             ORDER BY de.id ASC
             LIMIT ?'
        );
        $stmt->execute([$lastEventId, $spaceId, $userId, $spaceId, $userId, $spaceId, $userId, $limit]);
        return $stmt->fetchAll(\PDO::FETCH_ASSOC);
    }

    // ── VAPID Keys ──────────────────────────────────────────────────

    /**
     * Get VAPID keys for a space (or null if not yet generated).
     */
    public static function getVapidKeys(int $spaceId): ?array
    {
        $db = Database::connection();
        $stmt = $db->prepare('SELECT * FROM vapid_keys WHERE space_id = ?');
        $stmt->execute([$spaceId]);
        $row = $stmt->fetch(\PDO::FETCH_ASSOC);
        return $row ?: null;
    }

    /**
     * Store VAPID keys for a space.
     */
    public static function storeVapidKeys(int $spaceId, string $publicKey, string $privateKey): void
    {
        $db = Database::connection();
        $stmt = $db->prepare(
            'INSERT INTO vapid_keys (space_id, public_key, private_key)
             VALUES (?, ?, ?)
             ON DUPLICATE KEY UPDATE public_key = VALUES(public_key), private_key = VALUES(private_key)'
        );
        $stmt->execute([$spaceId, $publicKey, $privateKey]);
    }

    // ── Cleanup ─────────────────────────────────────────────────────

    /**
     * Deactivate subscriptions not used in the given number of days.
     */
    public static function deactivateStale(int $days = 90): int
    {
        $db = Database::connection();
        $stmt = $db->prepare(
            'UPDATE push_subscriptions SET active = 0
             WHERE active = 1 AND last_used_at < DATE_SUB(NOW(), INTERVAL ? DAY)'
        );
        $stmt->execute([$days]);
        return $stmt->rowCount();
    }

    /**
     * Purge old delivery logs.
     */
    public static function purgeDeliveryLogs(int $days = 30): int
    {
        $db = Database::connection();
        $stmt = $db->prepare(
            'DELETE FROM push_delivery_log WHERE created_at < DATE_SUB(NOW(), INTERVAL ? DAY)'
        );
        $stmt->execute([$days]);
        return $stmt->rowCount();
    }
}

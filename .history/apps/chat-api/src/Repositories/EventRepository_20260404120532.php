<?php

declare(strict_types=1);

namespace App\Repositories;

use App\Support\Database;

final class EventRepository
{
    /**
     * Insert a domain event into the outbox table.
     * Must be called within the same transaction as the business write.
     *
     * @param string $type  e.g. message.created, message.updated, message.deleted
     * @param string $room  e.g. channel:123 or conversation:456
     * @param array  $payload  JSON-serialisable data (the full message object)
     */
    public static function publish(string $type, string $room, array $payload): void
    {
        $stmt = Database::connection()->prepare(
            'INSERT INTO domain_events (event_type, room, payload) VALUES (?, ?, ?)'
        );
        $stmt->execute([$type, $room, json_encode($payload, JSON_UNESCAPED_UNICODE)]);
    }

    /**
     * Fetch unpublished events (for the realtime poller).
     * Returns rows with id, event_type, room, payload, created_at.
     */
    public static function fetchUnpublished(int $limit = 100): array
    {
        $stmt = Database::connection()->prepare(
            'SELECT id, event_type, room, payload, created_at
             FROM domain_events
             WHERE published_at IS NULL
             ORDER BY id ASC
             LIMIT ?'
        );
        $stmt->execute([$limit]);
        return $stmt->fetchAll();
    }

    /**
     * Mark events as published.
     * @param int[] $ids
     */
    public static function markPublished(array $ids): void
    {
        if (empty($ids)) {
            return;
        }
        $placeholders = implode(',', array_fill(0, count($ids), '?'));
        $stmt = Database::connection()->prepare(
            "UPDATE domain_events SET published_at = NOW() WHERE id IN ($placeholders)"
        );
        $stmt->execute($ids);
    }

    /**
     * Purge old published events (housekeeping).
     * Call periodically to prevent table bloat.
     */
    public static function purgeOlderThan(int $hours = 24): int
    {
        $stmt = Database::connection()->prepare(
            'DELETE FROM domain_events WHERE published_at IS NOT NULL AND published_at < DATE_SUB(NOW(), INTERVAL ? HOUR)'
        );
        $stmt->execute([$hours]);
        return $stmt->rowCount();
    }
}

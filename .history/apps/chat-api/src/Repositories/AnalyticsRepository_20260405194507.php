<?php

declare(strict_types=1);

namespace App\Repositories;

use App\Support\Database;

final class AnalyticsRepository
{
    // ── Privacy: user hashing ────────────────────

    /**
     * Hash user ID with a daily-rotating salt for privacy.
     * Same user produces different hashes on different days.
     */
    public static function hashUser(int $userId): string
    {
        $salt = date('Y-m-d') . ':analytics:' . ($userId >> 4);
        return hash('sha256', $userId . ':' . $salt);
    }

    // ── Product Events ───────────────────────────

    public static function trackEvent(
        int $spaceId,
        int $userId,
        string $eventType,
        ?int $channelId = null,
        ?array $metadata = null,
        string $category = 'product'
    ): void {
        Database::connection()->prepare('
            INSERT INTO analytics_events (space_id, user_hash, event_type, event_category, channel_id, metadata)
            VALUES (?, ?, ?, ?, ?, ?)
        ')->execute([
            $spaceId,
            self::hashUser($userId),
            $eventType,
            $category,
            $channelId,
            $metadata !== null ? json_encode($metadata, JSON_UNESCAPED_UNICODE) : null,
        ]);
    }

    public static function trackBatch(array $events): void
    {
        $db = Database::connection();
        $stmt = $db->prepare('
            INSERT INTO analytics_events (space_id, user_hash, event_type, event_category, channel_id, metadata)
            VALUES (?, ?, ?, ?, ?, ?)
        ');
        foreach ($events as $e) {
            $stmt->execute([
                $e['space_id'],
                self::hashUser($e['user_id']),
                $e['event_type'],
                $e['category'] ?? 'product',
                $e['channel_id'] ?? null,
                isset($e['metadata']) ? json_encode($e['metadata'], JSON_UNESCAPED_UNICODE) : null,
            ]);
        }
    }

    // ── System Events ────────────────────────────

    public static function trackSystemEvent(
        string $eventType,
        ?int $spaceId = null,
        string $severity = 'info',
        ?array $metadata = null
    ): void {
        Database::connection()->prepare('
            INSERT INTO analytics_system_events (space_id, event_type, severity, metadata)
            VALUES (?, ?, ?, ?)
        ')->execute([
            $spaceId,
            $eventType,
            $severity,
            $metadata !== null ? json_encode($metadata, JSON_UNESCAPED_UNICODE) : null,
        ]);
    }

    // ── DAU / WAU / MAU ──────────────────────────

    /**
     * Count distinct active users from product events in a date range.
     */
    public static function countActiveUsers(int $spaceId, string $after, string $before): int
    {
        $stmt = Database::connection()->prepare('
            SELECT COUNT(DISTINCT user_hash) AS cnt
            FROM analytics_events
            WHERE space_id = ? AND event_category = "product"
              AND created_at >= ? AND created_at < ?
        ');
        $stmt->execute([$spaceId, $after, $before]);
        return (int) $stmt->fetch()['cnt'];
    }

    /**
     * DAU for the last N days: [{date, count}, ...]
     */
    public static function dauTimeseries(int $spaceId, int $days = 30): array
    {
        $stmt = Database::connection()->prepare('
            SELECT DATE(created_at) AS date, COUNT(DISTINCT user_hash) AS count
            FROM analytics_events
            WHERE space_id = ? AND event_category = "product"
              AND created_at >= DATE_SUB(CURDATE(), INTERVAL ? DAY)
            GROUP BY DATE(created_at)
            ORDER BY date
        ');
        $stmt->execute([$spaceId, $days]);
        return $stmt->fetchAll();
    }

    // ── Channel Activity ─────────────────────────

    public static function channelActivity(int $spaceId, int $days = 30, int $limit = 20): array
    {
        $stmt = Database::connection()->prepare('
            SELECT e.channel_id, ch.name AS channel_name,
                   COUNT(*) AS event_count,
                   COUNT(DISTINCT e.user_hash) AS unique_users
            FROM analytics_events e
            JOIN channels ch ON ch.id = e.channel_id
            WHERE e.space_id = ? AND e.channel_id IS NOT NULL
              AND e.created_at >= DATE_SUB(NOW(), INTERVAL ? DAY)
            GROUP BY e.channel_id, ch.name
            ORDER BY event_count DESC
            LIMIT ?
        ');
        $stmt->execute([$spaceId, $days, $limit]);
        return $stmt->fetchAll();
    }

    // ── Response Times ───────────────────────────

    /**
     * Average time (ms) between a message and its first reply, per day.
     */
    public static function responseTimesTimeseries(int $spaceId, int $days = 30): array
    {
        $stmt = Database::connection()->prepare('
            SELECT DATE(reply.created_at) AS date,
                   ROUND(AVG(TIMESTAMPDIFF(SECOND, orig.created_at, reply.created_at))) AS avg_seconds,
                   ROUND(MIN(TIMESTAMPDIFF(SECOND, orig.created_at, reply.created_at))) AS min_seconds,
                   COUNT(*) AS reply_count
            FROM messages reply
            JOIN messages orig ON orig.id = reply.reply_to_id
            LEFT JOIN channels ch ON ch.id = reply.channel_id
            LEFT JOIN conversations cv ON cv.id = reply.conversation_id
            WHERE reply.reply_to_id IS NOT NULL
              AND reply.deleted_at IS NULL
              AND (ch.space_id = ? OR cv.space_id = ?)
              AND reply.created_at >= DATE_SUB(CURDATE(), INTERVAL ? DAY)
            GROUP BY DATE(reply.created_at)
            ORDER BY date
        ');
        $stmt->execute([$spaceId, $spaceId, $days]);
        return $stmt->fetchAll();
    }

    // ── Search Usage ─────────────────────────────

    public static function searchUsage(int $spaceId, int $days = 30): array
    {
        $stmt = Database::connection()->prepare('
            SELECT DATE(created_at) AS date,
                   COUNT(*) AS search_count,
                   COUNT(DISTINCT user_hash) AS unique_searchers
            FROM analytics_events
            WHERE space_id = ? AND event_type = "search.executed"
              AND created_at >= DATE_SUB(CURDATE(), INTERVAL ? DAY)
            GROUP BY DATE(created_at)
            ORDER BY date
        ');
        $stmt->execute([$spaceId, $days]);
        return $stmt->fetchAll();
    }

    /**
     * Top search terms from metadata.
     */
    public static function topSearchTerms(int $spaceId, int $days = 30, int $limit = 20): array
    {
        $stmt = Database::connection()->prepare('
            SELECT JSON_UNQUOTE(JSON_EXTRACT(metadata, "$.query")) AS term,
                   COUNT(*) AS count
            FROM analytics_events
            WHERE space_id = ? AND event_type = "search.executed"
              AND created_at >= DATE_SUB(CURDATE(), INTERVAL ? DAY)
              AND metadata IS NOT NULL
            GROUP BY term
            ORDER BY count DESC
            LIMIT ?
        ');
        $stmt->execute([$spaceId, $days, $limit]);
        return $stmt->fetchAll();
    }

    // ── Notification Engagement ──────────────────

    public static function notificationEngagement(int $spaceId, int $days = 30): array
    {
        $stmt = Database::connection()->prepare('
            SELECT
                COUNT(*) AS total_sent,
                SUM(CASE WHEN e.event_type = "notification.clicked" THEN 1 ELSE 0 END) AS total_clicked,
                DATE(e.created_at) AS date
            FROM analytics_events e
            WHERE e.space_id = ? AND e.event_type IN ("notification.sent", "notification.clicked")
              AND e.created_at >= DATE_SUB(CURDATE(), INTERVAL ? DAY)
            GROUP BY DATE(e.created_at)
            ORDER BY date
        ');
        $stmt->execute([$spaceId, $days]);
        return $stmt->fetchAll();
    }

    // ── Event Type Breakdown ─────────────────────

    public static function eventBreakdown(int $spaceId, int $days = 30, string $category = 'product'): array
    {
        $stmt = Database::connection()->prepare('
            SELECT event_type, COUNT(*) AS count
            FROM analytics_events
            WHERE space_id = ? AND event_category = ?
              AND created_at >= DATE_SUB(CURDATE(), INTERVAL ? DAY)
            GROUP BY event_type
            ORDER BY count DESC
        ');
        $stmt->execute([$spaceId, $category, $days]);
        return $stmt->fetchAll();
    }

    // ── Pre-aggregated Daily Metrics ─────────────

    public static function upsertDailyMetric(int $spaceId, string $date, string $metricName, float $value, ?array $breakdown = null): void
    {
        Database::connection()->prepare('
            INSERT INTO analytics_daily (space_id, metric_date, metric_name, metric_value, breakdown)
            VALUES (?, ?, ?, ?, ?)
            ON DUPLICATE KEY UPDATE metric_value = VALUES(metric_value), breakdown = VALUES(breakdown)
        ')->execute([
            $spaceId,
            $date,
            $metricName,
            $value,
            $breakdown !== null ? json_encode($breakdown, JSON_UNESCAPED_UNICODE) : null,
        ]);
    }

    public static function getDailyMetrics(int $spaceId, string $metricName, string $from, string $to): array
    {
        $stmt = Database::connection()->prepare('
            SELECT metric_date, metric_value, breakdown
            FROM analytics_daily
            WHERE space_id = ? AND metric_name = ? AND metric_date >= ? AND metric_date <= ?
            ORDER BY metric_date
        ');
        $stmt->execute([$spaceId, $metricName, $from, $to]);
        return array_map(function (array $row): array {
            return [
                'date' => $row['metric_date'],
                'value' => (float) $row['metric_value'],
                'breakdown' => $row['breakdown'] ? json_decode($row['breakdown'], true) : null,
            ];
        }, $stmt->fetchAll());
    }

    public static function getLatestDailyMetrics(int $spaceId, string $date): array
    {
        $stmt = Database::connection()->prepare('
            SELECT metric_name, metric_value, breakdown
            FROM analytics_daily
            WHERE space_id = ? AND metric_date = ?
        ');
        $stmt->execute([$spaceId, $date]);
        $result = [];
        foreach ($stmt->fetchAll() as $row) {
            $result[$row['metric_name']] = [
                'value' => (float) $row['metric_value'],
                'breakdown' => $row['breakdown'] ? json_decode($row['breakdown'], true) : null,
            ];
        }
        return $result;
    }

    // ── Aggregation Queries (used by job) ────────

    /**
     * Compute DAU/WAU/MAU and message counts from live data for a given date.
     */
    public static function aggregateForDate(int $spaceId, string $date): array
    {
        $db = Database::connection();

        // DAU from analytics_events
        $stmt = $db->prepare('
            SELECT COUNT(DISTINCT user_hash) AS dau
            FROM analytics_events
            WHERE space_id = ? AND event_category = "product"
              AND DATE(created_at) = ?
        ');
        $stmt->execute([$spaceId, $date]);
        $dau = (int) $stmt->fetch()['dau'];

        // WAU: last 7 days ending on $date
        $stmt = $db->prepare('
            SELECT COUNT(DISTINCT user_hash) AS wau
            FROM analytics_events
            WHERE space_id = ? AND event_category = "product"
              AND DATE(created_at) > DATE_SUB(?, INTERVAL 7 DAY) AND DATE(created_at) <= ?
        ');
        $stmt->execute([$spaceId, $date, $date]);
        $wau = (int) $stmt->fetch()['wau'];

        // MAU: last 30 days ending on $date
        $stmt = $db->prepare('
            SELECT COUNT(DISTINCT user_hash) AS mau
            FROM analytics_events
            WHERE space_id = ? AND event_category = "product"
              AND DATE(created_at) > DATE_SUB(?, INTERVAL 30 DAY) AND DATE(created_at) <= ?
        ');
        $stmt->execute([$spaceId, $date, $date]);
        $mau = (int) $stmt->fetch()['mau'];

        // Messages sent today
        $stmt = $db->prepare('
            SELECT COUNT(*) AS cnt FROM messages m
            LEFT JOIN channels ch ON ch.id = m.channel_id
            LEFT JOIN conversations cv ON cv.id = m.conversation_id
            WHERE DATE(m.created_at) = ? AND m.deleted_at IS NULL
              AND (ch.space_id = ? OR cv.space_id = ?)
        ');
        $stmt->execute([$date, $spaceId, $spaceId]);
        $messagesSent = (int) $stmt->fetch()['cnt'];

        // Active channels
        $stmt = $db->prepare('
            SELECT COUNT(DISTINCT m.channel_id) AS cnt FROM messages m
            JOIN channels ch ON ch.id = m.channel_id
            WHERE DATE(m.created_at) = ? AND ch.space_id = ? AND m.deleted_at IS NULL
        ');
        $stmt->execute([$date, $spaceId]);
        $channelsActive = (int) $stmt->fetch()['cnt'];

        return [
            'dau' => $dau,
            'wau' => $wau,
            'mau' => $mau,
            'messages_sent' => $messagesSent,
            'channels_active' => $channelsActive,
        ];
    }

    // ── System Events Read ───────────────────────

    public static function listSystemEvents(?int $spaceId, int $days = 7, int $limit = 100): array
    {
        $sql = 'SELECT * FROM analytics_system_events WHERE created_at >= DATE_SUB(NOW(), INTERVAL ? DAY)';
        $params = [$days];
        if ($spaceId !== null) {
            $sql .= ' AND space_id = ?';
            $params[] = $spaceId;
        }
        $sql .= ' ORDER BY created_at DESC LIMIT ?';
        $params[] = $limit;

        $stmt = Database::connection()->prepare($sql);
        $stmt->execute($params);
        return array_map(function (array $row): array {
            return [
                'id' => (int) $row['id'],
                'space_id' => $row['space_id'] ? (int) $row['space_id'] : null,
                'event_type' => $row['event_type'],
                'severity' => $row['severity'],
                'metadata' => $row['metadata'] ? json_decode($row['metadata'], true) : null,
                'created_at' => $row['created_at'],
            ];
        }, $stmt->fetchAll());
    }

    public static function systemEventCounts(int $days = 7): array
    {
        $stmt = Database::connection()->prepare('
            SELECT event_type, severity, COUNT(*) AS count
            FROM analytics_system_events
            WHERE created_at >= DATE_SUB(NOW(), INTERVAL ? DAY)
            GROUP BY event_type, severity
            ORDER BY count DESC
        ');
        $stmt->execute([$days]);
        return $stmt->fetchAll();
    }

    // ── Cleanup ──────────────────────────────────

    /**
     * Remove raw events older than $days. Daily aggregates are kept.
     */
    public static function purgeOldEvents(int $days = 90): int
    {
        $stmt = Database::connection()->prepare('
            DELETE FROM analytics_events WHERE created_at < DATE_SUB(NOW(), INTERVAL ? DAY)
        ');
        $stmt->execute([$days]);
        $count = $stmt->rowCount();

        $stmt = Database::connection()->prepare('
            DELETE FROM analytics_system_events WHERE created_at < DATE_SUB(NOW(), INTERVAL ? DAY)
        ');
        $stmt->execute([$days]);
        return $count + $stmt->rowCount();
    }
}

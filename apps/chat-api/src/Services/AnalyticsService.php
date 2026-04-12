<?php

declare(strict_types=1);

namespace App\Services;

use App\Exceptions\ApiException;
use App\Repositories\AnalyticsRepository;
use App\Repositories\SpaceRepository;

final class AnalyticsService
{
    // ── Event Types ──────────────────────────────

    private const PRODUCT_EVENTS = [
        'message.sent',
        'message.edited',
        'message.deleted',
        'reaction.added',
        'reaction.removed',
        'thread.created',
        'thread.replied',
        'channel.joined',
        'channel.left',
        'channel.created',
        'conversation.created',
        'search.executed',
        'notification.sent',
        'notification.clicked',
        'notification.dismissed',
        'attachment.uploaded',
        'pin.added',
        'pin.removed',
        'presence.online',
        'mention.created',
        'task.created',
        'task.completed',
        'draft.published',
        'snippet.created',
        // ── Call events ──
        'call.initiated',
        'call.accepted',
        'call.rejected',
        'call.missed',
        'call.ended',
        'call.failed',
    ];

    private const SYSTEM_EVENTS = [
        'job.completed',
        'job.failed',
        'api.error',
        'api.slow_query',
        'api.rate_limited',
        'push.sent',
        'push.failed',
        // ── Call system events ──
        'call.setup_failure',
        'call.signaling_error',
    ];

    // ── Track Events ─────────────────────────────

    public static function trackProduct(
        int $spaceId,
        int $userId,
        string $eventType,
        ?int $channelId = null,
        ?array $metadata = null
    ): void {
        if (!in_array($eventType, self::PRODUCT_EVENTS, true)) {
            throw ApiException::validation(
                "Unbekannter Event-Typ: {$eventType}",
                'ANALYTICS_INVALID_EVENT_TYPE'
            );
        }

        AnalyticsRepository::trackEvent($spaceId, $userId, $eventType, $channelId, $metadata);
    }

    public static function trackSystem(
        string $eventType,
        ?int $spaceId = null,
        string $severity = 'info',
        ?array $metadata = null
    ): void {
        if (!in_array($eventType, self::SYSTEM_EVENTS, true)) {
            throw ApiException::validation(
                "Unbekannter System-Event-Typ: {$eventType}",
                'ANALYTICS_INVALID_SYSTEM_EVENT'
            );
        }
        if (!in_array($severity, ['info', 'warning', 'error'], true)) {
            $severity = 'info';
        }

        AnalyticsRepository::trackSystemEvent($eventType, $spaceId, $severity, $metadata);
    }

    public static function trackBatch(int $spaceId, int $userId, array $events): int
    {
        $valid = [];
        foreach ($events as $event) {
            $type = $event['event_type'] ?? '';
            if (!in_array($type, self::PRODUCT_EVENTS, true)) {
                continue; // skip invalid, don't fail batch
            }
            $valid[] = [
                'space_id' => $spaceId,
                'user_id' => $userId,
                'event_type' => $type,
                'category' => 'product',
                'channel_id' => $event['channel_id'] ?? null,
                'metadata' => $event['metadata'] ?? null,
            ];
        }
        if (!empty($valid)) {
            AnalyticsRepository::trackBatch($valid);
        }
        return count($valid);
    }

    // ── Dashboard ────────────────────────────────

    public static function dashboard(int $spaceId, int $userId, int $days = 30): array
    {
        self::requireAdminAccess($spaceId, $userId);

        $today = date('Y-m-d');
        $todayStart = $today . ' 00:00:00';
        $tomorrowStart = date('Y-m-d', strtotime('+1 day')) . ' 00:00:00';
        $weekAgo = date('Y-m-d', strtotime('-7 days')) . ' 00:00:00';
        $monthAgo = date('Y-m-d', strtotime('-30 days')) . ' 00:00:00';

        // Engagement metrics
        $dauToday = AnalyticsRepository::countActiveUsers($spaceId, $todayStart, $tomorrowStart);
        $wau = AnalyticsRepository::countActiveUsers($spaceId, $weekAgo, $tomorrowStart);
        $mau = AnalyticsRepository::countActiveUsers($spaceId, $monthAgo, $tomorrowStart);

        // Timeseries
        $dauSeries = AnalyticsRepository::dauTimeseries($spaceId, $days);
        $channelActivity = AnalyticsRepository::channelActivity($spaceId, $days);
        $responseTimes = AnalyticsRepository::responseTimesTimeseries($spaceId, $days);
        $searchUsage = AnalyticsRepository::searchUsage($spaceId, $days);
        $notifEngagement = AnalyticsRepository::notificationEngagement($spaceId, $days);
        $eventBreakdown = AnalyticsRepository::eventBreakdown($spaceId, $days);

        return [
            'engagement' => [
                'dau' => $dauToday,
                'wau' => $wau,
                'mau' => $mau,
                'stickiness' => $mau > 0 ? round($dauToday / $mau * 100, 1) : 0,
            ],
            'dau_timeseries' => $dauSeries,
            'channel_activity' => $channelActivity,
            'response_times' => $responseTimes,
            'search_usage' => $searchUsage,
            'notification_engagement' => $notifEngagement,
            'event_breakdown' => $eventBreakdown,
        ];
    }

    // ── Individual Analytics Endpoints ────────────

    public static function engagement(int $spaceId, int $userId, int $days = 30): array
    {
        self::requireAdminAccess($spaceId, $userId);

        return [
            'dau_timeseries' => AnalyticsRepository::dauTimeseries($spaceId, $days),
        ];
    }

    public static function channelActivity(int $spaceId, int $userId, int $days = 30): array
    {
        self::requireAdminAccess($spaceId, $userId);

        return [
            'channels' => AnalyticsRepository::channelActivity($spaceId, $days),
        ];
    }

    public static function responseTimes(int $spaceId, int $userId, int $days = 30): array
    {
        self::requireAdminAccess($spaceId, $userId);

        return [
            'timeseries' => AnalyticsRepository::responseTimesTimeseries($spaceId, $days),
        ];
    }

    public static function searchUsage(int $spaceId, int $userId, int $days = 30): array
    {
        self::requireAdminAccess($spaceId, $userId);

        return [
            'timeseries' => AnalyticsRepository::searchUsage($spaceId, $days),
            'top_terms' => AnalyticsRepository::topSearchTerms($spaceId, $days),
        ];
    }

    public static function notificationEngagement(int $spaceId, int $userId, int $days = 30): array
    {
        self::requireAdminAccess($spaceId, $userId);

        return [
            'timeseries' => AnalyticsRepository::notificationEngagement($spaceId, $days),
        ];
    }

    public static function systemEvents(int $spaceId, int $userId, int $days = 7): array
    {
        self::requireAdminAccess($spaceId, $userId);

        return [
            'events' => AnalyticsRepository::listSystemEvents($spaceId, $days),
            'counts' => AnalyticsRepository::systemEventCounts($days),
        ];
    }

    // ── Aggregation (called by job) ──────────────

    public static function aggregateDay(int $spaceId, ?string $date = null): array
    {
        $date = $date ?? date('Y-m-d', strtotime('-1 day'));

        $metrics = AnalyticsRepository::aggregateForDate($spaceId, $date);

        foreach ($metrics as $name => $value) {
            AnalyticsRepository::upsertDailyMetric($spaceId, $date, $name, (float) $value);
        }

        return $metrics;
    }

    public static function getDailyMetrics(int $spaceId, int $userId, string $metricName, string $from, string $to): array
    {
        self::requireAdminAccess($spaceId, $userId);

        self::validateDateRange($from, $to);

        return AnalyticsRepository::getDailyMetrics($spaceId, $metricName, $from, $to);
    }

    // ── Cleanup ──────────────────────────────────

    public static function purgeOldEvents(int $days = 90): int
    {
        if ($days < 30) {
            throw ApiException::validation('Mindestens 30 Tage Aufbewahrung', 'ANALYTICS_RETENTION_TOO_SHORT');
        }
        return AnalyticsRepository::purgeOldEvents($days);
    }

    public static function getProductEventTypes(): array
    {
        return self::PRODUCT_EVENTS;
    }

    public static function getSystemEventTypes(): array
    {
        return self::SYSTEM_EVENTS;
    }

    // ── Helpers ──────────────────────────────────

    private static function requireAdminAccess(int $spaceId, int $userId): void
    {
        $role = SpaceRepository::memberRole($spaceId, $userId);
        if (!$role || !in_array($role, ['owner', 'admin'], true)) {
            throw ApiException::forbidden('Nur Admins können Analytics einsehen', 'ANALYTICS_ADMIN_REQUIRED');
        }
    }

    private static function validateDateRange(string $from, string $to): void
    {
        if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $from) || !preg_match('/^\d{4}-\d{2}-\d{2}$/', $to)) {
            throw ApiException::validation('Datumsformat muss YYYY-MM-DD sein', 'ANALYTICS_INVALID_DATE');
        }
        if ($from > $to) {
            throw ApiException::validation('from muss vor to liegen', 'ANALYTICS_INVALID_DATE_RANGE');
        }
    }
}

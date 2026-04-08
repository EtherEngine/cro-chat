<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Services\AnalyticsService;
use App\Support\Request;
use App\Support\Response;

final class AnalyticsController
{
    /**
     * POST /api/spaces/{spaceId}/analytics/events
     * Track a single product event.
     */
    public function trackEvent(array $params): void
    {
        $userId = Request::requireUserId();
        $spaceId = (int) $params['spaceId'];
        $body = Request::json();

        AnalyticsService::trackProduct(
            $spaceId,
            $userId,
            $body['event_type'] ?? '',
            isset($body['channel_id']) ? (int) $body['channel_id'] : null,
            $body['metadata'] ?? null,
        );

        Response::json(['tracked' => true], 201);
    }

    /**
     * POST /api/spaces/{spaceId}/analytics/events/batch
     * Track multiple product events at once.
     */
    public function trackBatch(array $params): void
    {
        $userId = Request::requireUserId();
        $spaceId = (int) $params['spaceId'];
        $body = Request::json();

        $events = $body['events'] ?? [];
        $count = AnalyticsService::trackBatch($spaceId, $userId, $events);

        Response::json(['tracked' => $count], 201);
    }

    /**
     * GET /api/spaces/{spaceId}/analytics/dashboard
     * Full dashboard: DAU/WAU/MAU, channel activity, response times, search usage, notifications.
     */
    public function dashboard(array $params): void
    {
        $userId = Request::requireUserId();
        $spaceId = (int) $params['spaceId'];
        $days = min(365, max(1, (int) ($_GET['days'] ?? 30)));

        $data = AnalyticsService::dashboard($spaceId, $userId, $days);

        Response::json($data);
    }

    /**
     * GET /api/spaces/{spaceId}/analytics/engagement
     * DAU timeseries.
     */
    public function engagement(array $params): void
    {
        $userId = Request::requireUserId();
        $spaceId = (int) $params['spaceId'];
        $days = min(365, max(1, (int) ($_GET['days'] ?? 30)));

        Response::json(AnalyticsService::engagement($spaceId, $userId, $days));
    }

    /**
     * GET /api/spaces/{spaceId}/analytics/channels
     * Channel activity ranking.
     */
    public function channelActivity(array $params): void
    {
        $userId = Request::requireUserId();
        $spaceId = (int) $params['spaceId'];
        $days = min(365, max(1, (int) ($_GET['days'] ?? 30)));

        Response::json(AnalyticsService::channelActivity($spaceId, $userId, $days));
    }

    /**
     * GET /api/spaces/{spaceId}/analytics/response-times
     * Reply response times timeseries.
     */
    public function responseTimes(array $params): void
    {
        $userId = Request::requireUserId();
        $spaceId = (int) $params['spaceId'];
        $days = min(365, max(1, (int) ($_GET['days'] ?? 30)));

        Response::json(AnalyticsService::responseTimes($spaceId, $userId, $days));
    }

    /**
     * GET /api/spaces/{spaceId}/analytics/search
     * Search usage and top terms.
     */
    public function searchUsage(array $params): void
    {
        $userId = Request::requireUserId();
        $spaceId = (int) $params['spaceId'];
        $days = min(365, max(1, (int) ($_GET['days'] ?? 30)));

        Response::json(AnalyticsService::searchUsage($spaceId, $userId, $days));
    }

    /**
     * GET /api/spaces/{spaceId}/analytics/notifications
     * Notification engagement (sent vs clicked).
     */
    public function notificationEngagement(array $params): void
    {
        $userId = Request::requireUserId();
        $spaceId = (int) $params['spaceId'];
        $days = min(365, max(1, (int) ($_GET['days'] ?? 30)));

        Response::json(AnalyticsService::notificationEngagement($spaceId, $userId, $days));
    }

    /**
     * GET /api/spaces/{spaceId}/analytics/system
     * System events and error counts.
     */
    public function systemEvents(array $params): void
    {
        $userId = Request::requireUserId();
        $spaceId = (int) $params['spaceId'];
        $days = min(30, max(1, (int) ($_GET['days'] ?? 7)));

        Response::json(AnalyticsService::systemEvents($spaceId, $userId, $days));
    }

    /**
     * GET /api/spaces/{spaceId}/analytics/daily
     * Pre-aggregated daily metrics.
     */
    public function dailyMetrics(array $params): void
    {
        $userId = Request::requireUserId();
        $spaceId = (int) $params['spaceId'];
        $metricName = $_GET['metric'] ?? 'dau';
        $from = $_GET['from'] ?? date('Y-m-d', strtotime('-30 days'));
        $to = $_GET['to'] ?? date('Y-m-d');

        Response::json([
            'metrics' => AnalyticsService::getDailyMetrics($spaceId, $userId, $metricName, $from, $to),
        ]);
    }

    /**
     * POST /api/spaces/{spaceId}/analytics/aggregate
     * Trigger daily aggregation (admin only, typically called by job).
     */
    public function aggregate(array $params): void
    {
        $userId = Request::requireUserId();
        $spaceId = (int) $params['spaceId'];
        $body = Request::json();

        $date = $body['date'] ?? null;
        $metrics = AnalyticsService::aggregateDay($spaceId, $date);

        Response::json(['aggregated' => $metrics]);
    }

    /**
     * GET /api/analytics/event-types
     * List all known event types.
     */
    public function eventTypes(): void
    {
        Request::requireUserId();

        Response::json([
            'product' => AnalyticsService::getProductEventTypes(),
            'system' => AnalyticsService::getSystemEventTypes(),
        ]);
    }
}

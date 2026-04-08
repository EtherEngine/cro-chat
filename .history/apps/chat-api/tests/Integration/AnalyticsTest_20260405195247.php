<?php

declare(strict_types=1);

namespace Tests\Integration;

use App\Repositories\AnalyticsRepository;
use App\Services\AnalyticsService;
use App\Services\MessageService;
use Tests\TestCase;

final class AnalyticsTest extends TestCase
{
    // -- Event Tracking --

    public function test_track_product_event(): void
    {
        $alice = $this->createUser(['display_name' => 'Alice']);
        $space = $this->createSpace($alice['id']);

        AnalyticsService::trackProduct($space['id'], $alice['id'], 'message.sent', null, ['length' => 42]);

        $db = \App\Support\Database::connection();
        $row = $db->query('SELECT * FROM analytics_events ORDER BY id DESC LIMIT 1')->fetch();
        $this->assertSame('message.sent', $row['event_type']);
        $this->assertSame('product', $row['event_category']);
        $this->assertNotEmpty($row['user_hash']);
        $meta = json_decode($row['metadata'], true);
        $this->assertSame(42, $meta['length']);
    }

    public function test_track_rejects_invalid_event_type(): void
    {
        $alice = $this->createUser(['display_name' => 'Alice']);
        $space = $this->createSpace($alice['id']);

        $this->assertApiException(
            422,
            'ANALYTICS_INVALID_EVENT_TYPE',
            fn() =>
            AnalyticsService::trackProduct($space['id'], $alice['id'], 'invalid.event')
        );
    }

    public function test_track_system_event(): void
    {
        $alice = $this->createUser(['display_name' => 'Alice']);
        $space = $this->createSpace($alice['id']);

        AnalyticsService::trackSystem('job.completed', $space['id'], 'info', ['job_type' => 'search.reindex']);

        $db = \App\Support\Database::connection();
        $row = $db->query('SELECT * FROM analytics_system_events ORDER BY id DESC LIMIT 1')->fetch();
        $this->assertSame('job.completed', $row['event_type']);
        $this->assertSame('info', $row['severity']);
    }

    public function test_track_system_rejects_invalid_type(): void
    {
        $this->assertApiException(
            422,
            'ANALYTICS_INVALID_SYSTEM_EVENT',
            fn() =>
            AnalyticsService::trackSystem('invalid.system.event')
        );
    }

    public function test_track_batch(): void
    {
        $alice = $this->createUser(['display_name' => 'Alice']);
        $space = $this->createSpace($alice['id']);

        $count = AnalyticsService::trackBatch($space['id'], $alice['id'], [
            ['event_type' => 'message.sent'],
            ['event_type' => 'reaction.added', 'metadata' => ['emoji' => '']],
            ['event_type' => 'invalid.skip.me'],
            ['event_type' => 'search.executed'],
        ]);

        $this->assertSame(3, $count);

        $db = \App\Support\Database::connection();
        $cnt = (int) $db->query('SELECT COUNT(*) AS c FROM analytics_events')->fetch()['c'];
        $this->assertSame(3, $cnt);
    }

    // -- Privacy: User Hashing --

    public function test_user_hash_is_not_plain_id(): void
    {
        $hash = AnalyticsRepository::hashUser(42);
        $this->assertSame(64, strlen($hash));
        $this->assertNotSame('42', $hash);
    }

    public function test_same_user_same_day_same_hash(): void
    {
        $h1 = AnalyticsRepository::hashUser(42);
        $h2 = AnalyticsRepository::hashUser(42);
        $this->assertSame($h1, $h2);
    }

    public function test_different_users_different_hashes(): void
    {
        $h1 = AnalyticsRepository::hashUser(1);
        $h2 = AnalyticsRepository::hashUser(2);
        $this->assertNotSame($h1, $h2);
    }
    // -- System Events --

    public function test_list_system_events(): void
    {
        $alice = $this->createUser(['display_name' => 'Alice']);
        $space = $this->createSpace($alice['id']);

        AnalyticsService::trackSystem('job.completed', $space['id'], 'info', ['job' => 'test']);
        AnalyticsService::trackSystem('api.error', $space['id'], 'error', ['path' => '/test']);

        $events = AnalyticsService::systemEvents($space['id'], $alice['id']);
        $this->assertArrayHasKey('events', $events);
        $this->assertCount(2, $events['events']);
        $this->assertArrayHasKey('counts', $events);
    }

    public function test_system_event_counts_by_severity(): void
    {
        $alice = $this->createUser(['display_name' => 'Alice']);
        $space = $this->createSpace($alice['id']);

        AnalyticsService::trackSystem('job.completed', $space['id'], 'info');
        AnalyticsService::trackSystem('api.error', $space['id'], 'error');
        AnalyticsService::trackSystem('api.slow_query', $space['id'], 'warning');

        $counts = AnalyticsRepository::systemEventCounts($space['id'], 7);
        $this->assertCount(3, $counts);
    }

    // -- Purge --

    public function test_purge_enforces_minimum_retention(): void
    {
        $alice = $this->createUser(['display_name' => 'Alice']);
        $space = $this->createSpace($alice['id']);

        $this->assertApiException(
            422,
            'ANALYTICS_RETENTION_TOO_SHORT',
            fn() =>
            AnalyticsService::purgeOldEvents(10)
        );
    }

    public function test_purge_deletes_old_events(): void
    {
        $alice = $this->createUser(['display_name' => 'Alice']);
        $space = $this->createSpace($alice['id']);

        AnalyticsRepository::trackEvent($space['id'], $alice['id'], 'message.sent');

        // 30 days — nothing deleted since it was just created
        $result = AnalyticsService::purgeOldEvents(30);
        $this->assertSame(0, $result);
    }

    // -- Event Types --

    public function test_get_product_event_types(): void
    {
        $types = AnalyticsService::getProductEventTypes();
        $this->assertContains('message.sent', $types);
        $this->assertContains('reaction.added', $types);
        $this->assertContains('search.executed', $types);
        $this->assertGreaterThan(20, count($types));
    }

    public function test_get_system_event_types(): void
    {
        $types = AnalyticsService::getSystemEventTypes();
        $this->assertContains('job.completed', $types);
        $this->assertContains('api.error', $types);
        $this->assertGreaterThan(5, count($types));
    }

    // -- Engagement via Service --

    public function test_engagement_via_service(): void
    {
        $alice = $this->createUser(['display_name' => 'Alice']);
        $space = $this->createSpace($alice['id']);

        AnalyticsService::trackProduct($space['id'], $alice['id'], 'message.sent');

        $engagement = AnalyticsService::engagement($space['id'], $alice['id']);
        $this->assertArrayHasKey('dau_timeseries', $engagement);
        $this->assertIsArray($engagement['dau_timeseries']);
    }

    // -- Channel Activity via Service --

    public function test_channel_activity_via_service(): void
    {
        $alice = $this->createUser(['display_name' => 'Alice']);
        $space = $this->createSpace($alice['id']);
        $channel = $this->createChannel($space['id'], $alice['id']);

        AnalyticsService::trackProduct($space['id'], $alice['id'], 'message.sent', $channel['id']);

        $activity = AnalyticsService::channelActivity($space['id'], $alice['id']);
        $this->assertNotEmpty($activity);
    }

    // -- Search Usage via Service --

    public function test_search_usage_via_service(): void
    {
        $alice = $this->createUser(['display_name' => 'Alice']);
        $space = $this->createSpace($alice['id']);

        AnalyticsService::trackProduct($space['id'], $alice['id'], 'search.executed', null, ['query' => 'test']);

        $usage = AnalyticsService::searchUsage($space['id'], $alice['id']);
        $this->assertArrayHasKey('timeseries', $usage);
        $this->assertArrayHasKey('top_terms', $usage);
    }

    // -- Notification Engagement via Service --

    public function test_notification_engagement_via_service(): void
    {
        $alice = $this->createUser(['display_name' => 'Alice']);
        $space = $this->createSpace($alice['id']);

        AnalyticsService::trackProduct($space['id'], $alice['id'], 'notification.sent');
        AnalyticsService::trackProduct($space['id'], $alice['id'], 'notification.clicked');

        $engagement = AnalyticsService::notificationEngagement($space['id'], $alice['id']);
        $this->assertNotEmpty($engagement);
    }

    // -- Response Times via Service --

    public function test_response_times_via_service(): void
    {
        $alice = $this->createUser(['display_name' => 'Alice']);
        $space = $this->createSpace($alice['id']);

        $times = AnalyticsService::responseTimes($space['id'], $alice['id']);
        $this->assertIsArray($times);
    }
}
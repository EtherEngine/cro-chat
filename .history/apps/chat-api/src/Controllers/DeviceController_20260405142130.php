<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Repositories\DeviceRepository;
use App\Services\PushService;
use App\Support\Request;
use App\Support\Response;

/**
 * Device registration, push subscriptions, and sync endpoints.
 */
final class DeviceController
{
    /**
     * GET /api/devices
     * List all devices registered by the current user.
     */
    public function listDevices(): void
    {
        $userId = Request::requireUserId();
        $devices = DeviceRepository::listDevices($userId);
        Response::json(['devices' => $devices]);
    }

    /**
     * POST /api/devices/register
     * Register a device for push notifications.
     *
     * Body: { device_id, platform, device_name?, space_id, endpoint?, p256dh_key?, auth_key?, push_token? }
     */
    public function register(): void
    {
        $userId = Request::requireUserId();
        $body = Request::jsonBody();

        $deviceId = trim($body['device_id'] ?? '');
        if (!$deviceId || strlen($deviceId) > 255) {
            Response::error('device_id is required (max 255 chars)', 400);
            return;
        }

        $platform = $body['platform'] ?? 'web';
        if (!in_array($platform, ['web', 'desktop', 'android', 'ios'], true)) {
            Response::error('Invalid platform', 400);
            return;
        }

        $spaceId = (int) ($body['space_id'] ?? 0);
        if ($spaceId <= 0) {
            Response::error('space_id is required', 400);
            return;
        }

        // For web push, endpoint is required
        $endpoint = $body['endpoint'] ?? null;
        $p256dhKey = $body['p256dh_key'] ?? null;
        $authKey = $body['auth_key'] ?? null;
        $pushToken = $body['push_token'] ?? null;

        if (($platform === 'web' || $platform === 'desktop') && !$endpoint) {
            Response::error('endpoint is required for web/desktop push', 400);
            return;
        }

        $subscription = DeviceRepository::upsertSubscription(
            $userId,
            $spaceId,
            $deviceId,
            $platform,
            $body['device_name'] ?? null,
            $endpoint,
            $p256dhKey,
            $authKey,
            $pushToken
        );

        Response::json(['subscription' => $subscription]);
    }

    /**
     * DELETE /api/devices/{subscriptionId}
     * Remove a push subscription.
     */
    public function unregister(int $subscriptionId): void
    {
        $userId = Request::requireUserId();
        $removed = DeviceRepository::remove($subscriptionId, $userId);

        if (!$removed) {
            Response::error('Subscription not found', 404);
            return;
        }

        Response::json(['ok' => true]);
    }

    /**
     * POST /api/devices/{subscriptionId}/deactivate
     * Temporarily deactivate push delivery for a device.
     */
    public function deactivate(int $subscriptionId): void
    {
        $userId = Request::requireUserId();
        $ok = DeviceRepository::deactivate($subscriptionId, $userId);

        if (!$ok) {
            Response::error('Subscription not found', 404);
            return;
        }

        Response::json(['ok' => true]);
    }

    /**
     * GET /api/spaces/{spaceId}/push/vapid-key
     * Get the public VAPID key for the space (for subscribing in the browser).
     */
    public function vapidKey(int $spaceId): void
    {
        Request::requireUserId();

        $keys = PushService::generateVapidKeys($spaceId);
        Response::json(['public_key' => $keys['public_key']]);
    }

    /**
     * POST /api/devices/sync
     * Fetch missed events since the last known cursor.
     *
     * Body: { device_id, space_id, limit? }
     * Response: { events: [...], cursor: int }
     */
    public function sync(): void
    {
        $userId = Request::requireUserId();
        $body = Request::jsonBody();

        $deviceId = trim($body['device_id'] ?? '');
        if (!$deviceId) {
            Response::error('device_id is required', 400);
            return;
        }

        $spaceId = (int) ($body['space_id'] ?? 0);
        if ($spaceId <= 0) {
            Response::error('space_id is required', 400);
            return;
        }

        $limit = min(max((int) ($body['limit'] ?? 200), 1), 500);

        $events = DeviceRepository::getMissedEvents($userId, $deviceId, $spaceId, $limit);

        // Decode JSON payloads
        foreach ($events as &$event) {
            if (is_string($event['payload'])) {
                $event['payload'] = json_decode($event['payload'], true);
            }
        }
        unset($event);

        // Advance cursor to the last event ID
        $lastEventId = 0;
        if (!empty($events)) {
            $lastEventId = (int) $events[count($events) - 1]['id'];
            DeviceRepository::updateSyncCursor($userId, $deviceId, $spaceId, $lastEventId);
        }

        Response::json([
            'events' => $events,
            'cursor' => $lastEventId,
            'has_more' => count($events) === $limit,
        ]);
    }

    /**
     * POST /api/devices/sync/ack
     * Acknowledge events up to a given cursor (without fetching).
     *
     * Body: { device_id, space_id, cursor }
     */
    public function syncAck(): void
    {
        $userId = Request::requireUserId();
        $body = Request::jsonBody();

        $deviceId = trim($body['device_id'] ?? '');
        $spaceId = (int) ($body['space_id'] ?? 0);
        $cursor = (int) ($body['cursor'] ?? 0);

        if (!$deviceId || $spaceId <= 0 || $cursor <= 0) {
            Response::error('device_id, space_id, and cursor are required', 400);
            return;
        }

        DeviceRepository::updateSyncCursor($userId, $deviceId, $spaceId, $cursor);
        Response::json(['ok' => true]);
    }
}

<?php

declare(strict_types=1);

namespace App\Jobs\Handlers;

use App\Jobs\JobHandler;
use App\Repositories\DeviceRepository;
use App\Repositories\NotificationRepository;
use App\Services\PushService;
use App\Support\Logger;

/**
 * Sends push notifications to all registered devices of a user.
 *
 * Dispatched whenever a notification is created (piggybacks on the existing
 * notification.dispatch flow).
 *
 * Payload:
 *   user_id      – recipient
 *   space_id     – space context
 *   notification_id – the notification to send push for
 *
 * Idempotent: delivery log prevents duplicate sends per subscription+notification.
 */
final class PushSendHandler implements JobHandler
{
    public function handle(array $payload): void
    {
        $userId = (int) ($payload['user_id'] ?? 0);
        $spaceId = (int) ($payload['space_id'] ?? 0);
        $notificationId = isset($payload['notification_id']) ? (int) $payload['notification_id'] : null;

        if ($userId <= 0 || $spaceId <= 0) {
            Logger::warning('push.send.invalid_payload', $payload);
            return;
        }

        // Check if user has any active push subscriptions
        $subscriptions = DeviceRepository::activeForUserInSpace($userId, $spaceId);
        if (empty($subscriptions)) {
            return; // No devices registered, nothing to send
        }

        // If we have a notification ID, fetch it for the push payload
        $notification = null;
        if ($notificationId) {
            $notification = NotificationRepository::find($notificationId);
        }

        if (!$notification) {
            // Build a minimal payload from the job data
            $notification = $payload;
        }

        $pushPayload = PushService::buildPayload($notification);

        $result = PushService::sendToUser($userId, $spaceId, $pushPayload, $notificationId);

        Logger::info('push.send.completed', [
            'user_id' => $userId,
            'notification_id' => $notificationId,
            'sent' => $result['sent'],
            'failed' => $result['failed'],
        ]);

        if ($result['failed'] > 0) {
            Logger::warning('push.send.failures', [
                'errors' => $result['errors'],
            ]);
        }
    }
}

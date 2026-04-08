<?php

declare(strict_types=1);

namespace App\Jobs\Handlers;

use App\Jobs\JobHandler;
use App\Repositories\ConversationRepository;
use App\Repositories\EventRepository;
use App\Repositories\MessageRepository;
use App\Repositories\NotificationRepository;
use App\Repositories\ThreadRepository;

/**
 * Dispatches a single notification and publishes the realtime event.
 *
 * Idempotent: checks if the notification already exists before creating.
 *
 * Payload:
 *   user_id, type, actor_id, message_id?, channel_id?, conversation_id?, thread_id?, data?
 */
final class NotificationDispatchHandler implements JobHandler
{
    public function handle(array $payload): void
    {
        $userId         = (int) $payload['user_id'];
        $type           = $payload['type'];
        $actorId        = (int) $payload['actor_id'];
        $messageId      = isset($payload['message_id']) ? (int) $payload['message_id'] : null;
        $channelId      = isset($payload['channel_id']) ? (int) $payload['channel_id'] : null;
        $conversationId = isset($payload['conversation_id']) ? (int) $payload['conversation_id'] : null;
        $threadId       = isset($payload['thread_id']) ? (int) $payload['thread_id'] : null;
        $data           = $payload['data'] ?? null;

        // Idempotency: if a notification_id was already passed, check it exists
        if (isset($payload['notification_id'])) {
            $existing = NotificationRepository::find((int) $payload['notification_id']);
            if ($existing) {
                // Already created — just ensure the event was published
                EventRepository::publish('notification.created', "user:$userId", $existing);
                return;
            }
        }

        $notification = NotificationRepository::create(
            $userId,
            $type,
            $actorId,
            $messageId,
            $channelId,
            $conversationId,
            $threadId,
            $data
        );

        EventRepository::publish('notification.created', "user:$userId", $notification);
    }
}

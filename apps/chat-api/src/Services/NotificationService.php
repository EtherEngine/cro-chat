<?php

declare(strict_types=1);

namespace App\Services;

use App\Repositories\ConversationRepository;
use App\Repositories\EventRepository;
use App\Repositories\MessageRepository;
use App\Repositories\NotificationRepository;
use App\Repositories\ThreadRepository;
use App\Support\SpacePolicy;

final class NotificationService
{
    // Notification types
    public const TYPE_MENTION = 'mention';
    public const TYPE_DM = 'dm';
    public const TYPE_THREAD_REPLY = 'thread_reply';
    public const TYPE_REACTION = 'reaction';

    /** When true, notifications are dispatched via the job queue instead of synchronously. */
    private static bool $async = false;

    /** When true, push notifications are dispatched alongside in-app notifications. */
    private static bool $pushEnabled = false;

    public static function enableAsync(bool $enabled = true): void
    {
        self::$async = $enabled;
    }

    public static function enablePush(bool $enabled = true): void
    {
        self::$pushEnabled = $enabled;
    }

    /**
     * Notify users who were @mentioned in a message.
     * Called from MentionService::processMentions() after storing mentions.
     *
     * @param int[] $mentionedUserIds  Already filtered (no self, room-scoped)
     */
    public static function notifyMentions(
        int $messageId,
        int $actorId,
        array $mentionedUserIds,
        ?int $channelId,
        ?int $conversationId,
        ?int $threadId
    ): void {
        $spaceId = SpacePolicy::resolveSpaceId($channelId, $conversationId);

        foreach ($mentionedUserIds as $uid) {
            self::createAndPublish(
                $uid,
                self::TYPE_MENTION,
                $actorId,
                $messageId,
                $channelId,
                $conversationId,
                $threadId,
                null,
                $spaceId
            );
        }
    }

    /**
     * Notify other conversation members about a new DM.
     * Skips the sender (no self-notification).
     */
    public static function notifyDm(
        int $messageId,
        int $senderId,
        int $conversationId
    ): void {
        $members = ConversationRepository::otherMembers($conversationId, $senderId);
        $spaceId = SpacePolicy::resolveSpaceId(null, $conversationId);

        foreach ($members as $member) {
            self::createAndPublish(
                (int) $member['id'],
                self::TYPE_DM,
                $senderId,
                $messageId,
                null,
                $conversationId,
                null,
                null,
                $spaceId
            );
        }
    }

    /**
     * Notify the thread's root message author and other thread participants
     * about a new thread reply. Skips the replier (no self-notification).
     */
    public static function notifyThreadReply(
        int $messageId,
        int $replierId,
        int $threadId,
        ?int $channelId,
        ?int $conversationId
    ): void {
        $thread = ThreadRepository::find($threadId);
        if (!$thread) {
            return;
        }

        $spaceId = SpacePolicy::resolveSpaceId($channelId, $conversationId);

        // Collect users to notify: root message author + all prior repliers
        $rootMsg = MessageRepository::findBasic($thread['root_message_id']);
        $notifyIds = [];

        if ($rootMsg && (int) $rootMsg['user_id'] !== $replierId) {
            $notifyIds[] = (int) $rootMsg['user_id'];
        }

        // Get unique repliers in this thread (excluding current replier)
        $repliers = self::threadParticipants($threadId, $replierId);
        foreach ($repliers as $uid) {
            $notifyIds[] = $uid;
        }

        $notifyIds = array_unique($notifyIds);

        foreach ($notifyIds as $uid) {
            self::createAndPublish(
                $uid,
                self::TYPE_THREAD_REPLY,
                $replierId,
                $messageId,
                $channelId,
                $conversationId,
                $threadId,
                null,
                $spaceId
            );
        }
    }

    /**
     * Notify the message author about a reaction.
     * Skips if reactor is the message author (no self-notification).
     */
    public static function notifyReaction(
        int $messageId,
        int $reactorId,
        string $emoji,
        ?int $channelId,
        ?int $conversationId
    ): void {
        $msg = MessageRepository::findBasic($messageId);
        if (!$msg) {
            return;
        }

        $authorId = (int) $msg['user_id'];

        // No self-notification
        if ($authorId === $reactorId) {
            return;
        }

        self::createAndPublish(
            $authorId,
            self::TYPE_REACTION,
            $reactorId,
            $messageId,
            $channelId,
            $conversationId,
            $msg['thread_id'],
            ['emoji' => $emoji],
            SpacePolicy::resolveSpaceId($channelId, $conversationId)
        );
    }

    // ── Core ──────────────────────────────────

    private static function createAndPublish(
        int $userId,
        string $type,
        int $actorId,
        ?int $messageId,
        ?int $channelId,
        ?int $conversationId,
        ?int $threadId,
        ?array $data = null,
        ?int $spaceId = null
    ): void {
        if (self::$async) {
            $payload = array_filter([
                'user_id' => $userId,
                'type' => $type,
                'actor_id' => $actorId,
                'message_id' => $messageId,
                'channel_id' => $channelId,
                'conversation_id' => $conversationId,
                'thread_id' => $threadId,
                'space_id' => $spaceId,
                'data' => $data,
            ], fn($v) => $v !== null);

            JobService::dispatch(
                'notification.dispatch',
                $payload,
                'notifications',
                3,
                50
            );
            return;
        }

        $notification = NotificationRepository::create(
            $userId,
            $type,
            $actorId,
            $messageId,
            $channelId,
            $conversationId,
            $threadId,
            $data,
            $spaceId
        );

        // Publish via domain events for realtime delivery (user-scoped room)
        EventRepository::publish('notification.created', "user:$userId", $notification);

        // Dispatch push notification to registered devices
        if (self::$pushEnabled) {
            JobService::dispatch(
                'push.send',
                [
                    'user_id' => $userId,
                    'space_id' => $spaceId,
                    'notification_id' => (int) $notification['id'],
                ],
                'notifications',
                3,
                30
            );
        }
    }

    /**
     * Get distinct user IDs who have replied in a thread, excluding a given user.
     */
    private static function threadParticipants(int $threadId, int $excludeUserId): array
    {
        $db = \App\Support\Database::connection();
        $stmt = $db->prepare(
            'SELECT DISTINCT user_id FROM messages
             WHERE thread_id = ? AND user_id != ? AND deleted_at IS NULL'
        );
        $stmt->execute([$threadId, $excludeUserId]);
        return array_map(fn($r) => (int) $r['user_id'], $stmt->fetchAll());
    }
}

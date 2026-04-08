<?php

declare(strict_types=1);

namespace App\Services;

use App\Exceptions\ApiException;
use App\Repositories\ChannelRepository;
use App\Repositories\ConversationRepository;
use App\Repositories\EventRepository;
use App\Repositories\MessageRepository;
use App\Repositories\PinRepository;
use App\Support\Database;

final class PinService
{
    /**
     * Pin a message.
     * - Message must exist and not be deleted
     * - User must have access to the channel/conversation
     * - Channel members can pin in channels; conversation members in DMs
     */
    public static function pin(int $messageId, int $userId): array
    {
        $message = self::requireMessage($messageId);
        $room = self::requireAccessAndRoom($message, $userId);

        $channelId      = $message['channel_id'];
        $conversationId = $message['conversation_id'];

        Database::transaction(function () use ($messageId, $channelId, $conversationId, $userId, $room) {
            $pinned = PinRepository::pin($messageId, $channelId, $conversationId, $userId);
            if ($pinned) {
                EventRepository::publish('message.pinned', $room, [
                    'message_id' => $messageId,
                    'pinned_by'  => $userId,
                ]);
            }
        });

        return ['pinned' => true, 'message_id' => $messageId];
    }

    /**
     * Unpin a message.
     * - User must have access to the channel/conversation
     */
    public static function unpin(int $messageId, int $userId): array
    {
        $message = self::requireMessage($messageId);
        $room = self::requireAccessAndRoom($message, $userId);

        Database::transaction(function () use ($messageId, $userId, $room) {
            $unpinned = PinRepository::unpin($messageId);
            if ($unpinned) {
                EventRepository::publish('message.unpinned', $room, [
                    'message_id' => $messageId,
                    'unpinned_by' => $userId,
                ]);
            }
        });

        return ['pinned' => false, 'message_id' => $messageId];
    }

    /**
     * List pins for a channel.
     */
    public static function forChannel(int $channelId, int $userId): array
    {
        $channel = ChannelRepository::find($channelId);
        if (!$channel) {
            throw ApiException::notFound('Channel nicht gefunden', 'CHANNEL_NOT_FOUND');
        }
        ChannelService::requireAccess($channel, $userId);

        return PinRepository::forChannel($channelId);
    }

    /**
     * List pins for a conversation.
     */
    public static function forConversation(int $conversationId, int $userId): array
    {
        ConversationService::requireMember($conversationId, $userId);
        return PinRepository::forConversation($conversationId);
    }

    // ── Helpers ─────────────────────────────────

    private static function requireMessage(int $messageId): array
    {
        $message = MessageRepository::findBasic($messageId);
        if (!$message) {
            throw ApiException::notFound('Nachricht nicht gefunden', 'MESSAGE_NOT_FOUND');
        }
        if ($message['deleted_at'] !== null) {
            throw ApiException::validation('Gelöschte Nachricht kann nicht gepinnt werden', 'MESSAGE_DELETED');
        }
        return $message;
    }

    /**
     * Verify user access and return the event room string.
     */
    private static function requireAccessAndRoom(array $message, int $userId): string
    {
        if ($message['channel_id']) {
            $channel = ChannelRepository::find((int) $message['channel_id']);
            if (!$channel) {
                throw ApiException::notFound('Channel nicht gefunden', 'CHANNEL_NOT_FOUND');
            }
            ChannelService::requireAccess($channel, $userId);
            return "channel:{$message['channel_id']}";
        }

        if ($message['conversation_id']) {
            ConversationService::requireMember((int) $message['conversation_id'], $userId);
            return "conversation:{$message['conversation_id']}";
        }

        throw ApiException::validation('Nachricht gehört zu keinem Channel oder Gespräch', 'NO_CONTEXT');
    }
}

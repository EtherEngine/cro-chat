<?php

declare(strict_types=1);

namespace App\Services;

use App\Exceptions\ApiException;
use App\Repositories\MessageRepository;
use App\Repositories\SavedMessageRepository;
use App\Services\ChannelService;
use App\Services\ConversationService;
use App\Repositories\ChannelRepository;

final class SavedMessageService
{
    /**
     * Save a message for the current user.
     * Message must exist (deleted messages can still be bookmarked).
     */
    public static function save(int $messageId, int $userId): array
    {
        $message = self::requireMessageExists($messageId);
        self::requireMessageAccess($message, $userId);
        SavedMessageRepository::save($userId, $messageId);
        return ['saved' => true, 'message_id' => $messageId];
    }

    /**
     * Remove a saved message.
     */
    public static function unsave(int $messageId, int $userId): array
    {
        SavedMessageRepository::unsave($userId, $messageId);
        return ['saved' => false, 'message_id' => $messageId];
    }

    /**
     * List all saved messages for a user.
     */
    public static function forUser(int $userId, ?int $spaceId = null): array
    {
        return SavedMessageRepository::forUser($userId, $spaceId);
    }

    private static function requireMessageExists(int $messageId): array
    {
        $message = MessageRepository::findBasic($messageId);
        if (!$message) {
            throw ApiException::notFound('Nachricht nicht gefunden', 'MESSAGE_NOT_FOUND');
        }
        return $message;
    }

    /**
     * Verify the user has access to the message's channel or conversation.
     */
    private static function requireMessageAccess(array $message, int $userId): void
    {
        if (!empty($message['channel_id'])) {
            $channel = ChannelRepository::find((int) $message['channel_id']);
            if ($channel) {
                ChannelService::requireAccess($channel, $userId);
                return;
            }
        }

        if (!empty($message['conversation_id'])) {
            ConversationService::requireMember((int) $message['conversation_id'], $userId);
            return;
        }
    }
}

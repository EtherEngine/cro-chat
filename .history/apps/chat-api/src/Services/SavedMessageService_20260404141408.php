<?php

declare(strict_types=1);

namespace App\Services;

use App\Exceptions\ApiException;
use App\Repositories\MessageRepository;
use App\Repositories\SavedMessageRepository;

final class SavedMessageService
{
    /**
     * Save a message for the current user.
     * Message must exist (deleted messages can still be bookmarked).
     */
    public static function save(int $messageId, int $userId): array
    {
        self::requireMessageExists($messageId);
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
    public static function forUser(int $userId): array
    {
        return SavedMessageRepository::forUser($userId);
    }

    private static function requireMessageExists(int $messageId): void
    {
        $message = MessageRepository::findBasic($messageId);
        if (!$message) {
            throw ApiException::notFound('Nachricht nicht gefunden', 'MESSAGE_NOT_FOUND');
        }
    }
}

<?php

namespace App\Services;

use App\Exceptions\ApiException;
use App\Repositories\MessageRepository;
use App\Repositories\ChannelRepository;
use App\Repositories\ConversationRepository;
use App\Repositories\EventRepository;
use App\Repositories\SpaceRepository;
use App\Support\Database;
use App\Services\MentionService;

final class MessageService
{
    private const MAX_BODY_LENGTH = 10_000;

    // ── Validation ────────────────────────────

    /**
     * Validates and sanitises message input.
     * Returns trimmed body on success, halts with 422 on failure.
     */
    private static function validateBody(array $input): string
    {
        $body = trim($input['body'] ?? '');

        if ($body === '') {
            throw ApiException::validation('Nachricht darf nicht leer sein', 'MESSAGE_EMPTY');
        }
        if (mb_strlen($body) > self::MAX_BODY_LENGTH) {
            throw ApiException::validation(
                'Nachricht darf maximal ' . self::MAX_BODY_LENGTH . ' Zeichen lang sein',
                'MESSAGE_TOO_LONG'
            );
        }

        return $body;
    }

    /**
     * If reply_to_id is given, validates it exists and belongs to the same context.
     */
    private static function validateReplyTo(
        ?int $replyToId,
        ?int $channelId,
        ?int $conversationId
    ): void {
        if ($replyToId === null) {
            return;
        }

        $parent = MessageRepository::find($replyToId);
        if (!$parent || $parent['deleted_at'] !== null) {
            throw ApiException::notFound('Elternnachricht nicht gefunden', 'REPLY_PARENT_NOT_FOUND');
        }

        // Must be in the same channel / conversation
        if ($channelId !== null && (int) $parent['channel_id'] !== $channelId) {
            throw ApiException::validation('reply_to muss im selben Channel sein', 'REPLY_WRONG_CHANNEL');
        }
        if ($conversationId !== null && (int) $parent['conversation_id'] !== $conversationId) {
            throw ApiException::validation('reply_to muss in derselben Konversation sein', 'REPLY_WRONG_CONVERSATION');
        }
    }

    // ── Channel Messages ──────────────────────

    public static function listChannel(
        int $channelId,
        int $userId,
        ?int $before = null,
        ?int $after = null,
        int $limit = 50
    ): array {
        $channel = ChannelRepository::find($channelId);
        if (!$channel) {
            throw ApiException::notFound('Channel nicht gefunden', 'CHANNEL_NOT_FOUND');
        }
        ChannelService::requireAccess($channel, $userId);

        return MessageRepository::forChannel($channelId, $before, $after, $limit);
    }

    public static function createChannel(
        int $channelId,
        int $userId,
        array $input
    ): array {
        $body = self::validateBody($input);

        $channel = ChannelRepository::find($channelId);
        if (!$channel) {
            throw ApiException::notFound('Channel nicht gefunden', 'CHANNEL_NOT_FOUND');
        }

        if (!ChannelRepository::isMember($channelId, $userId)) {
            throw ApiException::forbidden('Kein Mitglied dieses Channels', 'CHANNEL_MEMBER_REQUIRED');
        }

        $replyToId = isset($input['reply_to_id']) ? (int) $input['reply_to_id'] : null;
        self::validateReplyTo($replyToId, $channelId, null);

        $idempotencyKey = isset($input['idempotency_key'])
            ? substr(trim($input['idempotency_key']), 0, 36)
            : null;

        return Database::transaction(function () use ($userId, $body, $channelId, $replyToId, $idempotencyKey) {
            $message = MessageRepository::create(
                $userId,
                $body,
                $channelId,
                null,
                $replyToId,
                $idempotencyKey
            );
            $room = "channel:$channelId";
            EventRepository::publish('message.created', $room, $message);
            MentionService::processMentions($message['id'], $body, $userId, $channelId, null, $room);
            return $message;
        });
    }

    // ── Conversation Messages ─────────────────

    public static function listConversation(
        int $conversationId,
        int $userId,
        ?int $before = null,
        ?int $after = null,
        int $limit = 50
    ): array {
        self::requireConversationMember($conversationId, $userId);
        return MessageRepository::forConversation($conversationId, $before, $after, $limit);
    }

    public static function createConversation(
        int $conversationId,
        int $userId,
        array $input
    ): array {
        $body = self::validateBody($input);
        self::requireConversationMember($conversationId, $userId);

        $replyToId = isset($input['reply_to_id']) ? (int) $input['reply_to_id'] : null;
        self::validateReplyTo($replyToId, null, $conversationId);

        $idempotencyKey = isset($input['idempotency_key'])
            ? substr(trim($input['idempotency_key']), 0, 36)
            : null;

        return Database::transaction(function () use ($userId, $body, $conversationId, $replyToId, $idempotencyKey) {
            $message = MessageRepository::create(
                $userId,
                $body,
                null,
                $conversationId,
                $replyToId,
                $idempotencyKey
            );
            EventRepository::publish('message.created', "conversation:$conversationId", $message);
            return $message;
        });
    }

    // ── Edit ──────────────────────────────────

    public static function update(int $messageId, int $userId, array $input): array
    {
        $body = self::validateBody($input);
        $msg = self::findOrFail($messageId);

        // Only sender or space admin may edit
        if ((int) $msg['user_id'] !== $userId) {
            if (!self::isSpaceAdminForMessage($msg, $userId)) {
                throw ApiException::forbidden('Nur eigene Nachrichten bearbeiten', 'MESSAGE_EDIT_DENIED');
            }
        }

        $updated = MessageRepository::update($messageId, $body, $userId);
        $room = self::roomForMessage($updated);
        EventRepository::publish('message.updated', $room, $updated);
        return $updated;
    }

    // ── Delete ────────────────────────────────

    public static function delete(int $messageId, int $userId): void
    {
        $msg = self::findOrFail($messageId);

        $canDelete = ((int) $msg['user_id'] === $userId)
            || self::isSpaceAdminForMessage($msg, $userId);

        if (!$canDelete) {
            throw ApiException::forbidden('Keine Berechtigung', 'MESSAGE_DELETE_DENIED');
        }

        MessageRepository::softDelete($messageId);
        $room = self::roomForMessage($msg);
        EventRepository::publish('message.deleted', $room, ['id' => $messageId]);
    }
    // ── Edit History ──────────────────────────

    public static function editHistory(int $messageId, int $userId): array
    {
        // Only accessible if user has access to the context
        $msg = self::findOrFail($messageId);
        self::requireContextAccess($msg, $userId);

        return MessageRepository::editHistory($messageId);
    }

    // ── Helpers ───────────────────────────────

    private static function findOrFail(int $messageId): array
    {
        $msg = MessageRepository::findBasic($messageId);
        if (!$msg || $msg['deleted_at'] !== null) {
            throw ApiException::notFound('Nachricht nicht gefunden', 'MESSAGE_NOT_FOUND');
        }
        return $msg;
    }

    private static function requireConversationMember(int $conversationId, int $userId): void
    {
        if (!ConversationRepository::isMember($conversationId, $userId)) {
            throw ApiException::forbidden('Kein Zugriff auf dieses Gespräch', 'CONVERSATION_ACCESS_DENIED');
        }
    }

    /**
     * Checks whether the user has read access to the message's channel or conversation.
     */
    private static function requireContextAccess(array $msg, int $userId): void
    {
        if ($msg['channel_id']) {
            $channel = ChannelRepository::find((int) $msg['channel_id']);
            if ($channel) {
                ChannelService::requireAccess($channel, $userId);
                return;
            }
        }
        if ($msg['conversation_id']) {
            self::requireConversationMember((int) $msg['conversation_id'], $userId);
            return;
        }
        throw ApiException::forbidden('Kein Zugriff', 'MESSAGE_ACCESS_DENIED');
    }
    /**
     * Returns true if the user is admin/owner of the space the message belongs to.
     */
    private static function isSpaceAdminForMessage(array $msg, int $userId): bool
    {
        if ($msg['channel_id']) {
            $channel = ChannelRepository::find((int) $msg['channel_id']);
            if ($channel) {
                return SpaceRepository::isAdminOrOwner((int) $channel['space_id'], $userId);
            }
        }
        if ($msg['conversation_id']) {
            $conv = ConversationRepository::find((int) $msg['conversation_id']);
            if ($conv && isset($conv['space_id'])) {
                return SpaceRepository::isAdminOrOwner((int) $conv['space_id'], $userId);
            }
        }
        return false;
    }

    /**
     * Derive the room string for a message (channel:X or conversation:Y).
     */
    private static function roomForMessage(array $msg): string
    {
        if ($msg['channel_id']) {
            return 'channel:' . (int) $msg['channel_id'];
        }
        return 'conversation:' . (int) $msg['conversation_id'];
    }
}

<?php

namespace App\Services;

use App\Repositories\MessageRepository;
use App\Repositories\ChannelRepository;
use App\Repositories\ConversationRepository;
use App\Repositories\SpaceRepository;
use App\Support\Response;

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
            Response::error('Nachricht darf nicht leer sein', 422);
        }
        if (mb_strlen($body) > self::MAX_BODY_LENGTH) {
            Response::error(
                'Nachricht darf maximal ' . self::MAX_BODY_LENGTH . ' Zeichen lang sein',
                422
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
            Response::error('Elternnachricht nicht gefunden', 404);
        }

        // Must be in the same channel / conversation
        if ($channelId !== null && (int) $parent['channel_id'] !== $channelId) {
            Response::error('reply_to muss im selben Channel sein', 422);
        }
        if ($conversationId !== null && (int) $parent['conversation_id'] !== $conversationId) {
            Response::error('reply_to muss in derselben Konversation sein', 422);
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
            Response::error('Channel nicht gefunden', 404);
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
            Response::error('Channel nicht gefunden', 404);
        }

        if (!ChannelRepository::isMember($channelId, $userId)) {
            Response::error('Kein Mitglied dieses Channels', 403);
        }

        $replyToId = isset($input['reply_to_id']) ? (int) $input['reply_to_id'] : null;
        self::validateReplyTo($replyToId, $channelId, null);

        $idempotencyKey = isset($input['idempotency_key'])
            ? substr(trim($input['idempotency_key']), 0, 36)
            : null;

        return MessageRepository::create(
            $userId,
            $body,
            $channelId,
            null,
            $replyToId,
            $idempotencyKey
        );
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

        return MessageRepository::create(
            $userId,
            $body,
            null,
            $conversationId,
            $replyToId,
            $idempotencyKey
        );
    }

    // ── Edit ──────────────────────────────────

    public static function update(int $messageId, int $userId, array $input): array
    {
        $body = self::validateBody($input);
        $msg = self::findOrFail($messageId);

        // Only sender or space admin may edit
        if ((int) $msg['user_id'] !== $userId) {
            if (!self::isSpaceAdminForMessage($msg, $userId)) {
                Response::error('Nur eigene Nachrichten bearbeiten', 403);
            }
        }

        return MessageRepository::update($messageId, $body, $userId);
    }

    // ── Delete ────────────────────────────────

    public static function delete(int $messageId, int $userId): void
    {
        $msg = self::findOrFail($messageId);

        // Owner can always delete
        if ((int) $msg['user_id'] === $userId) {
            MessageRepository::softDelete($messageId);
            return;
        }

        // Space admin/owner can delete any message
        if (self::isSpaceAdminForMessage($msg, $userId)) {
            MessageRepository::softDelete($messageId);
            return;
        }

        Response::error('Keine Berechtigung', 403);
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
        $msg = MessageRepository::find($messageId);
        if (!$msg || $msg['deleted_at'] !== null) {
            Response::error('Nachricht nicht gefunden', 404);
        }
        return $msg;
    }

    private static function requireConversationMember(int $conversationId, int $userId): void
    {
        if (!ConversationRepository::isMember($conversationId, $userId)) {
            Response::error('Kein Zugriff auf dieses Gespräch', 403);
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
        Response::error('Kein Zugriff', 403);
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
}

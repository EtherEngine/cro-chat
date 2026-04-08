<?php

namespace App\Services;

use App\Repositories\MessageRepository;
use App\Repositories\ChannelRepository;
use App\Repositories\ConversationRepository;
use App\Repositories\SpaceRepository;
use App\Support\Response;

final class MessageService
{
    // ── Channel Messages ──────────────────────

    public static function listChannel(int $channelId, int $userId, ?int $before = null): array
    {
        $channel = ChannelRepository::find($channelId);
        if (!$channel)
            Response::error('Channel nicht gefunden', 404);
        ChannelService::requireAccess($channel, $userId);

        return MessageRepository::forChannel($channelId, 100, $before);
    }

    public static function createChannel(int $channelId, int $userId, string $body): array
    {
        $channel = ChannelRepository::find($channelId);
        if (!$channel)
            Response::error('Channel nicht gefunden', 404);

        // Must be member to post
        if (!ChannelRepository::isMember($channelId, $userId)) {
            Response::error('Kein Mitglied dieses Channels', 403);
        }

        return MessageRepository::create($userId, $body, $channelId, null);
    }

    // ── Conversation Messages ─────────────────

    public static function listConversation(int $conversationId, int $userId, ?int $before = null): array
    {
        self::requireConversationMember($conversationId, $userId);
        return MessageRepository::forConversation($conversationId, 100, $before);
    }

    public static function createConversation(int $conversationId, int $userId, string $body): array
    {
        self::requireConversationMember($conversationId, $userId);
        return MessageRepository::create($userId, $body, null, $conversationId);
    }

    // ── Edit / Delete ─────────────────────────

    public static function update(int $messageId, int $userId, string $body): array
    {
        $msg = self::findOrFail($messageId);

        if ((int) $msg['user_id'] !== $userId) {
            Response::error('Nur eigene Nachrichten bearbeiten', 403);
        }

        return MessageRepository::update($messageId, $body);
    }

    public static function delete(int $messageId, int $userId): void
    {
        $msg = self::findOrFail($messageId);

        // Owner can always delete their own message
        if ((int) $msg['user_id'] === $userId) {
            MessageRepository::softDelete($messageId);
            return;
        }

        // Space admin/owner can delete any message in their space
        if ($msg['channel_id']) {
            $channel = ChannelRepository::find((int) $msg['channel_id']);
            if ($channel && SpaceRepository::isAdminOrOwner((int) $channel['space_id'], $userId)) {
                MessageRepository::softDelete($messageId);
                return;
            }
        }

        Response::error('Keine Berechtigung', 403);
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
}

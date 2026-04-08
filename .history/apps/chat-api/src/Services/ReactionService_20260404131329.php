<?php

declare(strict_types=1);

namespace App\Services;

use App\Exceptions\ApiException;
use App\Repositories\ChannelRepository;
use App\Repositories\ConversationRepository;
use App\Repositories\EventRepository;
use App\Repositories\MessageRepository;
use App\Repositories\ReactionRepository;
use App\Services\NotificationService;

final class ReactionService
{
    private const MAX_EMOJI_LENGTH = 32;

    // Allowed pattern: standard unicode emoji or common shortcodes like :thumbsup:
    private const EMOJI_PATTERN = '/^[\x{1F000}-\x{1FFFF}\x{2600}-\x{27BF}\x{FE00}-\x{FE0F}\x{200D}\x{20E3}\x{E0020}-\x{E007F}\x{2702}-\x{27B0}\x{1F900}-\x{1F9FF}\x{1FA00}-\x{1FA6F}\x{1FA70}-\x{1FAFF}\x{2194}-\x{2199}\x{231A}-\x{231B}\x{23E9}-\x{23F3}\x{23F8}-\x{23FA}\x{25AA}-\x{25AB}\x{25B6}\x{25C0}\x{25FB}-\x{25FE}\x{2600}-\x{2604}\x{2614}-\x{2615}\x{2648}-\x{2653}\x{267F}\x{2693}\x{26A1}\x{26AA}-\x{26AB}\x{26BD}-\x{26BE}\x{26C4}-\x{26C5}\x{26CE}\x{26D4}\x{26EA}\x{26F2}-\x{26F3}\x{26F5}\x{26FA}\x{26FD}\x{2702}\x{2705}\x{2708}-\x{270D}\x{270F}\x{2712}\x{2714}\x{2716}\x{271D}\x{2721}\x{2728}\x{2733}-\x{2734}\x{2744}\x{2747}\x{274C}\x{274E}\x{2753}-\x{2755}\x{2757}\x{2763}-\x{2764}\x{2795}-\x{2797}\x{27A1}\x{27B0}\x{2934}-\x{2935}\x{2B05}-\x{2B07}\x{2B1B}-\x{2B1C}\x{2B50}\x{2B55}\x{3030}\x{303D}\x{3297}\x{3299}\x{00A9}\x{00AE}][\x{FE00}-\x{FE0F}\x{200D}\x{20E3}\x{E0020}-\x{E007F}\x{1F3FB}-\x{1F3FF}\x{1F000}-\x{1FFFF}\x{2600}-\x{27BF}]*$|^:[a-z0-9_+-]+:$/u';

    /**
     * Add a reaction to a message.
     */
    public static function add(int $messageId, int $userId, array $input): array
    {
        $emoji = self::validateEmoji($input);
        $msg = self::findMessageOrFail($messageId);
        self::requireContextAccess($msg, $userId);

        $reaction = ReactionRepository::add($messageId, $userId, $emoji);
        if ($reaction === null) {
            throw ApiException::validation('Reaction bereits vorhanden', 'REACTION_DUPLICATE');
        }

        $room = self::roomForMessage($msg);
        EventRepository::publish('reaction.created', $room, [
            'message_id' => $messageId,
            'user_id' => $userId,
            'emoji' => $emoji,
        ]);

        NotificationService::notifyReaction($messageId, $userId, $emoji, $msg['channel_id'], $msg['conversation_id']);

        return ReactionRepository::forMessage($messageId);
    }

    /**
     * Remove a reaction from a message.
     */
    public static function remove(int $messageId, int $userId, array $input): array
    {
        $emoji = self::validateEmoji($input);
        $msg = self::findMessageOrFail($messageId);
        self::requireContextAccess($msg, $userId);

        $removed = ReactionRepository::remove($messageId, $userId, $emoji);
        if (!$removed) {
            throw ApiException::notFound('Reaction nicht gefunden', 'REACTION_NOT_FOUND');
        }

        $room = self::roomForMessage($msg);
        EventRepository::publish('reaction.removed', $room, [
            'message_id' => $messageId,
            'user_id' => $userId,
            'emoji' => $emoji,
        ]);

        return ReactionRepository::forMessage($messageId);
    }

    /**
     * List aggregated reactions for a message.
     */
    public static function list(int $messageId, int $userId): array
    {
        $msg = self::findMessageOrFail($messageId);
        self::requireContextAccess($msg, $userId);
        return ReactionRepository::forMessage($messageId);
    }

    // ── Validation ────────────────────────────

    private static function validateEmoji(array $input): string
    {
        $emoji = trim($input['emoji'] ?? '');
        if ($emoji === '') {
            throw ApiException::validation('emoji ist erforderlich', 'EMOJI_REQUIRED');
        }
        if (mb_strlen($emoji) > self::MAX_EMOJI_LENGTH) {
            throw ApiException::validation('emoji zu lang', 'EMOJI_TOO_LONG');
        }
        if (!preg_match(self::EMOJI_PATTERN, $emoji)) {
            throw ApiException::validation('Ungültiges Emoji-Format', 'EMOJI_INVALID');
        }
        return $emoji;
    }

    // ── Helpers ───────────────────────────────

    private static function findMessageOrFail(int $messageId): array
    {
        $msg = MessageRepository::findBasic($messageId);
        if (!$msg || $msg['deleted_at'] !== null) {
            throw ApiException::notFound('Nachricht nicht gefunden', 'MESSAGE_NOT_FOUND');
        }
        return $msg;
    }

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
            if (!ConversationRepository::isMember((int) $msg['conversation_id'], $userId)) {
                throw ApiException::forbidden('Kein Zugriff auf dieses Gespräch', 'CONVERSATION_ACCESS_DENIED');
            }
            return;
        }
        throw ApiException::forbidden('Kein Zugriff', 'MESSAGE_ACCESS_DENIED');
    }

    private static function roomForMessage(array $msg): string
    {
        if ($msg['channel_id']) {
            return 'channel:' . (int) $msg['channel_id'];
        }
        return 'conversation:' . (int) $msg['conversation_id'];
    }
}

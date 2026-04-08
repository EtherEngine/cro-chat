<?php

declare(strict_types=1);

namespace App\Services;

use App\Exceptions\ApiException;
use App\Repositories\ChannelRepository;
use App\Repositories\ConversationRepository;
use App\Repositories\EventRepository;
use App\Repositories\MessageRepository;
use App\Repositories\ReadReceiptRepository;
use App\Repositories\SpaceRepository;
use App\Repositories\ThreadRepository;
use App\Support\Database;

final class ThreadService
{
    private const MAX_BODY_LENGTH = 10_000;

    // ── Load thread ───────────────────────────

    /**
     * GET /threads/{threadId} — returns thread info + paginated replies.
     */
    public static function getThread(
        int $threadId,
        int $userId,
        ?int $before = null,
        ?int $after = null,
        int $limit = 50
    ): array {
        $thread = self::findOrFail($threadId);
        self::requireThreadAccess($thread, $userId);

        $replies = MessageRepository::forThread($threadId, $before, $after, $limit);
        $rootMessage = MessageRepository::find($thread['root_message_id']);

        return [
            'thread' => $thread,
            'root_message' => $rootMessage,
            'messages' => $replies['messages'],
            'next_cursor' => $replies['next_cursor'],
            'has_more' => $replies['has_more'],
        ];
    }

    // ── Start thread (first reply to a message) ──

    /**
     * POST /messages/{messageId}/thread — creates thread if needed + first reply.
     */
    public static function startThread(int $messageId, int $userId, array $input): array
    {
        $body = self::validateBody($input);
        $rootMessage = MessageRepository::findBasic($messageId);

        if (!$rootMessage || $rootMessage['deleted_at'] !== null) {
            throw ApiException::notFound('Nachricht nicht gefunden', 'MESSAGE_NOT_FOUND');
        }

        // Root message itself must not be a thread reply
        if ($rootMessage['thread_id'] !== null) {
            throw ApiException::validation(
                'Thread-Antworten können keine eigenen Threads starten',
                'THREAD_NESTED_DENIED'
            );
        }

        self::requireContextAccess($rootMessage, $userId);
        self::requireMembership($rootMessage, $userId);

        $channelId = $rootMessage['channel_id'] ? (int) $rootMessage['channel_id'] : null;
        $conversationId = $rootMessage['conversation_id'] ? (int) $rootMessage['conversation_id'] : null;

        return Database::transaction(function () use ($messageId, $userId, $body, $channelId, $conversationId) {
            // Auto-create thread if not exists
            $thread = ThreadRepository::findByRootMessage($messageId);
            $isNew = $thread === null;

            if ($isNew) {
                $thread = ThreadRepository::create($messageId, $channelId, $conversationId, $userId);
            }

            $reply = MessageRepository::create(
                $userId,
                $body,
                $channelId,
                $conversationId,
                null,
                null,
                $thread['id']
            );

            ThreadRepository::incrementReplyCount($thread['id']);
            $thread = ThreadRepository::find($thread['id']);

            $room = self::roomForThread($thread);

            if ($isNew) {
                EventRepository::publish('thread.created', $room, [
                    'thread' => $thread,
                    'root_message_id' => $messageId,
                ]);
            }

            EventRepository::publish('thread.reply.created', $room, [
                'thread_id' => $thread['id'],
                'message' => $reply,
            ]);

            return ['thread' => $thread, 'message' => $reply];
        });
    }

    // ── Reply to existing thread ──────────────

    /**
     * POST /threads/{threadId}/replies
     */
    public static function createReply(int $threadId, int $userId, array $input): array
    {
        $body = self::validateBody($input);
        $thread = self::findOrFail($threadId);
        self::requireThreadAccess($thread, $userId);
        self::requireThreadMembership($thread, $userId);

        return Database::transaction(function () use ($thread, $userId, $body) {
            $reply = MessageRepository::create(
                $userId,
                $body,
                $thread['channel_id'],
                $thread['conversation_id'],
                null,
                null,
                $thread['id']
            );

            ThreadRepository::incrementReplyCount($thread['id']);

            $room = self::roomForThread($thread);
            EventRepository::publish('thread.reply.created', $room, [
                'thread_id' => $thread['id'],
                'message' => $reply,
            ]);

            return $reply;
        });
    }

    // ── Mark thread read ──────────────────────

    public static function markRead(int $threadId, int $userId, int $messageId): void
    {
        $thread = self::findOrFail($threadId);
        self::requireThreadAccess($thread, $userId);
        ReadReceiptRepository::markThreadRead($userId, $threadId, $messageId);
    }

    // ── Validation ────────────────────────────

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

    // ── Helpers ───────────────────────────────

    private static function findOrFail(int $threadId): array
    {
        $thread = ThreadRepository::find($threadId);
        if (!$thread) {
            throw ApiException::notFound('Thread nicht gefunden', 'THREAD_NOT_FOUND');
        }
        return $thread;
    }

    /**
     * Ensure user can view the thread's channel or conversation.
     */
    private static function requireThreadAccess(array $thread, int $userId): void
    {
        if ($thread['channel_id']) {
            $channel = ChannelRepository::find($thread['channel_id']);
            if ($channel) {
                ChannelService::requireAccess($channel, $userId);
                return;
            }
        }
        if ($thread['conversation_id']) {
            if (!ConversationRepository::isMember($thread['conversation_id'], $userId)) {
                throw ApiException::forbidden('Kein Zugriff auf dieses Gespräch', 'CONVERSATION_ACCESS_DENIED');
            }
            return;
        }
        throw ApiException::forbidden('Kein Zugriff', 'THREAD_ACCESS_DENIED');
    }

    /**
     * Ensure user is a member (for writing) of the thread's context.
     */
    private static function requireThreadMembership(array $thread, int $userId): void
    {
        if ($thread['channel_id']) {
            if (!ChannelRepository::isMember($thread['channel_id'], $userId)) {
                throw ApiException::forbidden('Kein Mitglied dieses Channels', 'CHANNEL_MEMBER_REQUIRED');
            }
            return;
        }
        if ($thread['conversation_id']) {
            if (!ConversationRepository::isMember($thread['conversation_id'], $userId)) {
                throw ApiException::forbidden('Kein Zugriff auf dieses Gespräch', 'CONVERSATION_ACCESS_DENIED');
            }
            return;
        }
    }

    /**
     * Require write-access: for channel messages, must be channel member;
     * for conversation messages, must be conversation member.
     */
    private static function requireMembership(array $msg, int $userId): void
    {
        if ($msg['channel_id']) {
            if (!ChannelRepository::isMember((int) $msg['channel_id'], $userId)) {
                throw ApiException::forbidden('Kein Mitglied dieses Channels', 'CHANNEL_MEMBER_REQUIRED');
            }
            return;
        }
        if ($msg['conversation_id']) {
            if (!ConversationRepository::isMember((int) $msg['conversation_id'], $userId)) {
                throw ApiException::forbidden('Kein Zugriff auf dieses Gespräch', 'CONVERSATION_ACCESS_DENIED');
            }
        }
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

    private static function roomForThread(array $thread): string
    {
        if ($thread['channel_id']) {
            return 'channel:' . $thread['channel_id'];
        }
        return 'conversation:' . $thread['conversation_id'];
    }
}

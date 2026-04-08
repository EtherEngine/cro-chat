<?php

declare(strict_types=1);

namespace App\Services;

use App\Repositories\ChannelRepository;
use App\Repositories\ConversationRepository;
use App\Repositories\EventRepository;
use App\Repositories\MentionRepository;
use App\Support\Database;

final class MentionService
{
    private const MAX_MENTIONS = 25;

    /**
     * Extract @display_name tokens from message body.
     * Matches @word tokens (word chars, dots, hyphens).
     */
    public static function parseMentions(string $body): array
    {
        if (!preg_match_all('/(?:^|(?<=\s))@([\w][\w.-]*)/u', $body, $matches)) {
            return [];
        }

        return array_values(array_unique($matches[1]));
    }

    /**
     * Resolve parsed @tokens to user IDs, filtered by room permissions.
     *
     * For public channels:  any space member can be mentioned.
     * For private channels: only channel members.
     * For conversations:    only conversation members.
     *
     * Returns array of user IDs (max MAX_MENTIONS).
     */
    public static function resolveMentions(
        array $rawNames,
        ?int $channelId,
        ?int $conversationId
    ): array {
        if (empty($rawNames)) {
            return [];
        }

        $db = Database::connection();

        if ($channelId !== null) {
            $channel = ChannelRepository::find($channelId);
            if (!$channel) {
                return [];
            }

            if ($channel['is_private']) {
                // Private channel: only channel members
                return self::resolveAgainstChannelMembers($rawNames, $channelId);
            }

            // Public channel: any space member
            return self::resolveAgainstSpaceMembers($rawNames, (int) $channel['space_id']);
        }

        if ($conversationId !== null) {
            return self::resolveAgainstConversationMembers($rawNames, $conversationId);
        }

        return [];
    }

    /**
     * Full pipeline: parse body → resolve → store → publish events.
     * Call inside a transaction after message creation.
     */
    public static function processMentions(
        int $messageId,
        string $body,
        int $authorId,
        ?int $channelId,
        ?int $conversationId,
        string $room
    ): void {
        $rawNames = self::parseMentions($body);
        $userIds = self::resolveMentions($rawNames, $channelId, $conversationId);

        // Don't mention yourself
        $userIds = array_values(array_filter($userIds, fn(int $uid) => $uid !== $authorId));

        if (empty($userIds)) {
            return;
        }

        // Cap to prevent abuse
        $userIds = array_slice($userIds, 0, self::MAX_MENTIONS);

        MentionRepository::store($messageId, $userIds);

        // Publish one event per mentioned user (for notifications)
        foreach ($userIds as $uid) {
            EventRepository::publish('mention.created', $room, [
                'message_id' => $messageId,
                'mentioned_user_id' => $uid,
                'author_id' => $authorId,
            ]);
        }
    }

    /**
     * Re-process mentions after message edit.
     */
    public static function reprocessMentions(
        int $messageId,
        string $newBody,
        int $authorId,
        ?int $channelId,
        ?int $conversationId,
        string $room
    ): void {
        MentionRepository::deleteForMessage($messageId);
        self::processMentions($messageId, $newBody, $authorId, $channelId, $conversationId, $room);
    }

    /**
     * Autocomplete search: find users matching a prefix, filtered by room permissions.
     *
     * @return array<array{id: int, display_name: string, avatar_color: string}>
     */
    public static function searchUsers(
        string $query,
        int $spaceId,
        ?int $channelId,
        ?int $conversationId,
        int $limit = 10
    ): array {
        $db = Database::connection();
        $like = '%' . self::escapeLike($query) . '%';
        $limit = min($limit, 20);

        if ($conversationId !== null) {
            $stmt = $db->prepare(
                'SELECT u.id, u.display_name, u.avatar_color
                 FROM users u
                 JOIN conversation_members cm ON cm.user_id = u.id AND cm.conversation_id = ?
                 WHERE u.display_name LIKE ?
                 ORDER BY u.display_name ASC
                 LIMIT ?'
            );
            $stmt->execute([$conversationId, $like, $limit]);
            return self::castRows($stmt->fetchAll());
        }

        if ($channelId !== null) {
            $channel = ChannelRepository::find($channelId);
            if (!$channel) {
                return [];
            }

            if ($channel['is_private']) {
                $stmt = $db->prepare(
                    'SELECT u.id, u.display_name, u.avatar_color
                     FROM users u
                     JOIN channel_members chm ON chm.user_id = u.id AND chm.channel_id = ?
                     WHERE u.display_name LIKE ?
                     ORDER BY u.display_name ASC
                     LIMIT ?'
                );
                $stmt->execute([$channelId, $like, $limit]);
                return self::castRows($stmt->fetchAll());
            }

            // Public channel → search all space members
            $spaceId = (int) $channel['space_id'];
        }

        // Default: search space members
        $stmt = $db->prepare(
            'SELECT u.id, u.display_name, u.avatar_color
             FROM users u
             JOIN space_members sm ON sm.user_id = u.id AND sm.space_id = ?
             WHERE u.display_name LIKE ?
             ORDER BY u.display_name ASC
             LIMIT ?'
        );
        $stmt->execute([$spaceId, $like, $limit]);
        return self::castRows($stmt->fetchAll());
    }

    // ── Private helpers ───────────────────────

    private static function resolveAgainstSpaceMembers(array $rawNames, int $spaceId): array
    {
        return self::resolveFromQuery(
            $rawNames,
            'SELECT u.id, u.display_name
             FROM users u
             JOIN space_members sm ON sm.user_id = u.id AND sm.space_id = ?',
            [$spaceId]
        );
    }

    private static function resolveAgainstChannelMembers(array $rawNames, int $channelId): array
    {
        return self::resolveFromQuery(
            $rawNames,
            'SELECT u.id, u.display_name
             FROM users u
             JOIN channel_members chm ON chm.user_id = u.id AND chm.channel_id = ?',
            [$channelId]
        );
    }

    private static function resolveAgainstConversationMembers(array $rawNames, int $conversationId): array
    {
        return self::resolveFromQuery(
            $rawNames,
            'SELECT u.id, u.display_name
             FROM users u
             JOIN conversation_members cm ON cm.user_id = u.id AND cm.conversation_id = ?',
            [$conversationId]
        );
    }

    /**
     * Generic resolver: run a base query to get eligible users, then match raw @tokens
     * case-insensitively against display_name.
     */
    private static function resolveFromQuery(array $rawNames, string $baseSql, array $params): array
    {
        $db = Database::connection();
        $stmt = $db->prepare($baseSql);
        $stmt->execute($params);

        $eligible = $stmt->fetchAll();
        $lowerNames = array_map('mb_strtolower', $rawNames);

        $matched = [];
        foreach ($eligible as $user) {
            $lower = mb_strtolower($user['display_name']);
            if (in_array($lower, $lowerNames, true)) {
                $matched[] = (int) $user['id'];
            }
        }

        return array_values(array_unique($matched));
    }

    private static function escapeLike(string $value): string
    {
        return str_replace(['%', '_', '\\'], ['\\%', '\\_', '\\\\'], $value);
    }

    private static function castRows(array $rows): array
    {
        return array_map(fn(array $r) => [
            'id' => (int) $r['id'],
            'display_name' => $r['display_name'],
            'avatar_color' => $r['avatar_color'],
        ], $rows);
    }
}

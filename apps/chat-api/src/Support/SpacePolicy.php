<?php

declare(strict_types=1);

namespace App\Support;

use App\Exceptions\ApiException;
use App\Repositories\SpaceRepository;

/**
 * Central tenant-isolation guard.
 *
 * Every space-scoped operation should pass through one of these methods
 * so that cross-tenant access is blocked in a single, auditable place.
 */
final class SpacePolicy
{
    /**
     * Require the user to be a member of the given space.
     *
     * @throws ApiException 403 if not a member.
     */
    public static function requireMember(int $spaceId, int $userId): void
    {
        if (!SpaceRepository::isMember($spaceId, $userId)) {
            throw ApiException::forbidden(
                'Kein Zugriff auf diesen Workspace',
                'SPACE_MEMBER_REQUIRED'
            );
        }
    }

    /**
     * Require admin or owner role in the space.
     *
     * @throws ApiException 403 if not admin/owner.
     */
    public static function requireAdminOrOwner(int $spaceId, int $userId): void
    {
        if (!SpaceRepository::isAdminOrOwner($spaceId, $userId)) {
            throw ApiException::forbidden(
                'Admin- oder Owner-Rechte erforderlich',
                'SPACE_ADMIN_REQUIRED'
            );
        }
    }

    /**
     * Require moderator, admin, or owner role in the space.
     *
     * @throws ApiException 403 if below moderator.
     */
    public static function requireModeratorOrAbove(int $spaceId, int $userId): void
    {
        if (!SpaceRepository::isModeratorOrAbove($spaceId, $userId)) {
            throw ApiException::forbidden(
                'Moderator-Rechte oder höher erforderlich',
                'SPACE_MODERATOR_REQUIRED'
            );
        }
    }

    /**
     * Resolve space_id from a channel or conversation ID.
     * Used when the caller only has a context ID (e.g. notification creation).
     *
     * @return int  The resolved space_id.
     * @throws \RuntimeException  When neither ID resolves to a space.
     */
    public static function resolveSpaceId(?int $channelId, ?int $conversationId): int
    {
        if ($channelId !== null) {
            $channel = \App\Repositories\ChannelRepository::find($channelId);
            if ($channel && isset($channel['space_id'])) {
                return (int) $channel['space_id'];
            }
        }

        if ($conversationId !== null) {
            $conversation = \App\Repositories\ConversationRepository::find($conversationId);
            if ($conversation && isset($conversation['space_id'])) {
                return (int) $conversation['space_id'];
            }
        }

        throw new \RuntimeException(
            "Cannot resolve space_id from channel=$channelId, conversation=$conversationId"
        );
    }
}

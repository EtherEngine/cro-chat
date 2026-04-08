<?php

declare(strict_types=1);

namespace App\Services;

use App\Exceptions\ApiException;
use App\Repositories\ChannelRepository;
use App\Repositories\EventRepository;
use App\Repositories\MessageRepository;
use App\Repositories\ModerationRepository;
use App\Repositories\SpaceRepository;
use App\Support\Database;

/**
 * Business logic for moderation actions.
 *
 * Enforces role hierarchy and logs every action to moderation_actions.
 * Clear separation: space-level actions use spaceRole, channel-level
 * combine spaceRole + channelRole via RoleService::effectiveChannelLevel().
 */
final class ModerationService
{
    // ── Delete a message (moderator+) ─────────

    public static function deleteMessage(int $messageId, int $actorId, ?string $reason = null): void
    {
        $msg = MessageRepository::findBasic($messageId);
        if (!$msg || $msg['deleted_at'] !== null) {
            throw ApiException::notFound('Nachricht nicht gefunden', 'MESSAGE_NOT_FOUND');
        }

        // Determine space context
        $spaceId = null;
        $channelId = null;

        if ($msg['channel_id']) {
            $channel = ChannelRepository::find((int) $msg['channel_id']);
            $spaceId = $channel ? (int) $channel['space_id'] : null;
            $channelId = (int) $msg['channel_id'];
        } elseif ($msg['conversation_id']) {
            $conv = \App\Repositories\ConversationRepository::find((int) $msg['conversation_id']);
            $spaceId = $conv ? (int) $conv['space_id'] : null;
        }

        if (!$spaceId) {
            throw ApiException::forbidden('Kontext nicht gefunden', 'CONTEXT_NOT_FOUND');
        }

        // Check permission
        $spaceRole = SpaceRepository::memberRole($spaceId, $actorId);
        $channelRole = $channelId ? ChannelRepository::memberRole($channelId, $actorId) : null;

        if (!$spaceRole) {
            throw ApiException::forbidden('Kein Mitglied dieses Space', 'SPACE_MEMBER_REQUIRED');
        }

        // Own messages can always be deleted
        if ((int) $msg['user_id'] !== $actorId) {
            if (!RoleService::canModerateChannel($spaceRole, $channelRole)) {
                throw ApiException::forbidden('Moderator-Berechtigung erforderlich', 'MODERATOR_REQUIRED');
            }
        }

        Database::transaction(function () use ($messageId, $spaceId, $channelId, $actorId, $msg, $reason) {
            MessageRepository::softDelete($messageId);

            ModerationRepository::log(
                $spaceId,
                'message_delete',
                $actorId,
                $channelId,
                (int) $msg['user_id'],
                $messageId,
                $reason,
                ['body_preview' => mb_substr($msg['body'], 0, 100)]
            );

            $room = $msg['channel_id']
                ? 'channel:' . (int) $msg['channel_id']
                : 'conversation:' . (int) $msg['conversation_id'];
            EventRepository::publish('message.deleted', $room, ['id' => $messageId]);
        });
    }

    // ── Mute a user in a channel ──────────────

    public static function muteUser(
        int $channelId,
        int $targetUserId,
        int $actorId,
        int $durationMinutes,
        ?string $reason = null
    ): void {
        $channel = ChannelRepository::find($channelId);
        if (!$channel) {
            throw ApiException::notFound('Channel nicht gefunden', 'CHANNEL_NOT_FOUND');
        }

        $spaceId = (int) $channel['space_id'];
        self::requireModerateChannel($spaceId, $channelId, $actorId);
        self::preventSelfAction($actorId, $targetUserId);
        self::requireTargetIsLower($spaceId, $channelId, $actorId, $targetUserId);

        $mutedUntil = date('Y-m-d H:i:s', time() + ($durationMinutes * 60));

        Database::transaction(function () use ($channelId, $targetUserId, $spaceId, $actorId, $mutedUntil, $durationMinutes, $reason) {
            ChannelRepository::updateMutedUntil($channelId, $targetUserId, $mutedUntil);

            ModerationRepository::log(
                $spaceId,
                'user_mute',
                $actorId,
                $channelId,
                $targetUserId,
                null,
                $reason,
                ['duration_minutes' => $durationMinutes, 'muted_until' => $mutedUntil]
            );

            EventRepository::publish('moderation.mute', "channel:$channelId", [
                'user_id' => $targetUserId,
                'muted_until' => $mutedUntil,
            ]);
        });
    }

    // ── Unmute a user in a channel ────────────

    public static function unmuteUser(int $channelId, int $targetUserId, int $actorId): void
    {
        $channel = ChannelRepository::find($channelId);
        if (!$channel) {
            throw ApiException::notFound('Channel nicht gefunden', 'CHANNEL_NOT_FOUND');
        }

        $spaceId = (int) $channel['space_id'];
        self::requireModerateChannel($spaceId, $channelId, $actorId);

        Database::transaction(function () use ($channelId, $targetUserId, $spaceId, $actorId) {
            ChannelRepository::updateMutedUntil($channelId, $targetUserId, null);

            ModerationRepository::log(
                $spaceId,
                'user_unmute',
                $actorId,
                $channelId,
                $targetUserId
            );
        });
    }

    // ── Kick user from channel ────────────────

    public static function kickFromChannel(int $channelId, int $targetUserId, int $actorId, ?string $reason = null): void
    {
        $channel = ChannelRepository::find($channelId);
        if (!$channel) {
            throw ApiException::notFound('Channel nicht gefunden', 'CHANNEL_NOT_FOUND');
        }

        $spaceId = (int) $channel['space_id'];
        self::requireModerateChannel($spaceId, $channelId, $actorId);
        self::preventSelfAction($actorId, $targetUserId);
        self::requireTargetIsLower($spaceId, $channelId, $actorId, $targetUserId);

        Database::transaction(function () use ($channelId, $targetUserId, $spaceId, $actorId, $reason) {
            ChannelRepository::removeMember($channelId, $targetUserId);

            ModerationRepository::log(
                $spaceId,
                'user_kick',
                $actorId,
                $channelId,
                $targetUserId,
                null,
                $reason
            );

            EventRepository::publish('moderation.kick', "channel:$channelId", [
                'user_id' => $targetUserId,
            ]);
        });
    }

    // ── Change space role (admin+) ────────────

    public static function changeSpaceRole(
        int $spaceId,
        int $targetUserId,
        string $newRole,
        int $actorId,
        ?string $reason = null
    ): void {
        if (!in_array($newRole, RoleService::VALID_SPACE_ROLES, true)) {
            throw ApiException::validation("Ungültige Rolle: $newRole", 'INVALID_ROLE');
        }

        $actorRole = SpaceRepository::memberRole($spaceId, $actorId);
        $targetRole = SpaceRepository::memberRole($spaceId, $targetUserId);

        if (!$actorRole) {
            throw ApiException::forbidden('Kein Mitglied dieses Space', 'SPACE_MEMBER_REQUIRED');
        }
        if (!$targetRole) {
            throw ApiException::notFound('Ziel-User ist kein Mitglied', 'TARGET_NOT_MEMBER');
        }

        if ($targetRole === 'owner') {
            throw ApiException::forbidden('Owner-Rolle kann nicht geändert werden', 'OWNER_PROTECTED');
        }
        if ($newRole === 'owner') {
            throw ApiException::forbidden('Ownership-Transfer nicht erlaubt', 'OWNER_TRANSFER_DENIED');
        }

        if (!RoleService::canManageSpaceRole($actorRole, $targetRole, $newRole)) {
            throw ApiException::forbidden('Keine Berechtigung für diese Rollenänderung', 'ROLE_CHANGE_DENIED');
        }

        Database::transaction(function () use ($spaceId, $targetUserId, $newRole, $actorId, $targetRole, $reason) {
            SpaceRepository::updateMemberRole($spaceId, $targetUserId, $newRole);

            ModerationRepository::log(
                $spaceId,
                'role_change',
                $actorId,
                null,
                $targetUserId,
                null,
                $reason,
                ['old_role' => $targetRole, 'new_role' => $newRole]
            );
        });
    }

    // ── Change channel role (channel admin+) ──

    public static function changeChannelRole(
        int $channelId,
        int $targetUserId,
        string $newRole,
        int $actorId,
        ?string $reason = null
    ): void {
        if (!in_array($newRole, RoleService::VALID_CHANNEL_ROLES, true)) {
            throw ApiException::validation("Ungültige Channel-Rolle: $newRole", 'INVALID_ROLE');
        }

        $channel = ChannelRepository::find($channelId);
        if (!$channel) {
            throw ApiException::notFound('Channel nicht gefunden', 'CHANNEL_NOT_FOUND');
        }

        $spaceId = (int) $channel['space_id'];

        $actorSpaceRole = SpaceRepository::memberRole($spaceId, $actorId);
        $actorChannelRole = ChannelRepository::memberRole($channelId, $actorId);
        $targetChannelRole = ChannelRepository::memberRole($channelId, $targetUserId);

        if (!$actorSpaceRole) {
            throw ApiException::forbidden('Kein Mitglied dieses Space', 'SPACE_MEMBER_REQUIRED');
        }
        if ($targetChannelRole === null) {
            throw ApiException::notFound('Ziel-User ist kein Channel-Mitglied', 'TARGET_NOT_MEMBER');
        }

        if (!RoleService::canManageChannelRole($actorSpaceRole, $actorChannelRole, $targetChannelRole, $newRole)) {
            throw ApiException::forbidden('Keine Berechtigung für diese Rollenänderung', 'ROLE_CHANGE_DENIED');
        }

        Database::transaction(function () use ($channelId, $targetUserId, $newRole, $spaceId, $actorId, $targetChannelRole, $reason) {
            ChannelRepository::updateMemberRole($channelId, $targetUserId, $newRole);

            ModerationRepository::log(
                $spaceId,
                'channel_role_change',
                $actorId,
                $channelId,
                $targetUserId,
                null,
                $reason,
                ['old_role' => $targetChannelRole, 'new_role' => $newRole]
            );
        });
    }

    // ── Audit log retrieval ───────────────────

    public static function spaceLog(int $spaceId, int $actorId, int $limit = 50, ?int $before = null): array
    {
        // Only admin+ can view the moderation log
        $role = SpaceRepository::memberRole($spaceId, $actorId);
        if (!$role || !RoleService::isSpaceAdminOrAbove($role)) {
            throw ApiException::forbidden('Admin-Berechtigung erforderlich', 'ADMIN_REQUIRED');
        }

        return ModerationRepository::forSpace($spaceId, $limit, $before);
    }

    public static function channelLog(int $channelId, int $actorId, int $limit = 50, ?int $before = null): array
    {
        $channel = ChannelRepository::find($channelId);
        if (!$channel) {
            throw ApiException::notFound('Channel nicht gefunden', 'CHANNEL_NOT_FOUND');
        }

        $spaceId = (int) $channel['space_id'];
        $spaceRole = SpaceRepository::memberRole($spaceId, $actorId);
        $channelRole = ChannelRepository::memberRole($channelId, $actorId);

        if (!$spaceRole || !RoleService::canModerateChannel($spaceRole, $channelRole)) {
            throw ApiException::forbidden('Moderator-Berechtigung erforderlich', 'MODERATOR_REQUIRED');
        }

        return ModerationRepository::forChannel($channelId, $limit, $before);
    }

    // ── Guards ─────────────────────────────────

    private static function requireModerateChannel(int $spaceId, int $channelId, int $actorId): void
    {
        $spaceRole = SpaceRepository::memberRole($spaceId, $actorId);
        $channelRole = ChannelRepository::memberRole($channelId, $actorId);

        if (!$spaceRole || !RoleService::canModerateChannel($spaceRole, $channelRole)) {
            throw ApiException::forbidden('Moderator-Berechtigung erforderlich', 'MODERATOR_REQUIRED');
        }
    }

    private static function preventSelfAction(int $actorId, int $targetUserId): void
    {
        if ($actorId === $targetUserId) {
            throw ApiException::validation('Aktion auf sich selbst nicht erlaubt', 'SELF_ACTION_DENIED');
        }
    }

    /** Ensure the actor outranks the target in the channel context. */
    private static function requireTargetIsLower(int $spaceId, int $channelId, int $actorId, int $targetUserId): void
    {
        $actorSpaceRole = SpaceRepository::memberRole($spaceId, $actorId) ?? 'guest';
        $actorChannelRole = ChannelRepository::memberRole($channelId, $actorId);
        $targetSpaceRole = SpaceRepository::memberRole($spaceId, $targetUserId) ?? 'guest';
        $targetChannelRole = ChannelRepository::memberRole($channelId, $targetUserId);

        $actorLevel = RoleService::effectiveChannelLevel($actorSpaceRole, $actorChannelRole);
        $targetLevel = RoleService::effectiveChannelLevel($targetSpaceRole, $targetChannelRole);

        if ($targetLevel >= $actorLevel) {
            throw ApiException::forbidden('Ziel-User hat gleiche oder höhere Berechtigung', 'TARGET_OUTRANKS');
        }
    }
}

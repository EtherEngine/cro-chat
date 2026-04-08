<?php

declare(strict_types=1);

namespace App\Services;

/**
 * Central role hierarchy for space and channel permissions.
 *
 * Space roles: owner > admin > moderator > member > guest
 * Channel roles: admin > moderator > member > guest
 */
final class RoleService
{
    /** Space role hierarchy (higher = more power). */
    private const SPACE_LEVELS = [
        'owner'     => 50,
        'admin'     => 40,
        'moderator' => 30,
        'member'    => 20,
        'guest'     => 10,
    ];

    /** Channel role hierarchy. */
    private const CHANNEL_LEVELS = [
        'admin'     => 40,
        'moderator' => 30,
        'member'    => 20,
        'guest'     => 10,
    ];

    public const VALID_SPACE_ROLES   = ['owner', 'admin', 'moderator', 'member', 'guest'];
    public const VALID_CHANNEL_ROLES = ['admin', 'moderator', 'member', 'guest'];

    // ── Level helpers ─────────────────────────

    public static function spaceLevel(string $role): int
    {
        return self::SPACE_LEVELS[$role] ?? 0;
    }

    public static function channelLevel(string $role): int
    {
        return self::CHANNEL_LEVELS[$role] ?? 0;
    }

    // ── Space permission checks ───────────────

    /** Is the role owner or admin? */
    public static function isSpaceAdminOrAbove(string $role): bool
    {
        return self::spaceLevel($role) >= self::SPACE_LEVELS['admin'];
    }

    /** Is the role moderator or above? */
    public static function isSpaceModeratorOrAbove(string $role): bool
    {
        return self::spaceLevel($role) >= self::SPACE_LEVELS['moderator'];
    }

    /** Can the actor's role manage the target's role at space level? */
    public static function canManageSpaceRole(string $actorRole, string $targetCurrentRole, string $newRole): bool
    {
        $actorLevel = self::spaceLevel($actorRole);

        // Must be admin+ to manage roles
        if ($actorLevel < self::SPACE_LEVELS['admin']) {
            return false;
        }

        // Cannot change someone with equal or higher role
        if (self::spaceLevel($targetCurrentRole) >= $actorLevel) {
            return false;
        }

        // Cannot promote someone to own level or above
        if (self::spaceLevel($newRole) >= $actorLevel) {
            return false;
        }

        return true;
    }

    // ── Channel permission checks ─────────────

    /** Is the channel role admin? */
    public static function isChannelAdmin(string $role): bool
    {
        return $role === 'admin';
    }

    /** Is the role moderator or above in channel? */
    public static function isChannelModeratorOrAbove(string $role): bool
    {
        return self::channelLevel($role) >= self::CHANNEL_LEVELS['moderator'];
    }

    /**
     * Effective permission: combines space role and channel role.
     * A space admin/owner always counts as channel admin.
     * A space moderator counts as at least channel moderator.
     */
    public static function effectiveChannelLevel(string $spaceRole, ?string $channelRole): int
    {
        $spaceMapped = match (true) {
            self::isSpaceAdminOrAbove($spaceRole)     => self::CHANNEL_LEVELS['admin'],
            self::isSpaceModeratorOrAbove($spaceRole)  => self::CHANNEL_LEVELS['moderator'],
            default                                    => 0,
        };

        $channelLvl = $channelRole ? self::channelLevel($channelRole) : 0;

        return max($spaceMapped, $channelLvl);
    }

    /** Can the actor moderate in this channel (delete messages, mute, kick)? */
    public static function canModerateChannel(string $spaceRole, ?string $channelRole): bool
    {
        return self::effectiveChannelLevel($spaceRole, $channelRole) >= self::CHANNEL_LEVELS['moderator'];
    }

    /** Can the actor manage channel settings (edit, delete channel)? */
    public static function canAdminChannel(string $spaceRole, ?string $channelRole): bool
    {
        return self::effectiveChannelLevel($spaceRole, $channelRole) >= self::CHANNEL_LEVELS['admin'];
    }

    /** Can the actor manage the target's channel role? */
    public static function canManageChannelRole(
        string $actorSpaceRole,
        ?string $actorChannelRole,
        ?string $targetChannelRole,
        string $newRole
    ): bool {
        $actorLevel  = self::effectiveChannelLevel($actorSpaceRole, $actorChannelRole);
        $targetLevel = $targetChannelRole ? self::channelLevel($targetChannelRole) : 0;

        // Must be channel admin to manage roles
        if ($actorLevel < self::CHANNEL_LEVELS['admin']) {
            return false;
        }

        // Cannot change someone with equal or higher effective level
        if ($targetLevel >= $actorLevel) {
            return false;
        }

        // Cannot promote above own level
        if (self::channelLevel($newRole) >= $actorLevel) {
            return false;
        }

        return true;
    }

    // ── Write permission (mute / guest check) ─

    /** Can the user write messages? Checks guest role and mute state. */
    public static function canWrite(string $role, ?string $mutedUntil): bool
    {
        if ($role === 'guest') {
            return false;
        }

        if ($mutedUntil !== null && strtotime($mutedUntil) > time()) {
            return false;
        }

        return true;
    }
}

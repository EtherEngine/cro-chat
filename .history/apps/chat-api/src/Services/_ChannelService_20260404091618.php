<?php

namespace App\Services;

use App\Repositories\ChannelRepository;
use App\Repositories\SpaceRepository;
use App\Support\Response;

final class ChannelService
{
    public static function listForSpace(int $spaceId, int $userId): array
    {
        self::requireSpaceMember($spaceId, $userId);
        return ChannelRepository::forSpace($spaceId, $userId);
    }

    public static function show(int $channelId, int $userId): array
    {
        $channel = self::findOrFail($channelId);
        self::requireAccess($channel, $userId);
        return $channel;
    }

    public static function members(int $channelId, int $userId): array
    {
        $channel = self::findOrFail($channelId);
        self::requireAccess($channel, $userId);
        return ChannelRepository::members($channelId);
    }

    public static function create(int $spaceId, string $name, string $description, string $color, bool $isPrivate, int $userId): array
    {
        self::requireSpaceMember($spaceId, $userId);
        return ChannelRepository::create($spaceId, $name, $description, $color, $isPrivate, $userId);
    }

    public static function update(int $channelId, array $data, int $userId): array
    {
        $channel = self::findOrFail($channelId);
        self::requireChannelAdmin($channel, $userId);
        return ChannelRepository::update($channelId, $data);
    }

    public static function delete(int $channelId, int $userId): void
    {
        $channel = self::findOrFail($channelId);
        self::requireChannelAdmin($channel, $userId);
        ChannelRepository::delete($channelId);
    }

    public static function join(int $channelId, int $userId): void
    {
        $channel = self::findOrFail($channelId);
        self::requireSpaceMember((int) $channel['space_id'], $userId);

        if ($channel['is_private']) {
            Response::error('Privater Channel — Einladung erforderlich', 403);
        }

        ChannelRepository::addMember($channelId, $userId);
    }

    public static function addMember(int $channelId, int $targetUserId, int $actingUserId): void
    {
        $channel = self::findOrFail($channelId);
        self::requireChannelAdmin($channel, $actingUserId);
        self::requireSpaceMember((int) $channel['space_id'], $targetUserId);
        ChannelRepository::addMember($channelId, $targetUserId);
    }

    public static function removeMember(int $channelId, int $targetUserId, int $actingUserId): void
    {
        $channel = self::findOrFail($channelId);

        // Can remove yourself, or admin can remove others
        if ($targetUserId !== $actingUserId) {
            self::requireChannelAdmin($channel, $actingUserId);
        }

        ChannelRepository::removeMember($channelId, $targetUserId);
    }

    // ── Guards ────────────────────────────────

    private static function findOrFail(int $channelId): array
    {
        $channel = ChannelRepository::find($channelId);
        if (!$channel) {
            Response::error('Channel nicht gefunden', 404);
        }
        return $channel;
    }

    public static function requireAccess(array $channel, int $userId): void
    {
        // Public channels: any space member can read
        if (!$channel['is_private']) {
            self::requireSpaceMember((int) $channel['space_id'], $userId);
            return;
        }
        // Private channels: only members
        if (!ChannelRepository::isMember((int) $channel['id'], $userId)) {
            Response::error('Kein Zugriff auf diesen Channel', 403);
        }
    }

    private static function requireChannelAdmin(array $channel, int $userId): void
    {
        // Space owner/admin can always manage channels
        if (SpaceRepository::isAdminOrOwner((int) $channel['space_id'], $userId)) {
            return;
        }
        // Channel admin
        $role = ChannelRepository::memberRole((int) $channel['id'], $userId);
        if ($role !== 'admin') {
            Response::error('Keine Berechtigung', 403);
        }
    }

    private static function requireSpaceMember(int $spaceId, int $userId): void
    {
        if (!SpaceRepository::isMember($spaceId, $userId)) {
            Response::error('Kein Mitglied dieses Space', 403);
        }
    }
}

<?php

declare(strict_types=1);

namespace App\Services;

use App\Exceptions\ApiException;
use App\Repositories\SpaceRepository;
use App\Support\Database;
use App\Services\RoleService;
use App\Services\ModerationService;

final class SpaceService
{
    public static function listForUser(int $userId): array
    {
        return SpaceRepository::forUser($userId);
    }

    public static function show(int $spaceId, int $userId): array
    {
        self::requireMember($spaceId, $userId);
        $space = SpaceRepository::find($spaceId);
        if (!$space) {
            throw ApiException::notFound('Space nicht gefunden', 'SPACE_NOT_FOUND');
        }
        return $space;
    }

    public static function create(string $name, string $slug, string $description, int $ownerId): array
    {
        return Database::transaction(function () use ($name, $slug, $description, $ownerId) {
            return SpaceRepository::create($name, $slug, $description, $ownerId);
        });
    }

    public static function members(int $spaceId, int $userId): array
    {
        self::requireMember($spaceId, $userId);
        return SpaceRepository::members($spaceId);
    }

    public static function addMember(int $spaceId, int $userId, int $actingUserId, string $role = 'member'): void
    {
        self::requireAdmin($spaceId, $actingUserId);

        if (!in_array($role, RoleService::VALID_SPACE_ROLES, true) || $role === 'owner') {
            throw ApiException::validation("Ungültige Rolle: $role", 'INVALID_ROLE');
        }

        SpaceRepository::addMember($spaceId, $userId, $role);
    }

    public static function updateMemberRole(int $spaceId, int $targetUserId, string $role, int $actingUserId): void
    {
        // Delegate to ModerationService which enforces hierarchy + audit logging
        ModerationService::changeSpaceRole($spaceId, $targetUserId, $role, $actingUserId);
    }

    public static function removeMember(int $spaceId, int $targetUserId, int $actingUserId): void
    {
        self::requireAdmin($spaceId, $actingUserId);
        self::protectOwner($spaceId, $targetUserId);
        SpaceRepository::removeMember($spaceId, $targetUserId);
    }

    // ── Guards ────────────────────────────────

    public static function requireMember(int $spaceId, int $userId): void
    {
        if (!SpaceRepository::isMember($spaceId, $userId)) {
            throw ApiException::forbidden('Kein Mitglied dieses Space', 'NOT_SPACE_MEMBER');
        }
    }

    private static function requireAdmin(int $spaceId, int $userId): void
    {
        if (!SpaceRepository::isAdminOrOwner($spaceId, $userId)) {
            throw ApiException::forbidden('Nur Admins können diese Aktion ausführen', 'ADMIN_REQUIRED');
        }
    }

    private static function protectOwner(int $spaceId, int $targetUserId): void
    {
        $role = SpaceRepository::memberRole($spaceId, $targetUserId);
        if ($role === 'owner') {
            throw ApiException::forbidden('Der Space-Owner kann nicht geändert werden', 'OWNER_PROTECTED');
        }
    }
}

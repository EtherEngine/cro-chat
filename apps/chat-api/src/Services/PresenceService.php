<?php

namespace App\Services;

use App\Repositories\UserRepository;

final class PresenceService
{
    public static function heartbeat(int $userId): void
    {
        UserRepository::touchPresence($userId);
    }

    public static function expireStale(): void
    {
        UserRepository::expirePresence();
    }

    /**
     * Lightweight status map: returns [ userId => status ] for co-members.
     * Only fetches id + status columns, no full user hydration.
     */
    public static function statusMap(int $userId): array
    {
        return UserRepository::coMemberStatuses($userId);
    }
}

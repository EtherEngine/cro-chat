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
}

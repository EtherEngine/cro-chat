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

    /**
     * Activate Do Not Disturb mode.
     * While active, the user will appear as 'dnd' and incoming calls
     * are rejected by the CallService busy-check.
     */
    public static function setDnd(int $userId): void
    {
        UserRepository::updateStatus($userId, 'dnd');
    }

    /**
     * Deactivate Do Not Disturb mode (restores to 'online').
     */
    public static function clearDnd(int $userId): void
    {
        $stmt = \App\Support\Database::connection()->prepare(
            "UPDATE users SET status = 'online', last_seen_at = NOW()
             WHERE id = ? AND status = 'dnd'"
        );
        $stmt->execute([$userId]);
    }
}

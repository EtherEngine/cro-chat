<?php

declare(strict_types=1);

namespace App\Jobs\Handlers;

use App\Jobs\JobHandler;
use App\Repositories\EventRepository;
use App\Repositories\UserRepository;

/**
 * Cleans up stale presence records and purges old domain events.
 *
 * Idempotent: running multiple times in a row is harmless — expirePresence
 * and purge are naturally idempotent (re-running is a no-op).
 *
 * Payload:
 *   presence_timeout_minutes? (default 5), event_purge_hours? (default 24)
 */
final class PresenceCleanupHandler implements JobHandler
{
    public function handle(array $payload): void
    {
        // 1. Expire stale user presences (users who haven't sent a heartbeat)
        UserRepository::expirePresence();

        // 2. Purge old published domain events
        $purgeHours = (int) ($payload['event_purge_hours'] ?? 24);
        $purged = EventRepository::purgeOlderThan($purgeHours);

        // 3. Purge old completed/failed jobs
        $jobPurgeHours = (int) ($payload['job_purge_hours'] ?? 48);
        \App\Repositories\JobRepository::purgeOlderThan($jobPurgeHours);
    }
}

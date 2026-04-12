<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Repositories\JobRepository;
use App\Services\JobService;
use App\Support\Request;
use App\Support\Response;

final class JobController
{
    /**
     * GET /api/jobs/stats
     * Returns job queue statistics (counts per status).
     */
    public function stats(): void
    {
        Request::requireUserId();
        $queue = $_GET['queue'] ?? null;
        $stats = JobRepository::stats($queue);
        Response::json($stats);
    }

    /**
     * POST /api/jobs/schedule-maintenance
     * Dispatches periodic maintenance jobs (presence cleanup, search optimize).
     * Intended to be called by a cron job or scheduler.
     */
    public function scheduleMaintenance(): void
    {
        Request::requireUserId();

        $dispatched = [];

        // Stale call reaper — runs every minute, idempotency key prevents pile-up
        $job = JobService::dispatch(
            'call.reap_stale',
            [],
            'maintenance',
            1,
            100,
            'call-reap-stale-' . date('Y-m-d-H-i')
        );
        if ($job) {
            $dispatched[] = 'call.reap_stale';
        }

        // Presence cleanup (idempotent — safe to dispatch frequently)
        $job = JobService::dispatch(
            'presence.cleanup',
            ['event_purge_hours' => 24, 'job_purge_hours' => 48],
            'maintenance',
            3,
            200,
            'presence-cleanup-' . date('Y-m-d-H')
        );
        if ($job) {
            $dispatched[] = 'presence.cleanup';
        }

        // Search index optimization (nightly — idempotency key prevents duplicates)
        $job = JobService::dispatch(
            'search.reindex',
            ['action' => 'optimize'],
            'maintenance',
            1,
            300,
            'search-optimize-' . date('Y-m-d')
        );
        if ($job) {
            $dispatched[] = 'search.reindex (optimize)';
        }

        Response::json(['dispatched' => $dispatched]);
    }
}

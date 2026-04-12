<?php

declare(strict_types=1);

namespace App\Jobs\Handlers;

use App\Jobs\JobHandler;
use App\Services\CallService;
use App\Support\Logger;

/**
 * Expires ringing calls that exceeded the timeout (default 45 s).
 * Transitions them to "missed" and notifies the callee.
 *
 * Idempotent: repeated execution is harmless — each call will be
 * transitioned at most once; subsequent runs are no-ops for that call.
 *
 * Payload: (none required)
 */
final class StaleCallReaperHandler implements JobHandler
{
    public function handle(array $payload): void
    {
        $reaped = CallService::reapStaleCalls();

        if ($reaped > 0) {
            Logger::info('call.reaper.reaped', ['count' => $reaped]);
        }
    }
}

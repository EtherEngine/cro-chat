<?php

declare(strict_types=1);

namespace App\Jobs\Handlers;

use App\Jobs\JobHandler;
use App\Repositories\ComplianceRepository;
use App\Services\ComplianceService;

/**
 * Applies retention policies and cleans up expired data.
 *
 * Idempotent: re-running is a no-op for already-cleaned data.
 * Also purges expired export files.
 *
 * Payload: (none required — processes all enabled policies)
 */
final class RetentionCleanupHandler implements JobHandler
{
    public function handle(array $payload): void
    {
        // 1. Apply all enabled retention policies
        $results = ComplianceService::applyRetentionPolicies();

        // 2. Purge expired data exports
        $expired = ComplianceRepository::purgeExpiredExports();

        // Log a summary (via the existing Logger if available)
        if (!empty($results) || $expired > 0) {
            $summary = array_merge($results, $expired > 0 ? [['target' => 'exports', 'affected' => $expired]] : []);
            \App\Support\Logger::info('retention.cleanup', ['results' => $summary]);
        }
    }
}

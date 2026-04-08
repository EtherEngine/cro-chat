<?php

declare(strict_types=1);

namespace App\Jobs\Handlers;

use App\Jobs\JobHandler;
use App\Repositories\ComplianceRepository;
use App\Services\ComplianceService;

/**
 * Processes data export requests and account deletion/anonymization.
 *
 * Idempotent: claims work via SELECT … FOR UPDATE SKIP LOCKED.
 * Only one export or deletion is processed per invocation.
 *
 * Payload:
 *   action: 'export' | 'deletion'
 *   id?:    Specific request ID (optional — otherwise claims next pending)
 */
final class ComplianceActionHandler implements JobHandler
{
    public function handle(array $payload): void
    {
        $action = $payload['action'] ?? 'export';

        match ($action) {
            'export' => $this->processExport($payload),
            'deletion' => $this->processDeletion($payload),
            default => throw new \InvalidArgumentException("Unknown compliance action: $action"),
        };
    }

    private function processExport(array $payload): void
    {
        if (isset($payload['id'])) {
            ComplianceService::generateExport((int) $payload['id']);
            return;
        }

        // Claim next pending export
        $export = ComplianceRepository::claimPendingExport();
        if ($export) {
            ComplianceService::generateExport($export['id']);
        }
    }

    private function processDeletion(array $payload): void
    {
        if (isset($payload['id'])) {
            $request = ComplianceRepository::findDeletionRequest((int) $payload['id']);
            if ($request && $request['status'] === 'processing') {
                $this->executeDeletion($request);
            }
            return;
        }

        // Claim next request whose grace period has expired
        $request = ComplianceRepository::claimExpiredGracePeriod();
        if ($request) {
            $this->executeDeletion($request);
        }
    }

    private function executeDeletion(array $request): void
    {
        $userId = $request['user_id'];
        $spaceId = $request['space_id'];

        $stats = match ($request['action']) {
            'anonymize' => ComplianceService::anonymizeUser($userId, $spaceId),
            'delete' => ComplianceService::hardDeleteUser($userId, $spaceId),
            default => [],
        };

        ComplianceRepository::completeDeletionRequest($request['id']);
        ComplianceRepository::log(
            $spaceId,
            "account.{$request['action']}",
            $request['requested_by'],
            $userId,
            $stats
        );
    }
}

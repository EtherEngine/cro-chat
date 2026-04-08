<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Exceptions\ApiException;
use App\Repositories\ComplianceRepository;
use App\Repositories\SpaceRepository;
use App\Services\ComplianceService;
use App\Services\JobService;
use App\Services\RoleService;
use App\Support\Request;
use App\Support\Response;
use App\Support\Validator;

/**
 * Compliance & Data Management endpoints.
 * All endpoints require Space Admin or Owner role.
 */
final class ComplianceController
{
    // ═══════════════════════════════════════════════════════════════
    // Retention Policies
    // ═══════════════════════════════════════════════════════════════

    /**
     * GET /api/spaces/{spaceId}/compliance/retention
     * List all retention policies for this space.
     */
    public function listPolicies(array $params): void
    {
        $userId = Request::requireUserId();
        $spaceId = (int) $params['spaceId'];
        self::requireAdmin($spaceId, $userId);

        $policies = ComplianceRepository::listPolicies($spaceId);
        $targets = ComplianceRepository::validTargets();

        Response::json([
            'policies' => $policies,
            'available_targets' => $targets,
        ]);
    }

    /**
     * PUT /api/spaces/{spaceId}/compliance/retention
     * Create or update a retention policy.
     */
    public function upsertPolicy(array $params): void
    {
        $userId = Request::requireUserId();
        $spaceId = (int) $params['spaceId'];
        self::requireOwner($spaceId, $userId);

        $body = Request::json();
        Validator::requireFields($body, ['target', 'retention_days']);

        $target = trim($body['target']);
        $days = (int) $body['retention_days'];
        $hardDelete = !empty($body['hard_delete']);
        $enabled = $body['enabled'] ?? true;

        if ($days < 0) {
            throw ApiException::validation('retention_days muss >= 0 sein.');
        }

        $policy = ComplianceRepository::upsertPolicy($spaceId, $target, $days, $hardDelete, (bool) $enabled, $userId);

        ComplianceRepository::log($spaceId, 'policy.update', $userId, null, [
            'target' => $target,
            'retention_days' => $days,
            'hard_delete' => $hardDelete,
            'enabled' => $enabled,
        ]);

        Response::json(['policy' => $policy]);
    }

    /**
     * POST /api/spaces/{spaceId}/compliance/retention/apply
     * Manually trigger retention enforcement (dispatches job).
     */
    public function applyRetention(array $params): void
    {
        $userId = Request::requireUserId();
        $spaceId = (int) $params['spaceId'];
        self::requireOwner($spaceId, $userId);

        JobService::dispatch('retention.cleanup', [], 'maintenance', 1, 50,
            'retention-cleanup-' . date('Ymd-H'));

        ComplianceRepository::log($spaceId, 'retention.apply', $userId, null, ['manual' => true]);

        Response::json(['ok' => true, 'message' => 'Retention-Cleanup wurde eingeplant.']);
    }

    // ═══════════════════════════════════════════════════════════════
    // Data Export
    // ═══════════════════════════════════════════════════════════════

    /**
     * POST /api/spaces/{spaceId}/compliance/export
     * Request a data export for a user.
     */
    public function requestExport(array $params): void
    {
        $userId = Request::requireUserId();
        $spaceId = (int) $params['spaceId'];
        self::requireAdmin($spaceId, $userId);

        $body = Request::json();
        Validator::requireFields($body, ['user_id']);
        $targetUserId = (int) $body['user_id'];

        // Verify target user is a space member
        $role = SpaceRepository::memberRole($spaceId, $targetUserId);
        if (!$role) {
            throw ApiException::notFound('Benutzer ist kein Mitglied dieses Space.');
        }

        $export = ComplianceRepository::createExportRequest($targetUserId, $spaceId, $userId);

        // Dispatch async export job
        JobService::dispatch('compliance.action', [
            'action' => 'export',
            'id' => $export['id'],
        ], 'maintenance', 2, 80);

        ComplianceRepository::log($spaceId, 'export.request', $userId, $targetUserId, [
            'export_id' => $export['id'],
        ]);

        Response::json(['export' => $export], 201);
    }

    /**
     * GET /api/spaces/{spaceId}/compliance/exports
     * List data export requests for this space.
     */
    public function listExports(array $params): void
    {
        $userId = Request::requireUserId();
        $spaceId = (int) $params['spaceId'];
        self::requireAdmin($spaceId, $userId);

        $limit = min((int) ($_GET['limit'] ?? 20), 100);
        $exports = ComplianceRepository::listExports($spaceId, $limit);

        Response::json(['exports' => $exports]);
    }

    /**
     * GET /api/spaces/{spaceId}/compliance/exports/{exportId}/download
     * Download a completed data export.
     */
    public function downloadExport(array $params): void
    {
        $userId = Request::requireUserId();
        $spaceId = (int) $params['spaceId'];
        self::requireAdmin($spaceId, $userId);

        $exportId = (int) $params['exportId'];
        $export = ComplianceRepository::findExport($exportId);

        if (!$export || $export['space_id'] !== $spaceId) {
            throw ApiException::notFound('Export nicht gefunden.');
        }
        if ($export['status'] !== 'ready') {
            throw ApiException::validation('Export ist noch nicht bereit. Status: ' . $export['status']);
        }

        // file_path is stored — read from internal path
        $filePath = $export['file_path'] ?? null;
        if (!$filePath || !file_exists($filePath)) {
            throw ApiException::notFound('Export-Datei nicht mehr verfügbar.');
        }

        // Path traversal protection
        $realPath = realpath($filePath);
        $allowedDir = realpath(dirname(__DIR__, 2) . '/storage/exports');
        if ($realPath === false || $allowedDir === false || !str_starts_with($realPath, $allowedDir)) {
            throw ApiException::forbidden('Ungültiger Dateipfad.');
        }

        ComplianceRepository::log($spaceId, 'export.download', $userId, $export['user_id'], [
            'export_id' => $exportId,
        ]);

        header('Content-Type: application/json');
        header('Content-Disposition: attachment; filename="data-export-' . $export['user_id'] . '.json"');
        header('Content-Length: ' . filesize($realPath));
        header('Cache-Control: no-store');
        readfile($realPath);
        exit;
    }

    // ═══════════════════════════════════════════════════════════════
    // Account Deletion / Anonymization
    // ═══════════════════════════════════════════════════════════════

    /**
     * POST /api/spaces/{spaceId}/compliance/deletion
     * Request account deletion or anonymization (starts grace period).
     */
    public function requestDeletion(array $params): void
    {
        $userId = Request::requireUserId();
        $spaceId = (int) $params['spaceId'];
        self::requireOwner($spaceId, $userId);

        $body = Request::json();
        Validator::requireFields($body, ['user_id', 'action']);

        $targetUserId = (int) $body['user_id'];
        $action = $body['action']; // 'anonymize' or 'delete'
        $reason = isset($body['reason']) ? trim($body['reason']) : null;

        // Cannot delete space owner
        $targetRole = SpaceRepository::memberRole($spaceId, $targetUserId);
        if (!$targetRole) {
            throw ApiException::notFound('Benutzer ist kein Mitglied dieses Space.');
        }
        if ($targetRole === 'owner') {
            throw ApiException::forbidden('Der Space-Owner kann nicht gelöscht werden.');
        }

        $request = ComplianceRepository::createDeletionRequest($targetUserId, $spaceId, $action, $userId, $reason);

        // Dispatch a delayed job to process after grace period
        JobService::later('compliance.action', [
            'action' => 'deletion',
            'id' => $request['id'],
        ], 7 * 86400, 'maintenance', 1, 50);

        ComplianceRepository::log($spaceId, 'account.request_' . $action, $userId, $targetUserId, [
            'request_id' => $request['id'],
            'grace_end_at' => $request['grace_end_at'],
            'reason' => $reason,
        ]);

        Response::json(['request' => $request], 201);
    }

    /**
     * GET /api/spaces/{spaceId}/compliance/deletions
     * List account deletion requests.
     */
    public function listDeletions(array $params): void
    {
        $userId = Request::requireUserId();
        $spaceId = (int) $params['spaceId'];
        self::requireAdmin($spaceId, $userId);

        $limit = min((int) ($_GET['limit'] ?? 20), 100);
        $requests = ComplianceRepository::listDeletionRequests($spaceId, $limit);

        Response::json(['requests' => $requests]);
    }

    /**
     * POST /api/spaces/{spaceId}/compliance/deletions/{requestId}/cancel
     * Cancel a pending deletion request (during grace period).
     */
    public function cancelDeletion(array $params): void
    {
        $userId = Request::requireUserId();
        $spaceId = (int) $params['spaceId'];
        self::requireOwner($spaceId, $userId);

        $requestId = (int) $params['requestId'];
        $request = ComplianceRepository::findDeletionRequest($requestId);

        if (!$request || $request['space_id'] !== $spaceId) {
            throw ApiException::notFound('Löschanfrage nicht gefunden.');
        }

        $cancelled = ComplianceRepository::cancelDeletionRequest($requestId);
        if (!$cancelled) {
            throw ApiException::validation('Kann nicht mehr storniert werden. Status: ' . $request['status']);
        }

        ComplianceRepository::log($spaceId, 'account.cancel_deletion', $userId, $request['user_id'], [
            'request_id' => $requestId,
        ]);

        Response::json(['ok' => true]);
    }

    // ═══════════════════════════════════════════════════════════════
    // Compliance Audit Log
    // ═══════════════════════════════════════════════════════════════

    /**
     * GET /api/spaces/{spaceId}/compliance/log
     * View the compliance audit log.
     */
    public function log(array $params): void
    {
        $userId = Request::requireUserId();
        $spaceId = (int) $params['spaceId'];
        self::requireAdmin($spaceId, $userId);

        $limit = min((int) ($_GET['limit'] ?? 50), 200);
        $action = isset($_GET['action']) ? trim($_GET['action']) : null;

        $entries = ComplianceRepository::listLog($spaceId, $limit, $action);

        Response::json(['entries' => $entries]);
    }

    // ═══════════════════════════════════════════════════════════════
    // Summary / Dashboard
    // ═══════════════════════════════════════════════════════════════

    /**
     * GET /api/spaces/{spaceId}/compliance/summary
     * Overview of compliance status for the admin panel.
     */
    public function summary(array $params): void
    {
        $userId = Request::requireUserId();
        $spaceId = (int) $params['spaceId'];
        self::requireAdmin($spaceId, $userId);

        $policies = ComplianceRepository::listPolicies($spaceId);
        $exports = ComplianceRepository::listExports($spaceId, 5);
        $deletions = ComplianceRepository::listDeletionRequests($spaceId, 5);
        $log = ComplianceRepository::listLog($spaceId, 10);

        Response::json([
            'policies' => $policies,
            'available_targets' => ComplianceRepository::validTargets(),
            'recent_exports' => $exports,
            'recent_deletions' => $deletions,
            'recent_log' => $log,
        ]);
    }

    // ═══════════════════════════════════════════════════════════════
    // Helpers
    // ═══════════════════════════════════════════════════════════════

    private static function requireAdmin(int $spaceId, int $userId): void
    {
        $role = SpaceRepository::memberRole($spaceId, $userId);
        if (!$role || !RoleService::isSpaceAdminOrAbove($role)) {
            throw ApiException::forbidden('Admin-Berechtigung erforderlich.');
        }
    }

    private static function requireOwner(int $spaceId, int $userId): void
    {
        $role = SpaceRepository::memberRole($spaceId, $userId);
        if ($role !== 'owner') {
            throw ApiException::forbidden('Nur der Space-Owner kann diese Aktion ausführen.');
        }
    }
}

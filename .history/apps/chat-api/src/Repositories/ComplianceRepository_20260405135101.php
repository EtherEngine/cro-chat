<?php

declare(strict_types=1);

namespace App\Repositories;

use App\Support\Database;

/**
 * Data access for compliance tables: retention policies, data exports,
 * account deletion requests, and the compliance audit log.
 */
final class ComplianceRepository
{
    // ═══════════════════════════════════════════════════════════════
    // Retention Policies
    // ═══════════════════════════════════════════════════════════════

    private const VALID_TARGETS = ['messages', 'attachments', 'notifications', 'events', 'jobs', 'moderation_log'];

    public static function listPolicies(int $spaceId): array
    {
        $stmt = Database::connection()->prepare(
            'SELECT rp.*, u.display_name AS created_by_name
             FROM retention_policies rp
             JOIN users u ON u.id = rp.created_by
             WHERE rp.space_id = ?
             ORDER BY rp.target'
        );
        $stmt->execute([$spaceId]);
        return array_map(self::hydratePolicy(...), $stmt->fetchAll());
    }

    public static function getPolicy(int $spaceId, string $target): ?array
    {
        $stmt = Database::connection()->prepare(
            'SELECT * FROM retention_policies WHERE space_id = ? AND target = ?'
        );
        $stmt->execute([$spaceId, $target]);
        $row = $stmt->fetch();
        return $row ? self::hydratePolicy($row) : null;
    }

    public static function upsertPolicy(
        int $spaceId,
        string $target,
        int $retentionDays,
        bool $hardDelete,
        bool $enabled,
        int $createdBy
    ): array {
        if (!in_array($target, self::VALID_TARGETS, true)) {
            throw new \InvalidArgumentException("Invalid retention target: $target");
        }

        $db = Database::connection();
        $db->prepare(
            'INSERT INTO retention_policies (space_id, target, retention_days, hard_delete, enabled, created_by)
             VALUES (?, ?, ?, ?, ?, ?)
             ON DUPLICATE KEY UPDATE retention_days = VALUES(retention_days),
               hard_delete = VALUES(hard_delete), enabled = VALUES(enabled)'
        )->execute([$spaceId, $target, $retentionDays, (int) $hardDelete, (int) $enabled, $createdBy]);

        return self::getPolicy($spaceId, $target);
    }

    public static function enabledPolicies(): array
    {
        $stmt = Database::connection()->query(
            'SELECT * FROM retention_policies WHERE enabled = 1 AND retention_days > 0'
        );
        return array_map(self::hydratePolicy(...), $stmt->fetchAll());
    }

    public static function validTargets(): array
    {
        return self::VALID_TARGETS;
    }

    // ═══════════════════════════════════════════════════════════════
    // Data Export Requests
    // ═══════════════════════════════════════════════════════════════

    public static function createExportRequest(int $userId, int $spaceId, int $requestedBy): array
    {
        // Only allow one pending/processing export per user per space
        $stmt = Database::connection()->prepare(
            "SELECT id FROM data_export_requests
             WHERE user_id = ? AND space_id = ? AND status IN ('pending','processing')
             LIMIT 1"
        );
        $stmt->execute([$userId, $spaceId]);
        if ($stmt->fetch()) {
            throw new \RuntimeException('Ein Export für diesen Benutzer läuft bereits.');
        }

        $db = Database::connection();
        $db->prepare(
            'INSERT INTO data_export_requests (user_id, space_id, requested_by)
             VALUES (?, ?, ?)'
        )->execute([$userId, $spaceId, $requestedBy]);

        return self::findExport((int) $db->lastInsertId());
    }

    public static function findExport(int $id): ?array
    {
        $stmt = Database::connection()->prepare(
            'SELECT der.*, u.display_name AS user_name, r.display_name AS requested_by_name
             FROM data_export_requests der
             JOIN users u ON u.id = der.user_id
             JOIN users r ON r.id = der.requested_by
             WHERE der.id = ?'
        );
        $stmt->execute([$id]);
        $row = $stmt->fetch();
        return $row ? self::hydrateExport($row) : null;
    }

    public static function listExports(int $spaceId, int $limit = 20): array
    {
        $stmt = Database::connection()->prepare(
            'SELECT der.*, u.display_name AS user_name, r.display_name AS requested_by_name
             FROM data_export_requests der
             JOIN users u ON u.id = der.user_id
             JOIN users r ON r.id = der.requested_by
             WHERE der.space_id = ?
             ORDER BY der.created_at DESC
             LIMIT ?'
        );
        $stmt->execute([$spaceId, $limit]);
        return array_map(self::hydrateExport(...), $stmt->fetchAll());
    }

    public static function claimPendingExport(): ?array
    {
        $db = Database::connection();
        $db->beginTransaction();
        try {
            $stmt = $db->prepare(
                "SELECT id FROM data_export_requests
                 WHERE status = 'pending'
                 ORDER BY created_at ASC LIMIT 1
                 FOR UPDATE SKIP LOCKED"
            );
            $stmt->execute();
            $row = $stmt->fetch();
            if (!$row) {
                $db->rollBack();
                return null;
            }
            $db->prepare(
                "UPDATE data_export_requests SET status = 'processing' WHERE id = ?"
            )->execute([$row['id']]);
            $db->commit();
            return self::findExport((int) $row['id']);
        } catch (\Throwable $e) {
            $db->rollBack();
            throw $e;
        }
    }

    public static function completeExport(int $id, string $filePath, int $fileSize): void
    {
        Database::connection()->prepare(
            "UPDATE data_export_requests
             SET status = 'ready', file_path = ?, file_size = ?,
                 completed_at = NOW(), expires_at = DATE_ADD(NOW(), INTERVAL 72 HOUR)
             WHERE id = ?"
        )->execute([$filePath, $fileSize, $id]);
    }

    public static function failExport(int $id, string $error): void
    {
        Database::connection()->prepare(
            "UPDATE data_export_requests SET status = 'failed', error = ?, completed_at = NOW() WHERE id = ?"
        )->execute([$error, $id]);
    }

    public static function purgeExpiredExports(): int
    {
        $stmt = Database::connection()->prepare(
            "SELECT file_path FROM data_export_requests
             WHERE status = 'ready' AND expires_at < NOW()"
        );
        $stmt->execute();
        $paths = $stmt->fetchAll(\PDO::FETCH_COLUMN);

        // Delete files
        foreach ($paths as $path) {
            if ($path && file_exists($path)) {
                @unlink($path);
            }
        }

        $del = Database::connection()->prepare(
            "UPDATE data_export_requests SET status = 'expired', file_path = NULL
             WHERE status = 'ready' AND expires_at < NOW()"
        );
        $del->execute();
        return $del->rowCount();
    }

    // ═══════════════════════════════════════════════════════════════
    // Account Deletion / Anonymization Requests
    // ═══════════════════════════════════════════════════════════════

    private const GRACE_PERIOD_DAYS = 7;

    public static function createDeletionRequest(
        int $userId,
        int $spaceId,
        string $action,
        int $requestedBy,
        ?string $reason = null
    ): array {
        if (!in_array($action, ['anonymize', 'delete'], true)) {
            throw new \InvalidArgumentException("Invalid action: $action");
        }

        // Only one active request per user
        $stmt = Database::connection()->prepare(
            "SELECT id FROM account_deletion_requests
             WHERE user_id = ? AND space_id = ? AND status IN ('pending','grace_period','processing')
             LIMIT 1"
        );
        $stmt->execute([$userId, $spaceId]);
        if ($stmt->fetch()) {
            throw new \RuntimeException('Eine Löschanfrage für diesen Benutzer existiert bereits.');
        }

        $graceEnd = date('Y-m-d H:i:s', time() + self::GRACE_PERIOD_DAYS * 86400);
        $db = Database::connection();
        $db->prepare(
            "INSERT INTO account_deletion_requests (user_id, space_id, action, status, reason, requested_by, grace_end_at)
             VALUES (?, ?, ?, 'grace_period', ?, ?, ?)"
        )->execute([$userId, $spaceId, $action, $reason, $requestedBy, $graceEnd]);

        return self::findDeletionRequest((int) $db->lastInsertId());
    }

    public static function findDeletionRequest(int $id): ?array
    {
        $stmt = Database::connection()->prepare(
            'SELECT adr.*, u.display_name AS user_name, r.display_name AS requested_by_name
             FROM account_deletion_requests adr
             JOIN users u ON u.id = adr.user_id
             JOIN users r ON r.id = adr.requested_by
             WHERE adr.id = ?'
        );
        $stmt->execute([$id]);
        $row = $stmt->fetch();
        return $row ? self::hydrateDeletion($row) : null;
    }

    public static function listDeletionRequests(int $spaceId, int $limit = 20): array
    {
        $stmt = Database::connection()->prepare(
            'SELECT adr.*, u.display_name AS user_name, r.display_name AS requested_by_name
             FROM account_deletion_requests adr
             JOIN users u ON u.id = adr.user_id
             JOIN users r ON r.id = adr.requested_by
             WHERE adr.space_id = ?
             ORDER BY adr.created_at DESC LIMIT ?'
        );
        $stmt->execute([$spaceId, $limit]);
        return array_map(self::hydrateDeletion(...), $stmt->fetchAll());
    }

    public static function cancelDeletionRequest(int $id): bool
    {
        $stmt = Database::connection()->prepare(
            "UPDATE account_deletion_requests SET status = 'cancelled'
             WHERE id = ? AND status IN ('pending','grace_period')"
        );
        $stmt->execute([$id]);
        return $stmt->rowCount() > 0;
    }

    /** Return requests whose grace period has expired and are ready to process. */
    public static function claimExpiredGracePeriod(): ?array
    {
        $db = Database::connection();
        $db->beginTransaction();
        try {
            $stmt = $db->prepare(
                "SELECT id FROM account_deletion_requests
                 WHERE status = 'grace_period' AND grace_end_at <= NOW()
                 ORDER BY grace_end_at ASC LIMIT 1
                 FOR UPDATE SKIP LOCKED"
            );
            $stmt->execute();
            $row = $stmt->fetch();
            if (!$row) {
                $db->rollBack();
                return null;
            }
            $db->prepare(
                "UPDATE account_deletion_requests SET status = 'processing' WHERE id = ?"
            )->execute([$row['id']]);
            $db->commit();
            return self::findDeletionRequest((int) $row['id']);
        } catch (\Throwable $e) {
            $db->rollBack();
            throw $e;
        }
    }

    public static function completeDeletionRequest(int $id): void
    {
        Database::connection()->prepare(
            "UPDATE account_deletion_requests SET status = 'completed', completed_at = NOW() WHERE id = ?"
        )->execute([$id]);
    }

    // ═══════════════════════════════════════════════════════════════
    // Compliance Audit Log
    // ═══════════════════════════════════════════════════════════════

    public static function log(int $spaceId, string $action, int $actorId, ?int $targetUserId = null, ?array $details = null): void
    {
        Database::connection()->prepare(
            'INSERT INTO compliance_log (space_id, action, actor_id, target_user_id, details)
             VALUES (?, ?, ?, ?, ?)'
        )->execute([
            $spaceId,
            $action,
            $actorId,
            $targetUserId,
            $details ? json_encode($details, JSON_UNESCAPED_UNICODE) : null,
        ]);
    }

    public static function listLog(int $spaceId, int $limit = 50, ?string $action = null): array
    {
        $sql = 'SELECT cl.*, a.display_name AS actor_name, t.display_name AS target_name
                FROM compliance_log cl
                JOIN users a ON a.id = cl.actor_id
                LEFT JOIN users t ON t.id = cl.target_user_id
                WHERE cl.space_id = ?';
        $params = [$spaceId];

        if ($action !== null) {
            $sql .= ' AND cl.action = ?';
            $params[] = $action;
        }
        $sql .= " ORDER BY cl.created_at DESC LIMIT ?";
        $params[] = $limit;

        $stmt = Database::connection()->prepare($sql);
        $stmt->execute($params);
        return array_map(self::hydrateLog(...), $stmt->fetchAll());
    }

    // ═══════════════════════════════════════════════════════════════
    // Hydration
    // ═══════════════════════════════════════════════════════════════

    private static function hydratePolicy(array $row): array
    {
        return [
            'id' => (int) $row['id'],
            'space_id' => (int) $row['space_id'],
            'target' => $row['target'],
            'retention_days' => (int) $row['retention_days'],
            'hard_delete' => (bool) $row['hard_delete'],
            'enabled' => (bool) $row['enabled'],
            'created_by' => (int) $row['created_by'],
            'created_by_name' => $row['created_by_name'] ?? null,
            'updated_at' => $row['updated_at'],
            'created_at' => $row['created_at'],
        ];
    }

    private static function hydrateExport(array $row): array
    {
        return [
            'id' => (int) $row['id'],
            'user_id' => (int) $row['user_id'],
            'user_name' => $row['user_name'] ?? null,
            'space_id' => (int) $row['space_id'],
            'status' => $row['status'],
            'file_size' => $row['file_size'] ? (int) $row['file_size'] : null,
            'requested_by' => (int) $row['requested_by'],
            'requested_by_name' => $row['requested_by_name'] ?? null,
            'completed_at' => $row['completed_at'],
            'expires_at' => $row['expires_at'],
            'error' => $row['error'],
            'created_at' => $row['created_at'],
        ];
    }

    private static function hydrateDeletion(array $row): array
    {
        return [
            'id' => (int) $row['id'],
            'user_id' => (int) $row['user_id'],
            'user_name' => $row['user_name'] ?? null,
            'space_id' => (int) $row['space_id'],
            'action' => $row['action'],
            'status' => $row['status'],
            'reason' => $row['reason'],
            'requested_by' => (int) $row['requested_by'],
            'requested_by_name' => $row['requested_by_name'] ?? null,
            'grace_end_at' => $row['grace_end_at'],
            'completed_at' => $row['completed_at'],
            'created_at' => $row['created_at'],
        ];
    }

    private static function hydrateLog(array $row): array
    {
        return [
            'id' => (int) $row['id'],
            'space_id' => (int) $row['space_id'],
            'action' => $row['action'],
            'actor_id' => (int) $row['actor_id'],
            'actor_name' => $row['actor_name'] ?? null,
            'target_user_id' => $row['target_user_id'] ? (int) $row['target_user_id'] : null,
            'target_name' => $row['target_name'] ?? null,
            'details' => $row['details'] ? json_decode($row['details'], true) : null,
            'created_at' => $row['created_at'],
        ];
    }
}

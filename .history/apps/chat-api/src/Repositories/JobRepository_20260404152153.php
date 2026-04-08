<?php

declare(strict_types=1);

namespace App\Repositories;

use App\Support\Database;

/**
 * CRUD + locking for the jobs table.
 *
 * Locking concept: SELECT … FOR UPDATE SKIP LOCKED (MariaDB 10.6+)
 * ensures multiple workers never claim the same job. A lock timeout
 * (locked_at older than 10 min) releases stale locks.
 */
final class JobRepository
{
    private const LOCK_TIMEOUT_MINUTES = 10;

    /**
     * Insert a new job. Returns the job row.
     * If idempotency_key is provided and already exists, returns null (skip).
     */
    public static function dispatch(
        string $type,
        array $payload,
        string $queue = 'default',
        int $maxAttempts = 3,
        int $priority = 100,
        ?string $idempotencyKey = null,
        ?string $availableAt = null
    ): ?array {
        $db = Database::connection();

        // Idempotency check — INSERT IGNORE with unique key
        if ($idempotencyKey !== null) {
            $stmt = $db->prepare(
                'SELECT id FROM jobs WHERE idempotency_key = ?'
            );
            $stmt->execute([$idempotencyKey]);
            if ($stmt->fetch()) {
                return null; // Already dispatched
            }
        }

        $stmt = $db->prepare(
            'INSERT INTO jobs (queue, type, payload, max_attempts, priority, idempotency_key, available_at)
             VALUES (?, ?, ?, ?, ?, ?, ?)'
        );
        $stmt->execute([
            $queue,
            $type,
            json_encode($payload, JSON_UNESCAPED_UNICODE),
            $maxAttempts,
            $priority,
            $idempotencyKey,
            $availableAt ?? date('Y-m-d H:i:s'),
        ]);

        return self::find((int) $db->lastInsertId());
    }

    public static function find(int $id): ?array
    {
        $stmt = Database::connection()->prepare('SELECT * FROM jobs WHERE id = ?');
        $stmt->execute([$id]);
        $row = $stmt->fetch();
        return $row ? self::hydrate($row) : null;
    }

    /**
     * Claim the next available job using SELECT … FOR UPDATE SKIP LOCKED.
     * Returns the claimed job row or null if none available.
     */
    public static function claim(string $workerId, string $queue = 'default'): ?array
    {
        $db = Database::connection();

        // Release stale locks first (processing jobs locked > timeout)
        self::releaseStale();

        $db->beginTransaction();
        try {
            $stmt = $db->prepare(
                'SELECT id FROM jobs
                 WHERE queue = ?
                   AND status = \'pending\'
                   AND available_at <= NOW()
                 ORDER BY priority ASC, id ASC
                 LIMIT 1
                 FOR UPDATE SKIP LOCKED'
            );
            $stmt->execute([$queue]);
            $row = $stmt->fetch();

            if (!$row) {
                $db->rollBack();
                return null;
            }

            $jobId = (int) $row['id'];

            $db->prepare(
                'UPDATE jobs SET status = \'processing\', locked_by = ?, locked_at = NOW(),
                 started_at = COALESCE(started_at, NOW()), attempts = attempts + 1
                 WHERE id = ?'
            )->execute([$workerId, $jobId]);

            $db->commit();

            return self::find($jobId);
        } catch (\Throwable $e) {
            $db->rollBack();
            throw $e;
        }
    }

    /**
     * Mark a job as done.
     */
    public static function complete(int $id): void
    {
        Database::connection()->prepare(
            'UPDATE jobs SET status = \'done\', completed_at = NOW(), locked_by = NULL, locked_at = NULL, last_error = NULL
             WHERE id = ?'
        )->execute([$id]);
    }

    /**
     * Mark a job as failed. If attempts < max_attempts, re-queue as pending
     * with exponential backoff.
     */
    public static function fail(int $id, string $error): void
    {
        $job = self::find($id);
        if (!$job) {
            return;
        }

        if ($job['attempts'] < $job['max_attempts']) {
            // Exponential backoff: 30s, 120s, 480s, …
            $delaySec = (int) (30 * pow(4, $job['attempts'] - 1));
            $availableAt = date('Y-m-d H:i:s', time() + $delaySec);

            Database::connection()->prepare(
                'UPDATE jobs SET status = \'pending\', last_error = ?, locked_by = NULL, locked_at = NULL,
                 available_at = ?
                 WHERE id = ?'
            )->execute([$error, $availableAt, $id]);
        } else {
            Database::connection()->prepare(
                'UPDATE jobs SET status = \'failed\', last_error = ?, locked_by = NULL, locked_at = NULL,
                 completed_at = NOW()
                 WHERE id = ?'
            )->execute([$error, $id]);
        }
    }

    /**
     * Release jobs stuck in processing state for longer than the lock timeout.
     */
    public static function releaseStale(): int
    {
        $stmt = Database::connection()->prepare(
            'UPDATE jobs SET status = \'pending\', locked_by = NULL, locked_at = NULL
             WHERE status = \'processing\'
               AND locked_at < DATE_SUB(NOW(), INTERVAL ? MINUTE)'
        );
        $stmt->execute([self::LOCK_TIMEOUT_MINUTES]);
        return $stmt->rowCount();
    }

    /**
     * Get counts of jobs by status (for monitoring).
     */
    public static function stats(?string $queue = null): array
    {
        $sql = 'SELECT status, COUNT(*) AS count FROM jobs';
        $params = [];
        if ($queue !== null) {
            $sql .= ' WHERE queue = ?';
            $params[] = $queue;
        }
        $sql .= ' GROUP BY status';

        $stmt = Database::connection()->prepare($sql);
        $stmt->execute($params);
        $rows = $stmt->fetchAll();

        $result = ['pending' => 0, 'processing' => 0, 'done' => 0, 'failed' => 0];
        foreach ($rows as $row) {
            $result[$row['status']] = (int) $row['count'];
        }
        return $result;
    }

    /**
     * Purge completed/failed jobs older than given hours.
     */
    public static function purgeOlderThan(int $hours = 48): int
    {
        $stmt = Database::connection()->prepare(
            'DELETE FROM jobs WHERE status IN (\'done\', \'failed\')
             AND completed_at < DATE_SUB(NOW(), INTERVAL ? HOUR)'
        );
        $stmt->execute([$hours]);
        return $stmt->rowCount();
    }

    private static function hydrate(array $row): array
    {
        return [
            'id'              => (int) $row['id'],
            'queue'           => $row['queue'],
            'type'            => $row['type'],
            'payload'         => json_decode($row['payload'], true),
            'status'          => $row['status'],
            'attempts'        => (int) $row['attempts'],
            'max_attempts'    => (int) $row['max_attempts'],
            'priority'        => (int) $row['priority'],
            'last_error'      => $row['last_error'],
            'idempotency_key' => $row['idempotency_key'],
            'locked_by'       => $row['locked_by'],
            'locked_at'       => $row['locked_at'],
            'available_at'    => $row['available_at'],
            'created_at'      => $row['created_at'],
            'started_at'      => $row['started_at'],
            'completed_at'    => $row['completed_at'],
        ];
    }
}

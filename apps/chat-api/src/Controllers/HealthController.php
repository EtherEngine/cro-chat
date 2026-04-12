<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Support\Database;
use App\Support\Response;

final class HealthController
{
    /** Liveness probe — process is running. */
    public function live(array $params): void
    {
        Response::json(['status' => 'ok']);
    }

    /** Readiness probe — DB is reachable. */
    public function ready(array $params): void
    {
        try {
            $pdo = Database::connection();
            $pdo->query('SELECT 1');
            Response::json([
                'status' => 'ok',
                'db' => 'connected',
                'query_count' => Database::getQueryCount(),
            ]);
        } catch (\Throwable $e) {
            http_response_code(503);
            echo json_encode([
                'status' => 'unavailable',
                'db' => 'unreachable',
                'error' => $e->getMessage(),
            ], JSON_UNESCAPED_UNICODE);
            exit;
        }
    }

    /**
     * GET /api/health/calls
     * Call-subsystem health: active calls, stale ringing, recent errors.
     */
    public function calls(array $params): void
    {
        $db = Database::connection();

        // Active calls (initiated / ringing / accepted)
        $stmt = $db->query("
            SELECT status, COUNT(*) AS cnt
            FROM calls
            WHERE status IN ('initiated','ringing','accepted')
            GROUP BY status
        ");
        $active = [];
        foreach ($stmt->fetchAll() as $row) {
            $active[$row['status']] = (int) $row['cnt'];
        }

        // Stale ringing: ringing for > 60 s (should normally timeout at 45 s)
        $stmt = $db->query("
            SELECT COUNT(*) AS cnt FROM calls
            WHERE status = 'ringing'
              AND started_at < DATE_SUB(NOW(), INTERVAL 60 SECOND)
        ");
        $staleRinging = (int) $stmt->fetch()['cnt'];

        // Failed calls in last hour
        $stmt = $db->query("
            SELECT COUNT(*) AS cnt FROM calls
            WHERE status = 'failed'
              AND ended_at >= DATE_SUB(NOW(), INTERVAL 1 HOUR)
        ");
        $recentFailures = (int) $stmt->fetch()['cnt'];

        // Signaling errors in last hour (from security_log)
        $stmt = $db->query("
            SELECT COUNT(*) AS cnt FROM security_log
            WHERE event_type LIKE 'call.%'
              AND severity IN ('warning','critical')
              AND created_at >= DATE_SUB(NOW(), INTERVAL 1 HOUR)
        ");
        $signalingErrors = (int) $stmt->fetch()['cnt'];

        $healthy = ($staleRinging === 0 && $recentFailures < 10 && $signalingErrors < 50);

        Response::json([
            'status' => $healthy ? 'ok' : 'degraded',
            'active_calls' => $active,
            'stale_ringing' => $staleRinging,
            'recent_failures' => $recentFailures,
            'signaling_errors' => $signalingErrors,
        ]);
    }
}

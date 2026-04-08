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
}

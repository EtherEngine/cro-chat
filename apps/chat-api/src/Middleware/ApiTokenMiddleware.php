<?php

declare(strict_types=1);

namespace App\Middleware;

use App\Exceptions\ApiException;
use App\Services\IntegrationService;

/**
 * Bearer-token authentication for the versioned API (v1).
 *
 * Reads Authorization: Bearer cro_<token> and resolves to a user_id
 * or service_account_id. Sets $_SESSION['user_id'] for downstream code
 * and stores the token in $_REQUEST['_api_token'] for scope checks.
 */
final class ApiTokenMiddleware
{
    public function handle(): void
    {
        // Also accept session auth (browser requests)
        if (isset($_SESSION['user_id']) && $_SESSION['user_id']) {
            return;
        }

        $header = $_SERVER['HTTP_AUTHORIZATION'] ?? '';
        if (!str_starts_with($header, 'Bearer ')) {
            throw ApiException::unauthorized('API-Token oder Session erforderlich');
        }

        $rawToken = substr($header, 7);
        $token = IntegrationService::resolveToken($rawToken);

        if (!$token) {
            throw ApiException::unauthorized('Ungültiger oder abgelaufener API-Token');
        }

        // Set session user for downstream compatibility
        if ($token['user_id']) {
            $_SESSION['user_id'] = $token['user_id'];
        } elseif ($token['service_account_id']) {
            // Service accounts act with a synthetic user context
            $_SESSION['user_id'] = $token['service_account_id'];
            $_SESSION['is_service_account'] = true;
        }

        // Store token for scope checks in controllers
        $_REQUEST['_api_token'] = $token;
    }

    /**
     * Check if the current request has a given scope (for use in controllers).
     */
    public static function requireScope(string $scope): void
    {
        $token = $_REQUEST['_api_token'] ?? null;
        if ($token && !IntegrationService::hasScope($token, $scope)) {
            throw ApiException::forbidden("Fehlender Scope: {$scope}");
        }
    }
}

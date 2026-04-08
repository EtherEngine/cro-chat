<?php

declare(strict_types=1);

namespace App\Middleware;

use App\Exceptions\ApiException;

/**
 * CSRF protection via Synchronizer Token pattern.
 *
 * On every authenticated session, a CSRF token is generated and stored in $_SESSION.
 * The token is sent to the client via the X-CSRF-Token response header.
 * State-changing requests (POST/PUT/DELETE) must send it back via X-CSRF-Token request header.
 *
 * Safe methods (GET, HEAD, OPTIONS) are exempt.
 */
final class CsrfMiddleware
{
    public function handle(): void
    {
        // Ensure a CSRF token exists in the session
        if (empty($_SESSION['csrf_token'])) {
            $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
        }

        // Safe methods don't need CSRF validation
        $method = $_SERVER['REQUEST_METHOD'] ?? 'GET';
        if (in_array($method, ['GET', 'HEAD', 'OPTIONS'], true)) {
            return;
        }

        // Validate token on state-changing requests
        $headerToken = $_SERVER['HTTP_X_CSRF_TOKEN'] ?? '';
        if (!hash_equals($_SESSION['csrf_token'], $headerToken)) {
            throw ApiException::forbidden('Ungültiges CSRF-Token', 'CSRF_TOKEN_INVALID');
        }
    }
}

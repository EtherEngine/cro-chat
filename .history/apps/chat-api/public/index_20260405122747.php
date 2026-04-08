<?php

declare(strict_types=1);

require_once __DIR__ . '/../vendor/autoload.php';
require_once __DIR__ . '/../src/bootstrap.php';

use App\Exceptions\ErrorHandler;
use App\Support\Database;
use App\Support\Logger;
use App\Support\Metrics;
use App\Support\Router;

// ── Request lifecycle start ─────────────────────────────────
$requestStart = hrtime(true);
$requestId = bin2hex(random_bytes(8));

// ── Global error handling ───────────────────────────────────
ErrorHandler::register();

// ── CORS ────────────────────────────────────────────────────
$corsOrigin = $GLOBALS['app_config']['cors_origin'] ?? 'http://localhost:5173';
header('Content-Type: application/json; charset=utf-8');
header('Access-Control-Allow-Origin: ' . $corsOrigin);
header('Access-Control-Allow-Credentials: true');
header('Access-Control-Allow-Methods: GET, POST, PUT, DELETE, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type, X-CSRF-Token');
header('Access-Control-Expose-Headers: X-CSRF-Token');

// ── Security headers ────────────────────────────────────────
header('X-Content-Type-Options: nosniff');
header('X-Frame-Options: DENY');
header('Cache-Control: no-store');
header('Referrer-Policy: strict-origin-when-cross-origin');

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(204);
    exit;
}

// ── CSRF token header (expose to client on every response) ──
if (!empty($_SESSION['csrf_token'])) {
    header('X-CSRF-Token: ' . $_SESSION['csrf_token']);
}

// ── Routing ─────────────────────────────────────────────────
$router = new Router();
require __DIR__ . '/../routes/api.php';

$method = $_SERVER['REQUEST_METHOD'] ?? 'GET';
$path = parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH) ?: '/';
$basePath = '/chat-api/public';

if (str_starts_with($path, $basePath)) {
    $path = substr($path, strlen($basePath)) ?: '/';
}

// ── Set request context for structured logging ──────────────
Logger::setRequestContext([
    'request_id' => $requestId,
    'user_id' => $_SESSION['user_id'] ?? null,
    'route' => "$method $path",
]);
header('X-Request-Id: ' . $requestId);

Database::resetCounters();
$router->dispatch($method, $path);

// ── Request complete — log and flush metrics ────────────────
$durationMs = (hrtime(true) - $requestStart) / 1_000_000;
Logger::request($method, $path, http_response_code(), $durationMs, [
    'query_count' => Database::getQueryCount(),
    'query_time_ms' => Database::getQueryTimeMs(),
]);
Metrics::timing('http.request', $durationMs);
Metrics::flush();

// ── Query profiling headers (debug mode only) ───────────────
if ($GLOBALS['app_config']['debug'] ?? false) {
    header('X-Query-Count: ' . Database::getQueryCount());
    header('X-Query-Time-Ms: ' . Database::getQueryTimeMs());
}

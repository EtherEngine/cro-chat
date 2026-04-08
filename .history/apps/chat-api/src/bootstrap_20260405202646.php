<?php

declare(strict_types=1);

// ── 0. Suppress error leaks ─────────────────────────────────
ini_set('display_errors', '0');
ini_set('display_startup_errors', '0');
error_reporting(E_ALL);

use App\Support\Database;
use App\Support\Env;
use App\Support\Cache;
use App\Support\ObjectStorage;

// ── 1. Load .env ────────────────────────────────────────────
Env::load(__DIR__ . '/../.env');

// ── 2. App config ───────────────────────────────────────────
$GLOBALS['app_config'] = require __DIR__ . '/Config/app.php';

// ── 3. Session ──────────────────────────────────────────────
$isSecure = ($GLOBALS['app_config']['env'] ?? 'local') === 'production';
session_set_cookie_params([
    'httponly' => true,
    'secure' => $isSecure,
    'samesite' => 'Lax',
    'lifetime' => $GLOBALS['app_config']['session_lifetime'],
    'path' => '/',
]);
session_name('cro_session');
session_start();

date_default_timezone_set('Europe/Berlin');

// ── 4. Database ─────────────────────────────────────────────
$dbConfig = require __DIR__ . '/Config/database.php';
Database::init($dbConfig);
<?php

declare(strict_types=1);

use App\Support\Database;
use App\Support\Env;

// ── 1. Load .env ────────────────────────────────────────────
Env::load(__DIR__ . '/../.env');

// ── 2. App config ───────────────────────────────────────────
$GLOBALS['app_config'] = require __DIR__ . '/Config/app.php';

// ── 3. Session ──────────────────────────────────────────────
session_set_cookie_params([
    'httponly'  => true,
    'secure'   => false,
    'samesite' => 'Lax',
    'lifetime' => $GLOBALS['app_config']['session_lifetime'],
]);
session_start();

date_default_timezone_set('Europe/Berlin');

// ── 4. Database ─────────────────────────────────────────────
$dbConfig = require __DIR__ . '/Config/database.php';
Database::init($dbConfig);
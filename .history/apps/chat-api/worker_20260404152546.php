<?php

/**
 * Job Worker CLI
 *
 * Usage:
 *   php worker.php                       # Process default queue
 *   php worker.php --queue=notifications  # Process specific queue
 *   php worker.php --once                 # Process one job then exit
 *   php worker.php --poll=2000            # Custom poll interval (ms)
 *
 * Run multiple workers for concurrency:
 *   start /B php worker.php --queue=default
 *   start /B php worker.php --queue=notifications
 *   start /B php worker.php --queue=maintenance
 */

declare(strict_types=1);

require_once __DIR__ . '/vendor/autoload.php';

use App\Jobs\Worker;
use App\Support\Database;
use App\Support\Env;

// ── Bootstrap (minimal, no session/HTTP) ──
Env::load(__DIR__ . '/.env');
$dbConfig = require __DIR__ . '/src/Config/database.php';
Database::init($dbConfig);
date_default_timezone_set('Europe/Berlin');

// ── Parse CLI args ──
$queue = 'default';
$once = false;
$pollMs = 1000;

foreach ($argv as $arg) {
    if (str_starts_with($arg, '--queue=')) {
        $queue = substr($arg, 8);
    }
    if ($arg === '--once') {
        $once = true;
    }
    if (str_starts_with($arg, '--poll=')) {
        $pollMs = max(100, (int) substr($arg, 7));
    }
}

// ── Run ──
$worker = new Worker($queue, $pollMs);
$worker->registerSignals();
$worker->run($once);

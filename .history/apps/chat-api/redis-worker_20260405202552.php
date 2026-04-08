<?php

/**
 * Redis Queue Worker CLI
 *
 * Usage:
 *   php redis-worker.php                       # Process default queue (blocking pop)
 *   php redis-worker.php --queue=notifications  # Process specific queue
 *   php redis-worker.php --once                 # Process one job then exit
 *   php redis-worker.php --timeout=5            # Blocking pop timeout (seconds)
 *
 * Run multiple workers for horizontal scaling:
 *   start /B php redis-worker.php --queue=default
 *   start /B php redis-worker.php --queue=notifications
 *   start /B php redis-worker.php --queue=maintenance
 *
 * Falls back to DB-based worker.php if Redis is unavailable.
 */

declare(strict_types=1);

require_once __DIR__ . '/vendor/autoload.php';

use App\Jobs\Worker;
use App\Support\Database;
use App\Support\Env;
use App\Support\Logger;
use App\Support\Metrics;
use App\Support\RedisQueue;

// ── Bootstrap ──
Env::load(__DIR__ . '/.env');
$dbConfig = require __DIR__ . '/src/Config/database.php';
Database::init($dbConfig);
date_default_timezone_set('Europe/Berlin');

// ── Parse CLI args ──
$queue = 'default';
$once = false;
$timeout = 5;

foreach ($argv as $arg) {
    if (str_starts_with($arg, '--queue=')) {
        $queue = substr($arg, 8);
    }
    if ($arg === '--once') {
        $once = true;
    }
    if (str_starts_with($arg, '--timeout=')) {
        $timeout = max(1, (int) substr($arg, 10));
    }
}

// ── Init Redis Queue ──
RedisQueue::init();

if (!RedisQueue::isConnected()) {
    fwrite(STDERR, json_encode([
        'ts' => date('c'),
        'message' => 'Redis unavailable, falling back to DB worker',
        'queue' => $queue,
    ]) . PHP_EOL);

    // Fallback to DB-based worker
    $worker = new Worker($queue, 1000);
    $worker->registerSignals();
    $worker->run($once);
    exit;
}

// ── Handler map (same as Worker::HANDLERS) ──
$handlers = [
    'notification.dispatch' => \App\Jobs\Handlers\NotificationDispatchHandler::class,
    'presence.cleanup' => \App\Jobs\Handlers\PresenceCleanupHandler::class,
    'search.reindex' => \App\Jobs\Handlers\SearchReindexHandler::class,
    'attachment.process' => \App\Jobs\Handlers\AttachmentProcessHandler::class,
    'retention.cleanup' => \App\Jobs\Handlers\RetentionCleanupHandler::class,
    'compliance.action' => \App\Jobs\Handlers\ComplianceActionHandler::class,
    'push.send' => \App\Jobs\Handlers\PushSendHandler::class,
    'webhook.send' => \App\Jobs\Handlers\WebhookSendHandler::class,
    'knowledge.summarize_thread' => \App\Jobs\Handlers\KnowledgeSummarizeThreadHandler::class,
    'knowledge.summarize_channel' => \App\Jobs\Handlers\KnowledgeSummarizeChannelHandler::class,
    'knowledge.extract' => \App\Jobs\Handlers\KnowledgeExtractHandler::class,
    'linkpreview.unfurl' => \App\Jobs\Handlers\LinkUnfurlHandler::class,
    'task.reminders' => \App\Jobs\Handlers\TaskReminderHandler::class,
];

$workerId = gethostname() . ':' . getmypid();
$shouldStop = false;

// Signal handlers for graceful shutdown
if (function_exists('pcntl_signal')) {
    pcntl_signal(SIGTERM, function () use (&$shouldStop) { $shouldStop = true; });
    pcntl_signal(SIGINT, function () use (&$shouldStop) { $shouldStop = true; });
}

$logEntry = fn(string $msg) => fwrite(STDERR, json_encode([
    'ts' => date('c'),
    'worker' => $workerId,
    'queue' => $queue,
    'driver' => 'redis',
    'message' => $msg,
], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) . PHP_EOL);

$logEntry("Redis worker {$workerId} started on queue [{$queue}]");

// ── Main Loop ──
$recoveryInterval = 0;

while (!$shouldStop) {
    if (function_exists('pcntl_signal_dispatch')) {
        pcntl_signal_dispatch();
    }

    // Periodically recover abandoned jobs
    if (++$recoveryInterval % 60 === 0) {
        RedisQueue::recoverAbandoned($queue);
    }

    // Blocking pop (waits up to $timeout seconds)
    $job = RedisQueue::bPop($queue, $timeout);

    if ($job === null) {
        if ($once) break;
        continue;
    }

    $type = $job['type'] ?? 'unknown';
    $jobId = $job['id'] ?? 'unknown';
    $stop = Metrics::startTimer('job.process');

    $logEntry("Processing job #{$jobId} [{$type}] (attempt {$job['attempts']}/{$job['max_attempts']})");

    $handlerClass = $handlers[$type] ?? null;
    if ($handlerClass === null) {
        RedisQueue::fail($queue, $job, "Unknown job type: {$type}");
        $logEntry("FAIL job #{$jobId}: unknown type [{$type}]");
        if ($once) break;
        continue;
    }

    try {
        $handler = new $handlerClass();
        $handler->handle($job['payload'] ?? []);

        RedisQueue::complete($queue, $job);
        $durationMs = $stop();
        Metrics::inc('job.completed');
        $logEntry("DONE job #{$jobId} [{$type}] ({$durationMs}ms)");
    } catch (\Throwable $e) {
        $error = $e->getMessage() . ' in ' . $e->getFile() . ':' . $e->getLine();
        RedisQueue::fail($queue, $job, $error);
        $durationMs = $stop();
        Metrics::inc('job.failed');
        $logEntry("FAIL job #{$jobId} [{$type}]: {$error}");
    }

    Metrics::flush();

    if ($once) break;
}

$logEntry("Redis worker {$workerId} stopped");

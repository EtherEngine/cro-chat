<?php

declare(strict_types=1);

namespace App\Jobs;

use App\Jobs\Handlers\AttachmentProcessHandler;
use App\Jobs\Handlers\NotificationDispatchHandler;
use App\Jobs\Handlers\PresenceCleanupHandler;
use App\Jobs\Handlers\SearchReindexHandler;
use App\Repositories\JobRepository;

/**
 * Worker loop that claims and executes jobs.
 *
 * Architecture:
 *  - Uses SELECT … FOR UPDATE SKIP LOCKED for concurrent workers
 *  - Each worker has a unique ID (hostname + PID)
 *  - Exponential backoff on failure (30s → 120s → 480s)
 *  - Stale lock detection (10 min timeout) for crashed workers
 *  - Graceful shutdown via SIGTERM/SIGINT
 *
 * Usage:
 *   php worker.php                   # process all queues
 *   php worker.php --queue=default   # only the default queue
 *   php worker.php --once            # process one job and exit
 */
final class Worker
{
    /** Map of job types to handler classes. */
    private const HANDLERS = [
        'notification.dispatch'   => NotificationDispatchHandler::class,
        'presence.cleanup'        => PresenceCleanupHandler::class,
        'search.reindex'          => SearchReindexHandler::class,
        'attachment.process'      => AttachmentProcessHandler::class,
    ];

    private string $workerId;
    private bool $shouldStop = false;
    private int $pollIntervalMs;

    public function __construct(
        private readonly string $queue = 'default',
        int $pollIntervalMs = 1000
    ) {
        $this->workerId = gethostname() . ':' . getmypid();
        $this->pollIntervalMs = $pollIntervalMs;
    }

    /**
     * Register signal handlers for graceful shutdown.
     */
    public function registerSignals(): void
    {
        if (function_exists('pcntl_signal')) {
            pcntl_signal(SIGTERM, fn() => $this->shouldStop = true);
            pcntl_signal(SIGINT, fn() => $this->shouldStop = true);
        }
    }

    /**
     * Main loop: claim → execute → repeat.
     */
    public function run(bool $once = false): void
    {
        $this->log("Worker {$this->workerId} started on queue [{$this->queue}]");

        while (!$this->shouldStop) {
            if (function_exists('pcntl_signal_dispatch')) {
                pcntl_signal_dispatch();
            }

            $job = JobRepository::claim($this->workerId, $this->queue);

            if ($job === null) {
                if ($once) {
                    break;
                }
                usleep($this->pollIntervalMs * 1000);
                continue;
            }

            $this->process($job);

            if ($once) {
                break;
            }
        }

        $this->log("Worker {$this->workerId} stopped");
    }

    /**
     * Process a single claimed job.
     */
    public function process(array $job): void
    {
        $type  = $job['type'];
        $jobId = $job['id'];

        $this->log("Processing job #{$jobId} [{$type}] (attempt {$job['attempts']}/{$job['max_attempts']})");

        $handlerClass = self::HANDLERS[$type] ?? null;
        if ($handlerClass === null) {
            JobRepository::fail($jobId, "Unknown job type: {$type}");
            $this->log("FAIL job #{$jobId}: unknown type [{$type}]");
            return;
        }

        try {
            /** @var JobHandler $handler */
            $handler = new $handlerClass();
            $handler->handle($job['payload']);

            JobRepository::complete($jobId);
            $this->log("DONE job #{$jobId} [{$type}]");
        } catch (\Throwable $e) {
            $error = $e->getMessage() . ' in ' . $e->getFile() . ':' . $e->getLine();
            JobRepository::fail($jobId, $error);
            $this->log("FAIL job #{$jobId} [{$type}]: {$error}");
        }
    }

    /**
     * Resolve a handler class for the given job type (for testing).
     */
    public static function resolveHandler(string $type): ?string
    {
        return self::HANDLERS[$type] ?? null;
    }

    private function log(string $message): void
    {
        $ts = date('Y-m-d H:i:s');
        fwrite(STDERR, "[{$ts}] {$message}" . PHP_EOL);
    }
}

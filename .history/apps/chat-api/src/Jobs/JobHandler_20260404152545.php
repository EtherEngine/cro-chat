<?php

declare(strict_types=1);

namespace App\Jobs;

/**
 * Interface that all job handlers must implement.
 * Handlers must be idempotent — repeated execution with the same
 * payload must produce the same result without side-effects.
 */
interface JobHandler
{
    /**
     * Execute the job.
     *
     * @param array $payload  The job payload from the database.
     * @throws \Throwable  Throwing marks the job as failed (with retry).
     */
    public function handle(array $payload): void;
}

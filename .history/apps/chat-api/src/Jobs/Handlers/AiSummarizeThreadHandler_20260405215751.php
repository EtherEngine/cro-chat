<?php

declare(strict_types=1);

namespace App\Jobs\Handlers;

use App\Jobs\JobHandler;
use App\Services\AiService;
use App\Support\Logger;

/**
 * Async AI thread summarization.
 *
 * Payload:
 *   thread_id  – Thread to summarise
 *   space_id   – Space context
 *   user_id    – Requester (for audit)
 */
final class AiSummarizeThreadHandler implements JobHandler
{
    public function handle(array $payload): void
    {
        $threadId = (int) ($payload['thread_id'] ?? 0);
        $spaceId = (int) ($payload['space_id'] ?? 0);
        $userId = (int) ($payload['user_id'] ?? 0);

        if ($threadId <= 0 || $spaceId <= 0 || $userId <= 0) {
            Logger::warning('ai.summarize_thread.invalid_payload', $payload);
            return;
        }

        try {
            $summary = AiService::summarizeThread($threadId, $spaceId, $userId);
            Logger::info('ai.summarize_thread.done', [
                'thread_id' => $threadId,
                'summary_id' => $summary['id'],
                'model' => $summary['model'],
            ]);
        } catch (\Throwable $e) {
            Logger::error('ai.summarize_thread.failed', [
                'thread_id' => $threadId,
                'error' => $e->getMessage(),
            ]);
            throw $e;
        }
    }
}

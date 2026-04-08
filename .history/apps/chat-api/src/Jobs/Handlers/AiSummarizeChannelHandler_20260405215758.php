<?php

declare(strict_types=1);

namespace App\Jobs\Handlers;

use App\Jobs\JobHandler;
use App\Services\AiService;
use App\Support\Logger;

/**
 * Async AI channel summarization.
 *
 * Payload:
 *   channel_id – Channel to summarise
 *   space_id   – Space context
 *   user_id    – Requester
 */
final class AiSummarizeChannelHandler implements JobHandler
{
    public function handle(array $payload): void
    {
        $channelId = (int) ($payload['channel_id'] ?? 0);
        $spaceId = (int) ($payload['space_id'] ?? 0);
        $userId = (int) ($payload['user_id'] ?? 0);

        if ($channelId <= 0 || $spaceId <= 0 || $userId <= 0) {
            Logger::warning('ai.summarize_channel.invalid_payload', $payload);
            return;
        }

        try {
            $summary = AiService::summarizeChannel($channelId, $spaceId, $userId);
            Logger::info('ai.summarize_channel.done', [
                'channel_id' => $channelId,
                'summary_id' => $summary['id'],
                'model' => $summary['model'],
            ]);
        } catch (\Throwable $e) {
            Logger::error('ai.summarize_channel.failed', [
                'channel_id' => $channelId,
                'error' => $e->getMessage(),
            ]);
            throw $e;
        }
    }
}

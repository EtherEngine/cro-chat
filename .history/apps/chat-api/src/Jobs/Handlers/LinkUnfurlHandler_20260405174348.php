<?php

declare(strict_types=1);

namespace App\Jobs\Handlers;

use App\Jobs\JobHandler;
use App\Services\RichContentService;
use App\Support\Logger;

/**
 * Processes pending link preview unfurling jobs.
 *
 * Payload:
 *  - message_id: int  (the message containing URLs)
 *  - preview_ids: int[]  (IDs of link_previews to unfurl)
 *
 * Also processes any stale pending previews as a catch-all.
 */
final class LinkUnfurlHandler implements JobHandler
{
    public function handle(array $payload): void
    {
        $previewIds = $payload['preview_ids'] ?? [];
        $messageId = $payload['message_id'] ?? null;

        Logger::info("[LinkUnfurl] Processing " . count($previewIds) . " previews for message $messageId");

        foreach ($previewIds as $previewId) {
            RichContentService::unfurlPreview((int) $previewId);
        }

        // Also sweep any stale pending previews
        $extra = RichContentService::processPendingPreviews();
        if ($extra > 0) {
            Logger::info("[LinkUnfurl] Processed $extra additional pending previews");
        }
    }
}

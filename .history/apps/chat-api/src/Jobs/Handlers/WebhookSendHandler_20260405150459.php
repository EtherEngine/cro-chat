<?php

declare(strict_types=1);

namespace App\Jobs\Handlers;

use App\Jobs\JobHandler;
use App\Services\WebhookService;

/**
 * Delivers a single webhook (or retry) asynchronously.
 *
 * Payload: { "delivery_id": int }
 */
final class WebhookSendHandler implements JobHandler
{
    public function handle(array $payload): void
    {
        $deliveryId = (int) ($payload['delivery_id'] ?? 0);
        if ($deliveryId <= 0) {
            return;
        }

        WebhookService::deliver($deliveryId);
    }
}

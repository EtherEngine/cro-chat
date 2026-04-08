<?php

declare(strict_types=1);

namespace App\Services;

use App\Repositories\IntegrationRepository;
use App\Repositories\JobRepository;
use App\Support\Logger;

/**
 * Outgoing webhook delivery with HMAC-SHA256 signatures and retry.
 *
 * Signature scheme (compatible with GitHub/Stripe):
 *   X-Signature-256: sha256=<hex HMAC of raw body>
 *   X-Webhook-Id: <delivery_id>
 *   X-Webhook-Timestamp: <unix timestamp>
 *
 * Retry: Exponential backoff (30s, 2min, 8min, 30min, 2h) — max 5 attempts.
 * Auto-disable: After 10 consecutive failures the webhook is deactivated.
 */
final class WebhookService
{
    private const MAX_ATTEMPTS = 5;
    private const MAX_CONSECUTIVE_FAILURES = 10;
    private const TIMEOUT_SECONDS = 10;

    /** Backoff schedule in seconds per attempt (1-indexed). */
    private const BACKOFF = [1 => 0, 2 => 30, 3 => 120, 4 => 480, 5 => 7200];

    // ── Supported event types ────────────────────────────────

    public const EVENTS = [
        'message.created',
        'message.updated',
        'message.deleted',
        'member.joined',
        'member.left',
        'member.role_changed',
        'channel.created',
        'channel.updated',
        'channel.deleted',
        'reaction.added',
        'reaction.removed',
        'thread.created',
        'thread.reply',
    ];

    /**
     * Dispatch webhook deliveries for a domain event.
     * Called from EventRepository::publish() hook or a job handler.
     */
    public static function dispatch(int $spaceId, string $eventType, array $payload): void
    {
        $webhooks = IntegrationRepository::activeForEvent($spaceId, $eventType);

        foreach ($webhooks as $wh) {
            // Create delivery record
            $delivery = IntegrationRepository::createDelivery([
                'webhook_id' => $wh['id'],
                'event_type' => $eventType,
                'payload' => $payload,
                'attempt' => 1,
                'status' => 'pending',
            ]);

            // Queue async delivery job
            JobRepository::dispatch(
                type: 'webhook.send',
                payload: ['delivery_id' => $delivery['id']],
                queue: 'default',
                maxAttempts: 1, // We manage retries ourselves via delivery records
                idempotencyKey: "webhook-delivery-{$delivery['id']}",
            );
        }
    }

    /**
     * Attempt to deliver a webhook. Called by WebhookSendHandler.
     */
    public static function deliver(int $deliveryId): void
    {
        $delivery = IntegrationRepository::findDelivery($deliveryId);
        if (!$delivery || $delivery['status'] === 'delivered') {
            return;
        }

        $webhook = IntegrationRepository::findWebhook($delivery['webhook_id']);
        if (!$webhook || !$webhook['is_active']) {
            IntegrationRepository::markFailed($deliveryId, $delivery['attempt'], 0, 'Webhook inactive', null);
            return;
        }

        $body = json_encode([
            'event' => $delivery['event_type'],
            'payload' => $delivery['payload'],
            'webhook_id' => $webhook['id'],
            'timestamp' => time(),
        ], JSON_UNESCAPED_UNICODE);

        // Build signature
        $timestamp = time();
        $signaturePayload = "{$timestamp}.{$body}";
        $signature = 'sha256=' . hash_hmac('sha256', $signaturePayload, $webhook['secret']);

        $headers = [
            'Content-Type: application/json',
            'User-Agent: cro-webhooks/1.0',
            "X-Webhook-Id: {$deliveryId}",
            "X-Webhook-Timestamp: {$timestamp}",
            "X-Signature-256: {$signature}",
            "X-Event-Type: {$delivery['event_type']}",
        ];

        $requestHeadersJson = json_encode(array_combine(
            array_map(fn($h) => explode(': ', $h, 2)[0], $headers),
            array_map(fn($h) => explode(': ', $h, 2)[1] ?? '', $headers),
        ));

        // cURL request
        $ch = curl_init($webhook['url']);
        curl_setopt_array($ch, [
            CURLOPT_POST => true,
            CURLOPT_POSTFIELDS => $body,
            CURLOPT_HTTPHEADER => $headers,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT => self::TIMEOUT_SECONDS,
            CURLOPT_CONNECTTIMEOUT => 5,
            CURLOPT_FOLLOWLOCATION => false,
            CURLOPT_PROTOCOLS => CURLPROTO_HTTPS | CURLPROTO_HTTP,
        ]);

        $responseBody = curl_exec($ch);
        $httpCode = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $curlError = curl_error($ch);
        curl_close($ch);

        if ($curlError) {
            $responseBody = "cURL error: {$curlError}";
            $httpCode = 0;
        }

        $responseBodyTrunc = mb_substr((string) $responseBody, 0, 2000);

        // Success: 2xx
        if ($httpCode >= 200 && $httpCode < 300) {
            IntegrationRepository::markDelivered($deliveryId, $httpCode, $responseBodyTrunc, $requestHeadersJson);
            IntegrationRepository::resetFailureCount($webhook['id']);
            Logger::info('webhook.delivered', [
                'webhook_id' => $webhook['id'],
                'delivery_id' => $deliveryId,
                'status' => $httpCode,
            ]);
            return;
        }

        // Failure
        $failureCount = IntegrationRepository::incrementFailure($webhook['id']);
        $attempt = $delivery['attempt'] + 1;

        // Schedule retry if under max attempts
        $nextRetryAt = null;
        if ($attempt <= self::MAX_ATTEMPTS) {
            $backoff = self::BACKOFF[$attempt] ?? 7200;
            $nextRetryAt = date('Y-m-d H:i:s', time() + $backoff);
        }

        IntegrationRepository::markFailed($deliveryId, $attempt, $httpCode, $responseBodyTrunc, $nextRetryAt);

        Logger::warning('webhook.failed', [
            'webhook_id' => $webhook['id'],
            'delivery_id' => $deliveryId,
            'status' => $httpCode,
            'attempt' => $attempt,
            'next_retry' => $nextRetryAt,
        ]);

        // Auto-disable after too many consecutive failures
        if ($failureCount >= self::MAX_CONSECUTIVE_FAILURES) {
            IntegrationRepository::disableWebhook($webhook['id']);
            Logger::warning('webhook.auto_disabled', [
                'webhook_id' => $webhook['id'],
                'failure_count' => $failureCount,
            ]);
        }

        // Queue retry job if applicable
        if ($nextRetryAt) {
            JobRepository::dispatch(
                type: 'webhook.send',
                payload: ['delivery_id' => $deliveryId],
                queue: 'default',
                maxAttempts: 1,
                availableAt: $nextRetryAt,
            );
        }
    }

    /**
     * Process all pending retries. Called periodically or by a worker.
     */
    public static function processRetries(int $limit = 50): int
    {
        $deliveries = IntegrationRepository::pendingRetries($limit);
        $count = 0;
        foreach ($deliveries as $d) {
            self::deliver($d['id']);
            $count++;
        }
        return $count;
    }

    /**
     * Verify an incoming webhook signature.
     */
    public static function verifySignature(string $payload, string $secret, string $signature, int $timestamp): bool
    {
        // Reject if timestamp is older than 5 minutes (replay protection)
        if (abs(time() - $timestamp) > 300) {
            return false;
        }

        $signatureBody = "{$timestamp}.{$payload}";
        $expected = 'sha256=' . hash_hmac('sha256', $signatureBody, $secret);
        return hash_equals($expected, $signature);
    }
}

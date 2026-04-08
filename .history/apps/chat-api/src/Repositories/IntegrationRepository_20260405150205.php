<?php

declare(strict_types=1);

namespace App\Repositories;

use App\Support\Database;

/**
 * CRUD for api_tokens, service_accounts, webhooks, webhook_deliveries, incoming_webhooks.
 */
final class IntegrationRepository
{
    // ── API Tokens ───────────────────────────────────────────

    public static function createToken(array $data): array
    {
        $db = Database::connection();
        $stmt = $db->prepare(
            'INSERT INTO api_tokens (space_id, user_id, service_account_id, name, token_hash, token_prefix, scopes, expires_at)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?)'
        );
        $stmt->execute([
            $data['space_id'],
            $data['user_id'] ?? null,
            $data['service_account_id'] ?? null,
            $data['name'],
            $data['token_hash'],
            $data['token_prefix'],
            json_encode($data['scopes'] ?? [], JSON_UNESCAPED_UNICODE),
            $data['expires_at'] ?? null,
        ]);
        return self::findToken((int) $db->lastInsertId());
    }

    public static function findToken(int $id): ?array
    {
        $stmt = Database::connection()->prepare('SELECT * FROM api_tokens WHERE id = ?');
        $stmt->execute([$id]);
        $row = $stmt->fetch();
        return $row ? self::hydrateToken($row) : null;
    }

    public static function findByTokenHash(string $hash): ?array
    {
        $stmt = Database::connection()->prepare(
            'SELECT * FROM api_tokens WHERE token_hash = ? AND revoked_at IS NULL'
        );
        $stmt->execute([$hash]);
        $row = $stmt->fetch();
        return $row ? self::hydrateToken($row) : null;
    }

    public static function listTokens(int $spaceId, ?int $userId = null): array
    {
        $sql = 'SELECT * FROM api_tokens WHERE space_id = ?';
        $params = [$spaceId];
        if ($userId !== null) {
            $sql .= ' AND user_id = ?';
            $params[] = $userId;
        }
        $sql .= ' ORDER BY created_at DESC';
        $stmt = Database::connection()->prepare($sql);
        $stmt->execute($params);
        return array_map([self::class, 'hydrateToken'], $stmt->fetchAll());
    }

    public static function revokeToken(int $id): void
    {
        Database::connection()->prepare(
            'UPDATE api_tokens SET revoked_at = NOW() WHERE id = ?'
        )->execute([$id]);
    }

    public static function touchToken(int $id): void
    {
        Database::connection()->prepare(
            'UPDATE api_tokens SET last_used_at = NOW() WHERE id = ?'
        )->execute([$id]);
    }

    private static function hydrateToken(array $row): array
    {
        $row['id'] = (int) $row['id'];
        $row['space_id'] = (int) $row['space_id'];
        $row['user_id'] = $row['user_id'] !== null ? (int) $row['user_id'] : null;
        $row['service_account_id'] = $row['service_account_id'] !== null ? (int) $row['service_account_id'] : null;
        $row['scopes'] = json_decode($row['scopes'] ?: '[]', true);
        return $row;
    }

    // ── Service Accounts ─────────────────────────────────────

    public static function createServiceAccount(array $data): array
    {
        $db = Database::connection();
        $stmt = $db->prepare(
            'INSERT INTO service_accounts (space_id, name, description, avatar_color, created_by)
             VALUES (?, ?, ?, ?, ?)'
        );
        $stmt->execute([
            $data['space_id'],
            $data['name'],
            $data['description'] ?? null,
            $data['avatar_color'] ?? '#6366F1',
            $data['created_by'],
        ]);
        return self::findServiceAccount((int) $db->lastInsertId());
    }

    public static function findServiceAccount(int $id): ?array
    {
        $stmt = Database::connection()->prepare('SELECT * FROM service_accounts WHERE id = ?');
        $stmt->execute([$id]);
        $row = $stmt->fetch();
        return $row ? self::hydrateSA($row) : null;
    }

    public static function listServiceAccounts(int $spaceId): array
    {
        $stmt = Database::connection()->prepare(
            'SELECT * FROM service_accounts WHERE space_id = ? ORDER BY name'
        );
        $stmt->execute([$spaceId]);
        return array_map([self::class, 'hydrateSA'], $stmt->fetchAll());
    }

    public static function updateServiceAccount(int $id, array $data): void
    {
        $fields = [];
        $params = [];
        foreach (['name', 'description', 'avatar_color', 'is_active'] as $f) {
            if (array_key_exists($f, $data)) {
                $fields[] = "$f = ?";
                $params[] = $data[$f];
            }
        }
        if (empty($fields))
            return;
        $params[] = $id;
        Database::connection()->prepare(
            'UPDATE service_accounts SET ' . implode(', ', $fields) . ' WHERE id = ?'
        )->execute($params);
    }

    public static function deleteServiceAccount(int $id): void
    {
        Database::connection()->prepare('DELETE FROM service_accounts WHERE id = ?')->execute([$id]);
    }

    private static function hydrateSA(array $row): array
    {
        $row['id'] = (int) $row['id'];
        $row['space_id'] = (int) $row['space_id'];
        $row['created_by'] = (int) $row['created_by'];
        $row['is_active'] = (bool) $row['is_active'];
        return $row;
    }

    // ── Webhooks (outgoing) ──────────────────────────────────

    public static function createWebhook(array $data): array
    {
        $db = Database::connection();
        $stmt = $db->prepare(
            'INSERT INTO webhooks (space_id, name, url, secret, events, created_by)
             VALUES (?, ?, ?, ?, ?, ?)'
        );
        $stmt->execute([
            $data['space_id'],
            $data['name'],
            $data['url'],
            $data['secret'],
            json_encode($data['events'] ?? [], JSON_UNESCAPED_UNICODE),
            $data['created_by'],
        ]);
        return self::findWebhook((int) $db->lastInsertId());
    }

    public static function findWebhook(int $id): ?array
    {
        $stmt = Database::connection()->prepare('SELECT * FROM webhooks WHERE id = ?');
        $stmt->execute([$id]);
        $row = $stmt->fetch();
        return $row ? self::hydrateWebhook($row) : null;
    }

    public static function listWebhooks(int $spaceId): array
    {
        $stmt = Database::connection()->prepare(
            'SELECT * FROM webhooks WHERE space_id = ? ORDER BY created_at DESC'
        );
        $stmt->execute([$spaceId]);
        return array_map([self::class, 'hydrateWebhook'], $stmt->fetchAll());
    }

    /** Find all active webhooks subscribed to a given event type in a space. */
    public static function activeForEvent(int $spaceId, string $eventType): array
    {
        $stmt = Database::connection()->prepare(
            "SELECT * FROM webhooks
             WHERE space_id = ? AND is_active = 1
               AND JSON_CONTAINS(events, ?)
             ORDER BY id"
        );
        $stmt->execute([$spaceId, json_encode($eventType)]);
        return array_map([self::class, 'hydrateWebhook'], $stmt->fetchAll());
    }

    public static function updateWebhook(int $id, array $data): void
    {
        $fields = [];
        $params = [];
        foreach (['name', 'url', 'is_active'] as $f) {
            if (array_key_exists($f, $data)) {
                $fields[] = "$f = ?";
                $params[] = $data[$f];
            }
        }
        if (array_key_exists('events', $data)) {
            $fields[] = 'events = ?';
            $params[] = json_encode($data['events'], JSON_UNESCAPED_UNICODE);
        }
        if (empty($fields))
            return;
        $params[] = $id;
        Database::connection()->prepare(
            'UPDATE webhooks SET ' . implode(', ', $fields) . ' WHERE id = ?'
        )->execute($params);
    }

    public static function deleteWebhook(int $id): void
    {
        Database::connection()->prepare('DELETE FROM webhooks WHERE id = ?')->execute([$id]);
    }

    public static function incrementFailure(int $id): int
    {
        $db = Database::connection();
        $db->prepare(
            'UPDATE webhooks SET failure_count = failure_count + 1 WHERE id = ?'
        )->execute([$id]);
        $stmt = $db->prepare('SELECT failure_count FROM webhooks WHERE id = ?');
        $stmt->execute([$id]);
        return (int) $stmt->fetchColumn();
    }

    public static function disableWebhook(int $id): void
    {
        Database::connection()->prepare(
            'UPDATE webhooks SET is_active = 0, disabled_at = NOW() WHERE id = ?'
        )->execute([$id]);
    }

    public static function resetFailureCount(int $id): void
    {
        Database::connection()->prepare(
            'UPDATE webhooks SET failure_count = 0 WHERE id = ?'
        )->execute([$id]);
    }

    public static function regenerateSecret(int $id): string
    {
        $secret = bin2hex(random_bytes(32));
        Database::connection()->prepare(
            'UPDATE webhooks SET secret = ? WHERE id = ?'
        )->execute([$secret, $id]);
        return $secret;
    }

    private static function hydrateWebhook(array $row): array
    {
        $row['id'] = (int) $row['id'];
        $row['space_id'] = (int) $row['space_id'];
        $row['created_by'] = (int) $row['created_by'];
        $row['is_active'] = (bool) $row['is_active'];
        $row['failure_count'] = (int) $row['failure_count'];
        $row['events'] = json_decode($row['events'] ?: '[]', true);
        return $row;
    }

    // ── Webhook Deliveries ───────────────────────────────────

    public static function createDelivery(array $data): array
    {
        $db = Database::connection();
        $stmt = $db->prepare(
            'INSERT INTO webhook_deliveries (webhook_id, event_type, payload, attempt, status, next_retry_at)
             VALUES (?, ?, ?, ?, ?, ?)'
        );
        $stmt->execute([
            $data['webhook_id'],
            $data['event_type'],
            json_encode($data['payload'], JSON_UNESCAPED_UNICODE),
            $data['attempt'] ?? 1,
            $data['status'] ?? 'pending',
            $data['next_retry_at'] ?? null,
        ]);
        return self::findDelivery((int) $db->lastInsertId());
    }

    public static function findDelivery(int $id): ?array
    {
        $stmt = Database::connection()->prepare('SELECT * FROM webhook_deliveries WHERE id = ?');
        $stmt->execute([$id]);
        $row = $stmt->fetch();
        return $row ? self::hydrateDelivery($row) : null;
    }

    public static function listDeliveries(int $webhookId, int $limit = 50): array
    {
        $stmt = Database::connection()->prepare(
            'SELECT * FROM webhook_deliveries WHERE webhook_id = ? ORDER BY id DESC LIMIT ?'
        );
        $stmt->execute([$webhookId, $limit]);
        return array_map([self::class, 'hydrateDelivery'], $stmt->fetchAll());
    }

    public static function pendingRetries(int $limit = 50): array
    {
        $stmt = Database::connection()->prepare(
            "SELECT * FROM webhook_deliveries
             WHERE status = 'pending' AND next_retry_at <= NOW()
             ORDER BY next_retry_at ASC
             LIMIT ?"
        );
        $stmt->execute([$limit]);
        return array_map([self::class, 'hydrateDelivery'], $stmt->fetchAll());
    }

    public static function markDelivered(int $id, int $responseStatus, ?string $responseBody, ?string $requestHeaders): void
    {
        Database::connection()->prepare(
            "UPDATE webhook_deliveries
             SET status = 'delivered', response_status = ?, response_body = ?, request_headers = ?, delivered_at = NOW()
             WHERE id = ?"
        )->execute([$responseStatus, $responseBody, $requestHeaders, $id]);
    }

    public static function markFailed(int $id, int $attempt, int $responseStatus, ?string $responseBody, ?string $nextRetryAt): void
    {
        $status = $nextRetryAt ? 'pending' : 'failed';
        Database::connection()->prepare(
            'UPDATE webhook_deliveries
             SET status = ?, attempt = ?, response_status = ?, response_body = ?, next_retry_at = ?
             WHERE id = ?'
        )->execute([$status, $attempt, $responseStatus, $responseBody, $nextRetryAt, $id]);
    }

    public static function purgeDeliveries(int $days = 30): int
    {
        $stmt = Database::connection()->prepare(
            'DELETE FROM webhook_deliveries WHERE created_at < DATE_SUB(NOW(), INTERVAL ? DAY)'
        );
        $stmt->execute([$days]);
        return $stmt->rowCount();
    }

    private static function hydrateDelivery(array $row): array
    {
        $row['id'] = (int) $row['id'];
        $row['webhook_id'] = (int) $row['webhook_id'];
        $row['attempt'] = (int) $row['attempt'];
        $row['response_status'] = $row['response_status'] !== null ? (int) $row['response_status'] : null;
        $row['payload'] = json_decode($row['payload'] ?: '{}', true);
        $row['request_headers'] = $row['request_headers'] ? json_decode($row['request_headers'], true) : null;
        return $row;
    }

    // ── Incoming Webhooks ────────────────────────────────────

    public static function createIncoming(array $data): array
    {
        $db = Database::connection();
        $stmt = $db->prepare(
            'INSERT INTO incoming_webhooks (space_id, channel_id, name, slug, provider, secret, avatar_color, created_by)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?)'
        );
        $stmt->execute([
            $data['space_id'],
            $data['channel_id'],
            $data['name'],
            $data['slug'],
            $data['provider'] ?? 'generic',
            $data['secret'] ?? null,
            $data['avatar_color'] ?? '#F59E0B',
            $data['created_by'],
        ]);
        return self::findIncoming((int) $db->lastInsertId());
    }

    public static function findIncoming(int $id): ?array
    {
        $stmt = Database::connection()->prepare('SELECT * FROM incoming_webhooks WHERE id = ?');
        $stmt->execute([$id]);
        $row = $stmt->fetch();
        return $row ? self::hydrateIncoming($row) : null;
    }

    public static function findIncomingBySlug(string $slug): ?array
    {
        $stmt = Database::connection()->prepare('SELECT * FROM incoming_webhooks WHERE slug = ?');
        $stmt->execute([$slug]);
        $row = $stmt->fetch();
        return $row ? self::hydrateIncoming($row) : null;
    }

    public static function listIncoming(int $spaceId): array
    {
        $stmt = Database::connection()->prepare(
            'SELECT * FROM incoming_webhooks WHERE space_id = ? ORDER BY name'
        );
        $stmt->execute([$spaceId]);
        return array_map([self::class, 'hydrateIncoming'], $stmt->fetchAll());
    }

    public static function updateIncoming(int $id, array $data): void
    {
        $fields = [];
        $params = [];
        foreach (['name', 'channel_id', 'provider', 'secret', 'is_active', 'avatar_color'] as $f) {
            if (array_key_exists($f, $data)) {
                $fields[] = "$f = ?";
                $params[] = $data[$f];
            }
        }
        if (empty($fields))
            return;
        $params[] = $id;
        Database::connection()->prepare(
            'UPDATE incoming_webhooks SET ' . implode(', ', $fields) . ' WHERE id = ?'
        )->execute($params);
    }

    public static function deleteIncoming(int $id): void
    {
        Database::connection()->prepare('DELETE FROM incoming_webhooks WHERE id = ?')->execute([$id]);
    }

    private static function hydrateIncoming(array $row): array
    {
        $row['id'] = (int) $row['id'];
        $row['space_id'] = (int) $row['space_id'];
        $row['channel_id'] = (int) $row['channel_id'];
        $row['created_by'] = (int) $row['created_by'];
        $row['is_active'] = (bool) $row['is_active'];
        return $row;
    }
}

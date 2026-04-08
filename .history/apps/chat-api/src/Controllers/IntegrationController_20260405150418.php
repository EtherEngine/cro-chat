<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Exceptions\ApiException;
use App\Repositories\IntegrationRepository;
use App\Repositories\SpaceRepository;
use App\Services\IntegrationService;
use App\Services\RoleService;
use App\Services\WebhookService;
use App\Support\Request;
use App\Support\Response;

/**
 * Manages API tokens, service accounts, outgoing webhooks, and incoming webhooks.
 * All endpoints require space admin/owner role.
 */
final class IntegrationController
{
    // ── Helpers ───────────────────────────────────────────────

    private function requireAdmin(int $spaceId): int
    {
        $userId = Request::requireUserId();
        $role = SpaceRepository::memberRole($spaceId, $userId);
        if (!$role || !RoleService::isSpaceAdminOrAbove($role)) {
            throw ApiException::forbidden('Nur Admins können Integrationen verwalten.');
        }
        return $userId;
    }

    // ══════════════════════════════════════════════════════════
    // API Tokens
    // ══════════════════════════════════════════════════════════

    /** GET /api/v1/spaces/{spaceId}/tokens */
    public function listTokens(array $params): void
    {
        $spaceId = (int) $params['spaceId'];
        $this->requireAdmin($spaceId);

        $tokens = IntegrationRepository::listTokens($spaceId);
        // Strip hash from listing
        $tokens = array_map(function ($t) {
            unset($t['token_hash']);
            return $t;
        }, $tokens);

        Response::json($tokens);
    }

    /** POST /api/v1/spaces/{spaceId}/tokens */
    public function createToken(array $params): void
    {
        $spaceId = (int) $params['spaceId'];
        $userId = $this->requireAdmin($spaceId);
        $input = Request::json();

        $token = IntegrationService::createToken(
            spaceId: $spaceId,
            name: $input['name'] ?? '',
            scopes: $input['scopes'] ?? [],
            userId: $input['service_account_id'] ? null : $userId,
            serviceAccountId: isset($input['service_account_id']) ? (int) $input['service_account_id'] : null,
            expiresAt: $input['expires_at'] ?? null,
        );

        // raw_token is only returned once
        unset($token['token_hash']);
        Response::json($token, 201);
    }

    /** DELETE /api/v1/spaces/{spaceId}/tokens/{tokenId} */
    public function revokeToken(array $params): void
    {
        $spaceId = (int) $params['spaceId'];
        $this->requireAdmin($spaceId);

        IntegrationService::revokeToken((int) $params['tokenId'], $spaceId);
        Response::json(['ok' => true]);
    }

    // ══════════════════════════════════════════════════════════
    // Service Accounts
    // ══════════════════════════════════════════════════════════

    /** GET /api/v1/spaces/{spaceId}/service-accounts */
    public function listServiceAccounts(array $params): void
    {
        $spaceId = (int) $params['spaceId'];
        $this->requireAdmin($spaceId);

        Response::json(IntegrationRepository::listServiceAccounts($spaceId));
    }

    /** POST /api/v1/spaces/{spaceId}/service-accounts */
    public function createServiceAccount(array $params): void
    {
        $spaceId = (int) $params['spaceId'];
        $userId = $this->requireAdmin($spaceId);
        $input = Request::json();

        $sa = IntegrationService::createServiceAccount(
            spaceId: $spaceId,
            name: $input['name'] ?? '',
            createdBy: $userId,
            description: $input['description'] ?? null,
            avatarColor: $input['avatar_color'] ?? null,
        );

        Response::json($sa, 201);
    }

    /** PUT /api/v1/spaces/{spaceId}/service-accounts/{accountId} */
    public function updateServiceAccount(array $params): void
    {
        $spaceId = (int) $params['spaceId'];
        $this->requireAdmin($spaceId);
        $accountId = (int) $params['accountId'];

        $sa = IntegrationRepository::findServiceAccount($accountId);
        if (!$sa || $sa['space_id'] !== $spaceId) {
            throw ApiException::notFound('Service Account nicht gefunden');
        }

        $input = Request::json();
        IntegrationRepository::updateServiceAccount($accountId, $input);
        Response::json(IntegrationRepository::findServiceAccount($accountId));
    }

    /** DELETE /api/v1/spaces/{spaceId}/service-accounts/{accountId} */
    public function deleteServiceAccount(array $params): void
    {
        $spaceId = (int) $params['spaceId'];
        $this->requireAdmin($spaceId);
        $accountId = (int) $params['accountId'];

        $sa = IntegrationRepository::findServiceAccount($accountId);
        if (!$sa || $sa['space_id'] !== $spaceId) {
            throw ApiException::notFound('Service Account nicht gefunden');
        }

        IntegrationRepository::deleteServiceAccount($accountId);
        Response::json(['ok' => true]);
    }

    // ══════════════════════════════════════════════════════════
    // Outgoing Webhooks
    // ══════════════════════════════════════════════════════════

    /** GET /api/v1/spaces/{spaceId}/webhooks */
    public function listWebhooks(array $params): void
    {
        $spaceId = (int) $params['spaceId'];
        $this->requireAdmin($spaceId);

        $webhooks = IntegrationRepository::listWebhooks($spaceId);
        // Mask secrets in listing
        $webhooks = array_map(function ($wh) {
            $wh['secret'] = '••••••••' . substr($wh['secret'], -4);
            return $wh;
        }, $webhooks);

        Response::json($webhooks);
    }

    /** POST /api/v1/spaces/{spaceId}/webhooks */
    public function createWebhook(array $params): void
    {
        $spaceId = (int) $params['spaceId'];
        $userId = $this->requireAdmin($spaceId);
        $input = Request::json();

        $webhook = IntegrationService::createWebhook(
            spaceId: $spaceId,
            name: $input['name'] ?? '',
            url: $input['url'] ?? '',
            events: $input['events'] ?? [],
            createdBy: $userId,
        );

        Response::json($webhook, 201);
    }

    /** PUT /api/v1/spaces/{spaceId}/webhooks/{webhookId} */
    public function updateWebhook(array $params): void
    {
        $spaceId = (int) $params['spaceId'];
        $this->requireAdmin($spaceId);
        $webhookId = (int) $params['webhookId'];

        $wh = IntegrationRepository::findWebhook($webhookId);
        if (!$wh || $wh['space_id'] !== $spaceId) {
            throw ApiException::notFound('Webhook nicht gefunden');
        }

        $input = Request::json();
        IntegrationRepository::updateWebhook($webhookId, $input);
        Response::json(IntegrationRepository::findWebhook($webhookId));
    }

    /** DELETE /api/v1/spaces/{spaceId}/webhooks/{webhookId} */
    public function deleteWebhook(array $params): void
    {
        $spaceId = (int) $params['spaceId'];
        $this->requireAdmin($spaceId);
        $webhookId = (int) $params['webhookId'];

        $wh = IntegrationRepository::findWebhook($webhookId);
        if (!$wh || $wh['space_id'] !== $spaceId) {
            throw ApiException::notFound('Webhook nicht gefunden');
        }

        IntegrationRepository::deleteWebhook($webhookId);
        Response::json(['ok' => true]);
    }

    /** POST /api/v1/spaces/{spaceId}/webhooks/{webhookId}/test */
    public function testWebhook(array $params): void
    {
        $spaceId = (int) $params['spaceId'];
        $this->requireAdmin($spaceId);
        $webhookId = (int) $params['webhookId'];

        $wh = IntegrationRepository::findWebhook($webhookId);
        if (!$wh || $wh['space_id'] !== $spaceId) {
            throw ApiException::notFound('Webhook nicht gefunden');
        }

        WebhookService::dispatch($spaceId, 'webhook.test', [
            'message' => 'Test-Webhook von crø',
            'timestamp' => date('c'),
        ]);

        Response::json(['ok' => true, 'message' => 'Test-Event dispatched']);
    }

    /** POST /api/v1/spaces/{spaceId}/webhooks/{webhookId}/rotate-secret */
    public function rotateWebhookSecret(array $params): void
    {
        $spaceId = (int) $params['spaceId'];
        $this->requireAdmin($spaceId);
        $webhookId = (int) $params['webhookId'];

        $wh = IntegrationRepository::findWebhook($webhookId);
        if (!$wh || $wh['space_id'] !== $spaceId) {
            throw ApiException::notFound('Webhook nicht gefunden');
        }

        $newSecret = IntegrationRepository::regenerateSecret($webhookId);
        Response::json(['secret' => $newSecret]);
    }

    /** GET /api/v1/spaces/{spaceId}/webhooks/{webhookId}/deliveries */
    public function listDeliveries(array $params): void
    {
        $spaceId = (int) $params['spaceId'];
        $this->requireAdmin($spaceId);
        $webhookId = (int) $params['webhookId'];

        $wh = IntegrationRepository::findWebhook($webhookId);
        if (!$wh || $wh['space_id'] !== $spaceId) {
            throw ApiException::notFound('Webhook nicht gefunden');
        }

        Response::json(IntegrationRepository::listDeliveries($webhookId));
    }

    // ══════════════════════════════════════════════════════════
    // Incoming Webhooks
    // ══════════════════════════════════════════════════════════

    /** GET /api/v1/spaces/{spaceId}/incoming-webhooks */
    public function listIncoming(array $params): void
    {
        $spaceId = (int) $params['spaceId'];
        $this->requireAdmin($spaceId);

        Response::json(IntegrationRepository::listIncoming($spaceId));
    }

    /** POST /api/v1/spaces/{spaceId}/incoming-webhooks */
    public function createIncoming(array $params): void
    {
        $spaceId = (int) $params['spaceId'];
        $userId = $this->requireAdmin($spaceId);
        $input = Request::json();

        if (empty($input['channel_id'])) {
            throw ApiException::validation('channel_id ist erforderlich');
        }

        $incoming = IntegrationService::createIncomingWebhook(
            spaceId: $spaceId,
            channelId: (int) $input['channel_id'],
            name: $input['name'] ?? '',
            createdBy: $userId,
            provider: $input['provider'] ?? 'generic',
            avatarColor: $input['avatar_color'] ?? null,
        );

        Response::json($incoming, 201);
    }

    /** PUT /api/v1/spaces/{spaceId}/incoming-webhooks/{incomingId} */
    public function updateIncoming(array $params): void
    {
        $spaceId = (int) $params['spaceId'];
        $this->requireAdmin($spaceId);
        $incomingId = (int) $params['incomingId'];

        $iw = IntegrationRepository::findIncoming($incomingId);
        if (!$iw || $iw['space_id'] !== $spaceId) {
            throw ApiException::notFound('Incoming Webhook nicht gefunden');
        }

        $input = Request::json();
        IntegrationRepository::updateIncoming($incomingId, $input);
        Response::json(IntegrationRepository::findIncoming($incomingId));
    }

    /** DELETE /api/v1/spaces/{spaceId}/incoming-webhooks/{incomingId} */
    public function deleteIncoming(array $params): void
    {
        $spaceId = (int) $params['spaceId'];
        $this->requireAdmin($spaceId);
        $incomingId = (int) $params['incomingId'];

        $iw = IntegrationRepository::findIncoming($incomingId);
        if (!$iw || $iw['space_id'] !== $spaceId) {
            throw ApiException::notFound('Incoming Webhook nicht gefunden');
        }

        IntegrationRepository::deleteIncoming($incomingId);
        Response::json(['ok' => true]);
    }

    // ══════════════════════════════════════════════════════════
    // Meta / Events catalog
    // ══════════════════════════════════════════════════════════

    /** GET /api/v1/integrations/events */
    public function eventsCatalog(array $params): void
    {
        Response::json([
            'events' => WebhookService::EVENTS,
            'scopes' => IntegrationService::SCOPES,
        ]);
    }
}

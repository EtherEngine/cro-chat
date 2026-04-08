<?php

declare(strict_types=1);

namespace App\Services;

use App\Exceptions\ApiException;
use App\Repositories\IntegrationRepository;

/**
 * Business logic for API tokens, service accounts, and integration management.
 *
 * Token format: cro_<random 48 hex chars>  (prefix "cro_" + 24 random bytes).
 * Only the SHA-256 hash is stored; the raw token is returned once on creation.
 */
final class IntegrationService
{
    // ── Allowed scopes ───────────────────────────────────────

    public const SCOPES = [
        'messages.read',
        'messages.write',
        'channels.read',
        'channels.write',
        'members.read',
        'members.write',
        'reactions.read',
        'reactions.write',
        'threads.read',
        'threads.write',
        'webhooks.manage',
        'integrations.manage',
    ];

    // ── API Token Management ─────────────────────────────────

    /**
     * Create a new API token. Returns the token row + raw token (only shown once).
     */
    public static function createToken(
        int $spaceId,
        string $name,
        array $scopes,
        ?int $userId = null,
        ?int $serviceAccountId = null,
        ?string $expiresAt = null
    ): array {
        $name = trim($name);
        if ($name === '' || mb_strlen($name) > 100) {
            throw ApiException::validation('Token-Name muss 1–100 Zeichen lang sein');
        }

        // Validate scopes
        $invalidScopes = array_diff($scopes, self::SCOPES);
        if (!empty($invalidScopes)) {
            throw ApiException::validation('Ungültige Scopes: ' . implode(', ', $invalidScopes));
        }
        if (empty($scopes)) {
            throw ApiException::validation('Mindestens ein Scope erforderlich');
        }

        // Generate token: cro_ + 48 hex chars
        $rawToken = 'cro_' . bin2hex(random_bytes(24));
        $tokenHash = hash('sha256', $rawToken);
        $tokenPrefix = substr($rawToken, 0, 8); // "cro_xxxx"

        $tokenRow = IntegrationRepository::createToken([
            'space_id'           => $spaceId,
            'user_id'            => $userId,
            'service_account_id' => $serviceAccountId,
            'name'               => $name,
            'token_hash'         => $tokenHash,
            'token_prefix'       => $tokenPrefix,
            'scopes'             => $scopes,
            'expires_at'         => $expiresAt,
        ]);

        // Return row + raw token (only time it's visible)
        $tokenRow['raw_token'] = $rawToken;
        return $tokenRow;
    }

    /**
     * Resolve and validate a Bearer token.
     * Returns ['token' => tokenRow, 'user_id' => int|null, 'service_account_id' => int|null].
     */
    public static function resolveToken(string $rawToken): ?array
    {
        if (!str_starts_with($rawToken, 'cro_') || strlen($rawToken) !== 52) {
            return null;
        }

        $hash = hash('sha256', $rawToken);
        $token = IntegrationRepository::findByTokenHash($hash);

        if (!$token) {
            return null;
        }

        // Check expiry
        if ($token['expires_at'] && strtotime($token['expires_at']) < time()) {
            return null;
        }

        // Track usage
        IntegrationRepository::touchToken($token['id']);

        return $token;
    }

    /**
     * Check if a token has a given scope.
     */
    public static function hasScope(array $token, string $scope): bool
    {
        return in_array($scope, $token['scopes'], true);
    }

    /**
     * Revoke a token.
     */
    public static function revokeToken(int $tokenId, int $spaceId): void
    {
        $token = IntegrationRepository::findToken($tokenId);
        if (!$token || $token['space_id'] !== $spaceId) {
            throw ApiException::notFound('Token nicht gefunden');
        }
        IntegrationRepository::revokeToken($tokenId);
    }

    // ── Service Account Management ───────────────────────────

    public static function createServiceAccount(
        int $spaceId,
        string $name,
        int $createdBy,
        ?string $description = null,
        ?string $avatarColor = null
    ): array {
        $name = trim($name);
        if ($name === '' || mb_strlen($name) > 100) {
            throw ApiException::validation('Name muss 1–100 Zeichen lang sein');
        }

        return IntegrationRepository::createServiceAccount([
            'space_id'     => $spaceId,
            'name'         => $name,
            'description'  => $description,
            'avatar_color' => $avatarColor ?? '#6366F1',
            'created_by'   => $createdBy,
        ]);
    }

    // ── Webhook Management ───────────────────────────────────

    public static function createWebhook(
        int $spaceId,
        string $name,
        string $url,
        array $events,
        int $createdBy
    ): array {
        $name = trim($name);
        if ($name === '' || mb_strlen($name) > 100) {
            throw ApiException::validation('Webhook-Name muss 1–100 Zeichen lang sein');
        }

        // Validate URL
        if (!filter_var($url, FILTER_VALIDATE_URL)) {
            throw ApiException::validation('Ungültige Webhook-URL');
        }
        $scheme = parse_url($url, PHP_URL_SCHEME);
        if (!in_array($scheme, ['http', 'https'], true)) {
            throw ApiException::validation('Webhook-URL muss HTTP(S) sein');
        }

        // Validate events
        $invalidEvents = array_diff($events, WebhookService::EVENTS);
        if (!empty($invalidEvents)) {
            throw ApiException::validation('Ungültige Events: ' . implode(', ', $invalidEvents));
        }
        if (empty($events)) {
            throw ApiException::validation('Mindestens ein Event erforderlich');
        }

        // Generate signing secret
        $secret = bin2hex(random_bytes(32));

        $webhook = IntegrationRepository::createWebhook([
            'space_id'   => $spaceId,
            'name'       => $name,
            'url'        => $url,
            'secret'     => $secret,
            'events'     => $events,
            'created_by' => $createdBy,
        ]);

        // Include secret in creation response (only time it's fully visible)
        $webhook['secret'] = $secret;
        return $webhook;
    }

    // ── Incoming Webhook Management ──────────────────────────

    public static function createIncomingWebhook(
        int $spaceId,
        int $channelId,
        string $name,
        int $createdBy,
        string $provider = 'generic',
        ?string $avatarColor = null
    ): array {
        $name = trim($name);
        if ($name === '' || mb_strlen($name) > 100) {
            throw ApiException::validation('Name muss 1–100 Zeichen lang sein');
        }

        $validProviders = ['generic', 'github', 'jira', 'gitlab', 'custom'];
        if (!in_array($provider, $validProviders, true)) {
            throw ApiException::validation('Ungültiger Provider');
        }

        // Generate unique slug
        $slug = self::generateSlug();

        // Generate secret for signature verification
        $secret = bin2hex(random_bytes(32));

        $incoming = IntegrationRepository::createIncoming([
            'space_id'     => $spaceId,
            'channel_id'   => $channelId,
            'name'         => $name,
            'slug'         => $slug,
            'provider'     => $provider,
            'secret'       => $secret,
            'avatar_color' => $avatarColor ?? '#F59E0B',
            'created_by'   => $createdBy,
        ]);

        $incoming['secret'] = $secret;
        return $incoming;
    }

    private static function generateSlug(): string
    {
        return bin2hex(random_bytes(16)); // 32-char hex slug
    }
}

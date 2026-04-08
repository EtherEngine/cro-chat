<?php

declare(strict_types=1);

namespace App\Services;

use App\Exceptions\ApiException;
use App\Support\Database;
use App\Support\SecretManager;
use App\Support\SecurityLogger;

/**
 * SSO Service — OIDC Authorization Code Flow + SAML 2.0.
 */
final class SsoService
{
    // ── Provider CRUD ────────────────────────────

    public static function createProvider(int $spaceId, array $data): array
    {
        $required = ['slug', 'name', 'provider_type'];
        foreach ($required as $f) {
            if (empty($data[$f])) {
                throw ApiException::validation(["$f is required"]);
            }
        }
        if (!in_array($data['provider_type'], ['oidc', 'saml'], true)) {
            throw ApiException::validation(['provider_type must be oidc or saml']);
        }

        $pdo = Database::connection();

        // Encrypt client_secret if present
        $clientSecretEnc = null;
        if (!empty($data['client_secret'])) {
            $clientSecretEnc = SecretManager::encrypt($data['client_secret']);
        }

        $stmt = $pdo->prepare(
            'INSERT INTO sso_providers
             (space_id, slug, name, provider_type, client_id, client_secret_enc,
              issuer_url, authorization_url, token_url, userinfo_url, jwks_url,
              saml_idp_entity_id, saml_sso_url, saml_certificate,
              scopes, attribute_map, auto_provision, enforce_sso, is_active, created_at, updated_at)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, 1, NOW(), NOW())'
        );
        $stmt->execute([
            $spaceId,
            $data['slug'],
            $data['name'],
            $data['provider_type'],
            $data['client_id'] ?? null,
            $clientSecretEnc,
            $data['issuer_url'] ?? null,
            $data['authorization_url'] ?? null,
            $data['token_url'] ?? null,
            $data['userinfo_url'] ?? null,
            $data['jwks_url'] ?? null,
            $data['saml_idp_entity_id'] ?? null,
            $data['saml_sso_url'] ?? null,
            $data['saml_certificate'] ?? null,
            $data['scopes'] ?? 'openid profile email',
            json_encode($data['attribute_map'] ?? ['email' => 'email', 'name' => 'name']),
            (int) ($data['auto_provision'] ?? true),
            (int) ($data['enforce_sso'] ?? false),
        ]);

        $id = (int) $pdo->lastInsertId();
        SecurityLogger::info(null, 'sso.provider_created', ['provider_id' => $id, 'space_id' => $spaceId]);

        return self::getProvider($id);
    }

    public static function getProvider(int $id): array
    {
        $pdo = Database::connection();
        $stmt = $pdo->prepare('SELECT * FROM sso_providers WHERE id = ?');
        $stmt->execute([$id]);
        $row = $stmt->fetch(\PDO::FETCH_ASSOC);
        if (!$row) {
            throw ApiException::notFound('SSO provider not found');
        }
        unset($row['client_secret_enc']);
        return $row;
    }

    public static function listProviders(int $spaceId): array
    {
        $pdo = Database::connection();
        $stmt = $pdo->prepare(
            'SELECT id, space_id, slug, name, provider_type, issuer_url, auto_provision,
                    enforce_sso, is_active, created_at
             FROM sso_providers WHERE space_id = ? AND is_active = 1 ORDER BY name'
        );
        $stmt->execute([$spaceId]);
        return $stmt->fetchAll(\PDO::FETCH_ASSOC);
    }

    public static function updateProvider(int $id, array $data): array
    {
        $provider = self::getProvider($id);
        $sets = [];
        $params = [];

        $allowed = ['name', 'client_id', 'issuer_url', 'authorization_url',
                     'token_url', 'userinfo_url', 'jwks_url', 'saml_idp_entity_id',
                     'saml_sso_url', 'saml_certificate', 'scopes', 'auto_provision',
                     'enforce_sso', 'is_active'];

        foreach ($allowed as $field) {
            if (array_key_exists($field, $data)) {
                $sets[] = "$field = ?";
                $params[] = $data[$field];
            }
        }
        if (!empty($data['client_secret'])) {
            $sets[] = 'client_secret_enc = ?';
            $params[] = SecretManager::encrypt($data['client_secret']);
        }
        if (!empty($data['attribute_map'])) {
            $sets[] = 'attribute_map = ?';
            $params[] = json_encode($data['attribute_map']);
        }
        if (empty($sets)) {
            return $provider;
        }

        $sets[] = 'updated_at = NOW()';
        $params[] = $id;

        $pdo = Database::connection();
        $stmt = $pdo->prepare('UPDATE sso_providers SET ' . implode(', ', $sets) . ' WHERE id = ?');
        $stmt->execute($params);

        return self::getProvider($id);
    }

    public static function deleteProvider(int $id): void
    {
        $pdo = Database::connection();
        $pdo->prepare('DELETE FROM sso_user_links WHERE provider_id = ?')->execute([$id]);
        $pdo->prepare('DELETE FROM sso_providers WHERE id = ?')->execute([$id]);
        SecurityLogger::info(null, 'sso.provider_deleted', ['provider_id' => $id]);
    }

    // ── OIDC Authorization Code Flow ─────────────

    public static function buildOidcAuthUrl(int $providerId, string $state): string
    {
        $pdo = Database::connection();
        $stmt = $pdo->prepare('SELECT * FROM sso_providers WHERE id = ? AND provider_type = ?');
        $stmt->execute([$providerId, 'oidc']);
        $provider = $stmt->fetch(\PDO::FETCH_ASSOC);
        if (!$provider) {
            throw ApiException::notFound('OIDC provider not found');
        }

        $params = [
            'response_type' => 'code',
            'client_id'     => $provider['client_id'],
            'redirect_uri'  => self::callbackUrl($providerId),
            'scope'         => $provider['scopes'] ?: 'openid profile email',
            'state'         => $state,
            'nonce'         => bin2hex(random_bytes(16)),
        ];

        return $provider['authorization_url'] . '?' . http_build_query($params);
    }

    public static function handleOidcCallback(int $providerId, string $code): array
    {
        $pdo = Database::connection();
        $stmt = $pdo->prepare('SELECT * FROM sso_providers WHERE id = ?');
        $stmt->execute([$providerId]);
        $provider = $stmt->fetch(\PDO::FETCH_ASSOC);
        if (!$provider) {
            throw ApiException::notFound('SSO provider not found');
        }

        // Exchange code for tokens
        $clientSecret = '';
        if ($provider['client_secret_enc']) {
            $clientSecret = SecretManager::decrypt($provider['client_secret_enc']);
        }

        $tokenPayload = [
            'grant_type'    => 'authorization_code',
            'code'          => $code,
            'redirect_uri'  => self::callbackUrl($providerId),
            'client_id'     => $provider['client_id'],
            'client_secret' => $clientSecret,
        ];

        $tokenData = self::httpPost($provider['token_url'], $tokenPayload);
        if (empty($tokenData['access_token'])) {
            SecurityLogger::warning(null, 'sso.token_exchange_failed', ['provider_id' => $providerId]);
            throw ApiException::internal('SSO token exchange failed');
        }

        // Fetch userinfo
        $userInfo = self::httpGet($provider['userinfo_url'], $tokenData['access_token']);

        $attrMap = json_decode($provider['attribute_map'] ?: '{}', true);
        $externalId    = $userInfo['sub'] ?? $userInfo['id'] ?? '';
        $externalEmail = $userInfo[$attrMap['email'] ?? 'email'] ?? '';
        $externalName  = $userInfo[$attrMap['name'] ?? 'name'] ?? '';

        if (!$externalId || !$externalEmail) {
            throw ApiException::validation(['SSO response missing required fields']);
        }

        // Link or create user
        $user = self::linkOrCreateUser(
            $provider,
            $externalId,
            $externalEmail,
            $externalName,
            $tokenData['access_token'],
            $tokenData['refresh_token'] ?? null,
            isset($tokenData['expires_in']) ? time() + (int) $tokenData['expires_in'] : null
        );

        SecurityLogger::info($user['id'], 'sso.login', [
            'provider_id' => $providerId,
            'external_id' => $externalId,
        ]);

        return $user;
    }

    // ── SAML ─────────────────────────────────────

    public static function buildSamlAuthUrl(int $providerId, string $relayState): string
    {
        $pdo = Database::connection();
        $stmt = $pdo->prepare('SELECT * FROM sso_providers WHERE id = ? AND provider_type = ?');
        $stmt->execute([$providerId, 'saml']);
        $provider = $stmt->fetch(\PDO::FETCH_ASSOC);
        if (!$provider) {
            throw ApiException::notFound('SAML provider not found');
        }

        $id = '_' . bin2hex(random_bytes(16));
        $issueInstant = gmdate('Y-m-d\TH:i:s\Z');
        $destination = $provider['saml_sso_url'];
        $acsUrl = self::callbackUrl($providerId);

        $request = '<samlp:AuthnRequest xmlns:samlp="urn:oasis:names:tc:SAML:2.0:protocol"'
            . ' ID="' . htmlspecialchars($id, ENT_XML1) . '"'
            . ' Version="2.0"'
            . ' IssueInstant="' . $issueInstant . '"'
            . ' Destination="' . htmlspecialchars($destination, ENT_XML1) . '"'
            . ' AssertionConsumerServiceURL="' . htmlspecialchars($acsUrl, ENT_XML1) . '"'
            . ' ProtocolBinding="urn:oasis:names:tc:SAML:2.0:bindings:HTTP-POST">'
            . '<saml:Issuer xmlns:saml="urn:oasis:names:tc:SAML:2.0:assertion">'
            . htmlspecialchars($acsUrl, ENT_XML1)
            . '</saml:Issuer>'
            . '</samlp:AuthnRequest>';

        $encoded = base64_encode(gzdeflate($request));
        $params = [
            'SAMLRequest' => $encoded,
            'RelayState'  => $relayState,
        ];

        return $destination . '?' . http_build_query($params);
    }

    public static function handleSamlCallback(int $providerId, string $samlResponse): array
    {
        $pdo = Database::connection();
        $stmt = $pdo->prepare('SELECT * FROM sso_providers WHERE id = ?');
        $stmt->execute([$providerId]);
        $provider = $stmt->fetch(\PDO::FETCH_ASSOC);
        if (!$provider) {
            throw ApiException::notFound('SSO provider not found');
        }

        $xml = base64_decode($samlResponse, true);
        if ($xml === false) {
            throw ApiException::validation(['Invalid SAML response']);
        }

        // Parse SAML response
        $doc = new \DOMDocument();
        $prevEntityLoader = libxml_disable_entity_loader(true);
        $loaded = @$doc->loadXML($xml);
        libxml_disable_entity_loader($prevEntityLoader);

        if (!$loaded) {
            throw ApiException::validation(['Malformed SAML XML']);
        }

        // Extract NameID and attributes
        $xpath = new \DOMXPath($doc);
        $xpath->registerNamespace('saml', 'urn:oasis:names:tc:SAML:2.0:assertion');
        $xpath->registerNamespace('samlp', 'urn:oasis:names:tc:SAML:2.0:protocol');

        $statusNode = $xpath->query('//samlp:StatusCode/@Value')->item(0);
        if ($statusNode && !str_contains($statusNode->nodeValue, ':Success')) {
            throw ApiException::validation(['SAML authentication failed']);
        }

        $nameIdNode = $xpath->query('//saml:NameID')->item(0);
        $externalId = $nameIdNode ? $nameIdNode->nodeValue : '';

        // Collect attributes
        $attrs = [];
        $attrNodes = $xpath->query('//saml:Attribute');
        foreach ($attrNodes as $attrNode) {
            $name = $attrNode->getAttribute('Name');
            $valueNode = $xpath->query('saml:AttributeValue', $attrNode)->item(0);
            if ($valueNode) {
                $attrs[$name] = $valueNode->nodeValue;
            }
        }

        $attrMap = json_decode($provider['attribute_map'] ?: '{}', true);
        $externalEmail = $attrs[$attrMap['email'] ?? 'email'] ?? $externalId;
        $externalName  = $attrs[$attrMap['name'] ?? 'name'] ?? '';

        if (!$externalId) {
            throw ApiException::validation(['SAML response missing NameID']);
        }

        $user = self::linkOrCreateUser($provider, $externalId, $externalEmail, $externalName);

        SecurityLogger::info($user['id'], 'sso.saml_login', [
            'provider_id' => $providerId,
            'external_id' => $externalId,
        ]);

        return $user;
    }

    // ── User Link Management ─────────────────────

    public static function getUserLinks(int $userId): array
    {
        $pdo = Database::connection();
        $stmt = $pdo->prepare(
            'SELECT l.id, l.provider_id, l.external_id, l.external_email, l.external_name,
                    l.created_at, p.name AS provider_name, p.provider_type
             FROM sso_user_links l
             JOIN sso_providers p ON p.id = l.provider_id
             WHERE l.user_id = ?'
        );
        $stmt->execute([$userId]);
        return $stmt->fetchAll(\PDO::FETCH_ASSOC);
    }

    public static function unlinkUser(int $userId, int $linkId): void
    {
        $pdo = Database::connection();
        $stmt = $pdo->prepare('DELETE FROM sso_user_links WHERE id = ? AND user_id = ?');
        $stmt->execute([$linkId, $userId]);
        SecurityLogger::info($userId, 'sso.unlinked', ['link_id' => $linkId]);
    }

    /** Check if a space enforces SSO for a user. */
    public static function isEnforced(int $spaceId): bool
    {
        $pdo = Database::connection();
        $stmt = $pdo->prepare(
            'SELECT COUNT(*) FROM sso_providers WHERE space_id = ? AND enforce_sso = 1 AND is_active = 1'
        );
        $stmt->execute([$spaceId]);
        return (int) $stmt->fetchColumn() > 0;
    }

    // ── Internal Helpers ─────────────────────────

    private static function linkOrCreateUser(
        array $provider,
        string $externalId,
        string $externalEmail,
        string $externalName,
        ?string $accessToken = null,
        ?string $refreshToken = null,
        ?int $tokenExpiresAt = null
    ): array {
        $pdo = Database::connection();

        // Check existing link
        $stmt = $pdo->prepare(
            'SELECT user_id FROM sso_user_links WHERE provider_id = ? AND external_id = ?'
        );
        $stmt->execute([$provider['id'], $externalId]);
        $link = $stmt->fetch(\PDO::FETCH_ASSOC);

        if ($link) {
            $userId = (int) $link['user_id'];
            // Update tokens
            $upd = $pdo->prepare(
                'UPDATE sso_user_links SET external_email = ?, external_name = ?,
                        access_token_enc = ?, refresh_token_enc = ?, token_expires_at = ?,
                        updated_at = NOW()
                 WHERE provider_id = ? AND external_id = ?'
            );
            $upd->execute([
                $externalEmail,
                $externalName,
                $accessToken ? SecretManager::encrypt($accessToken) : null,
                $refreshToken ? SecretManager::encrypt($refreshToken) : null,
                $tokenExpiresAt ? date('Y-m-d H:i:s', $tokenExpiresAt) : null,
                $provider['id'],
                $externalId,
            ]);

            $stmt2 = $pdo->prepare('SELECT id, email, display_name FROM users WHERE id = ?');
            $stmt2->execute([$userId]);
            return $stmt2->fetch(\PDO::FETCH_ASSOC);
        }

        // Try to match by email
        $stmt = $pdo->prepare('SELECT id, email, display_name FROM users WHERE email = ?');
        $stmt->execute([$externalEmail]);
        $user = $stmt->fetch(\PDO::FETCH_ASSOC);

        if (!$user && $provider['auto_provision']) {
            // Auto-create user
            $ins = $pdo->prepare(
                'INSERT INTO users (email, password_hash, display_name, status, sso_only, created_at, updated_at)
                 VALUES (?, ?, ?, ?, 1, NOW(), NOW())'
            );
            $ins->execute([
                $externalEmail,
                '', // No password — SSO only
                $externalName ?: explode('@', $externalEmail)[0],
                'offline',
            ]);
            $user = [
                'id' => (int) $pdo->lastInsertId(),
                'email' => $externalEmail,
                'display_name' => $externalName,
            ];

            // Auto-add to space
            $pdo->prepare(
                'INSERT IGNORE INTO space_members (space_id, user_id, role, joined_at)
                 VALUES (?, ?, ?, NOW())'
            )->execute([$provider['space_id'], $user['id'], 'member']);

            SecurityLogger::info($user['id'], 'sso.user_provisioned', [
                'provider_id' => $provider['id'],
                'email' => $externalEmail,
            ]);
        }

        if (!$user) {
            throw ApiException::forbidden('No account linked and auto-provision disabled');
        }

        // Create link
        $ins = $pdo->prepare(
            'INSERT INTO sso_user_links
             (provider_id, user_id, external_id, external_email, external_name,
              access_token_enc, refresh_token_enc, token_expires_at, created_at, updated_at)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?, NOW(), NOW())'
        );
        $ins->execute([
            $provider['id'],
            $user['id'],
            $externalId,
            $externalEmail,
            $externalName,
            $accessToken ? SecretManager::encrypt($accessToken) : null,
            $refreshToken ? SecretManager::encrypt($refreshToken) : null,
            $tokenExpiresAt ? date('Y-m-d H:i:s', $tokenExpiresAt) : null,
        ]);

        return $user;
    }

    private static function callbackUrl(int $providerId): string
    {
        $base = rtrim(\App\Support\Env::get('APP_URL', 'http://localhost/chat-api'), '/');
        return $base . '/api/security/sso/' . $providerId . '/callback';
    }

    private static function httpPost(string $url, array $data): array
    {
        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_POST           => true,
            CURLOPT_POSTFIELDS     => http_build_query($data),
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT        => 10,
            CURLOPT_HTTPHEADER     => ['Accept: application/json'],
        ]);
        $resp = curl_exec($ch);
        curl_close($ch);
        return json_decode($resp ?: '{}', true) ?: [];
    }

    private static function httpGet(string $url, string $bearerToken): array
    {
        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT        => 10,
            CURLOPT_HTTPHEADER     => [
                'Accept: application/json',
                'Authorization: Bearer ' . $bearerToken,
            ],
        ]);
        $resp = curl_exec($ch);
        curl_close($ch);
        return json_decode($resp ?: '{}', true) ?: [];
    }
}

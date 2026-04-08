<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Exceptions\ApiException;
use App\Services\AbuseDetection;
use App\Services\DeviceTracker;
use App\Services\MfaService;
use App\Services\SessionManager;
use App\Services\SsoService;
use App\Support\Request;
use App\Support\Response;
use App\Support\SecurityLogger;
use App\Support\Validator;

final class SecurityController
{
    // ── MFA ──────────────────────────────────────

    public function mfaSetup(): void
    {
        $userId = Request::requireUserId();
        $result = MfaService::setup($userId);
        Response::json($result);
    }

    public function mfaVerify(): void
    {
        $userId = Request::requireUserId();
        $input = Request::json();
        (new Validator($input))->required('code')->string('code')->validate();
        MfaService::verify($userId, $input['code']);
        Response::json(['ok' => true, 'message' => 'MFA enabled']);
    }

    public function mfaValidate(): void
    {
        $userId = Request::requireUserId();
        $input = Request::json();
        (new Validator($input))->required('code')->string('code')->validate();
        $valid = MfaService::validateCode($userId, $input['code']);
        if (!$valid) {
            // Try recovery code
            $valid = MfaService::validateRecoveryCode($userId, $input['code']);
        }
        if (!$valid) {
            throw new ApiException('Invalid MFA code', 401, 'MFA_INVALID');
        }
        Response::json(['ok' => true]);
    }

    public function mfaDisable(): void
    {
        $userId = Request::requireUserId();
        $input = Request::json();
        (new Validator($input))->required('code')->string('code')->validate();
        MfaService::disable($userId, $input['code']);
        Response::json(['ok' => true]);
    }

    public function mfaRecoveryCodes(): void
    {
        $userId = Request::requireUserId();
        $codes = MfaService::regenerateRecoveryCodes($userId);
        Response::json(['recovery_codes' => $codes]);
    }

    public function mfaStatus(): void
    {
        $userId = Request::requireUserId();
        Response::json(MfaService::status($userId));
    }

    // ── SSO ──────────────────────────────────────

    public function ssoProviders(): void
    {
        $spaceId = (int) Request::param('spaceId');
        Response::json(['providers' => SsoService::listProviders($spaceId)]);
    }

    public function ssoCreateProvider(): void
    {
        $spaceId = (int) Request::param('spaceId');
        $input = Request::json();
        $provider = SsoService::createProvider($spaceId, $input);
        Response::json($provider, 201);
    }

    public function ssoUpdateProvider(): void
    {
        $providerId = (int) Request::param('providerId');
        $input = Request::json();
        $provider = SsoService::updateProvider($providerId, $input);
        Response::json($provider);
    }

    public function ssoDeleteProvider(): void
    {
        $providerId = (int) Request::param('providerId');
        SsoService::deleteProvider($providerId);
        Response::json(['ok' => true]);
    }

    public function ssoAuthUrl(): void
    {
        $providerId = (int) Request::param('providerId');
        $input = Request::json();
        $state = $input['state'] ?? bin2hex(random_bytes(16));

        $pdo = \App\Support\Database::connection();
        $stmt = $pdo->prepare('SELECT provider_type FROM sso_providers WHERE id = ?');
        $stmt->execute([$providerId]);
        $type = $stmt->fetchColumn();

        if ($type === 'oidc') {
            $url = SsoService::buildOidcAuthUrl($providerId, $state);
        } elseif ($type === 'saml') {
            $url = SsoService::buildSamlAuthUrl($providerId, $state);
        } else {
            throw ApiException::notFound('Provider not found');
        }

        Response::json(['auth_url' => $url, 'state' => $state]);
    }

    public function ssoCallback(): void
    {
        $providerId = (int) Request::param('providerId');
        $input = Request::json();

        if (!empty($input['code'])) {
            $user = SsoService::handleOidcCallback($providerId, $input['code']);
        } elseif (!empty($input['SAMLResponse'])) {
            $user = SsoService::handleSamlCallback($providerId, $input['SAMLResponse']);
        } else {
            throw ApiException::validation(['code or SAMLResponse required']);
        }

        // Start session
        $_SESSION['user_id'] = $user['id'];
        session_regenerate_id(true);

        Response::json(['user' => $user]);
    }

    public function ssoLinks(): void
    {
        $userId = Request::requireUserId();
        Response::json(['links' => SsoService::getUserLinks($userId)]);
    }

    public function ssoUnlink(): void
    {
        $userId = Request::requireUserId();
        $linkId = (int) Request::param('linkId');
        SsoService::unlinkUser($userId, $linkId);
        Response::json(['ok' => true]);
    }

    // ── Devices ──────────────────────────────────

    public function deviceList(): void
    {
        $userId = Request::requireUserId();
        Response::json(['devices' => DeviceTracker::listDevices($userId)]);
    }

    public function deviceTrust(): void
    {
        $userId = Request::requireUserId();
        $deviceId = (int) Request::param('deviceId');
        DeviceTracker::trust($userId, $deviceId);
        Response::json(['ok' => true]);
    }

    public function deviceRevoke(): void
    {
        $userId = Request::requireUserId();
        $deviceId = (int) Request::param('deviceId');
        DeviceTracker::revoke($userId, $deviceId);
        Response::json(['ok' => true]);
    }

    public function deviceRemove(): void
    {
        $userId = Request::requireUserId();
        $deviceId = (int) Request::param('deviceId');
        DeviceTracker::remove($userId, $deviceId);
        Response::json(['ok' => true]);
    }

    // ── Sessions ─────────────────────────────────

    public function sessionList(): void
    {
        $userId = Request::requireUserId();
        Response::json(['sessions' => SessionManager::listSessions($userId)]);
    }

    public function sessionRevoke(): void
    {
        $userId = Request::requireUserId();
        $sessionId = (int) Request::param('sessionId');
        SessionManager::revoke($userId, $sessionId);
        Response::json(['ok' => true]);
    }

    public function sessionRevokeAll(): void
    {
        $userId = Request::requireUserId();
        $count = SessionManager::revokeAll($userId);
        Response::json(['revoked' => $count]);
    }

    // ── Security Log ─────────────────────────────

    public function securityLog(): void
    {
        $userId = Request::requireUserId();
        $filters = ['user_id' => $userId];
        if ($type = Request::query('event_type')) {
            $filters['event_type'] = $type;
        }
        if ($severity = Request::query('severity')) {
            $filters['severity'] = $severity;
        }
        $limit = min((int) (Request::query('limit') ?: 50), 100);
        $offset = (int) (Request::query('offset') ?: 0);

        $logs = SecurityLogger::query($filters, $limit, $offset);
        $total = SecurityLogger::count($filters);

        Response::json(['logs' => $logs, 'total' => $total]);
    }
}

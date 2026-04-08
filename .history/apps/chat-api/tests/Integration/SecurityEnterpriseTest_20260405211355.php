<?php

declare(strict_types=1);

namespace Tests\Integration;

use App\Exceptions\ApiException;
use App\Services\AbuseDetection;
use App\Services\DeviceTracker;
use App\Services\MfaService;
use App\Services\SessionManager;
use App\Services\SsoService;
use App\Support\Database;
use App\Support\SecretManager;
use App\Support\SecurityLogger;
use Tests\TestCase;

final class SecurityEnterpriseTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        SecretManager::reset();
        SecretManager::init('test-master-key-32-bytes-long!!!');
    }

    // ══════════════════════════════════════════════
    // SecretManager
    // ══════════════════════════════════════════════

    public function test_encrypt_decrypt_roundtrip(): void
    {
        $plain = 'super-secret-value-123';
        $encrypted = SecretManager::encrypt($plain);
        $this->assertNotEquals($plain, $encrypted);
        $this->assertEquals($plain, SecretManager::decrypt($encrypted));
    }

    public function test_encrypt_produces_different_ciphertext(): void
    {
        $plain = 'same-input';
        $a = SecretManager::encrypt($plain);
        $b = SecretManager::encrypt($plain);
        $this->assertNotEquals($a, $b); // different IV each time
        $this->assertEquals($plain, SecretManager::decrypt($a));
        $this->assertEquals($plain, SecretManager::decrypt($b));
    }

    public function test_vault_put_get(): void
    {
        SecretManager::put('api_key', 'sk-12345');
        $this->assertEquals('sk-12345', SecretManager::get('api_key'));
    }

    public function test_vault_overwrite(): void
    {
        SecretManager::put('key1', 'value1');
        SecretManager::put('key1', 'value2');
        $this->assertEquals('value2', SecretManager::get('key1'));
    }

    public function test_vault_delete(): void
    {
        SecretManager::put('temp', 'data');
        $this->assertEquals('data', SecretManager::get('temp'));
        SecretManager::delete('temp');
        $this->assertNull(SecretManager::get('temp'));
    }

    public function test_vault_list_keys(): void
    {
        SecretManager::put('a_key', '1');
        SecretManager::put('b_key', '2');
        $keys = SecretManager::listKeys();
        $this->assertContains('a_key', $keys);
        $this->assertContains('b_key', $keys);
    }

    public function test_vault_rotate(): void
    {
        SecretManager::put('rotate_me', 'old');
        SecretManager::rotate('rotate_me', 'new');
        $this->assertEquals('new', SecretManager::get('rotate_me'));
    }

    public function test_generate_random(): void
    {
        $a = SecretManager::generate(16);
        $b = SecretManager::generate(16);
        $this->assertNotEquals($a, $b);
        $this->assertEquals(32, strlen($a)); // hex = 2x bytes
    }

    public function test_vault_default_fallback(): void
    {
        $this->assertEquals('fallback', SecretManager::get('nonexistent', 'fallback'));
    }

    // ══════════════════════════════════════════════
    // SecurityLogger
    // ══════════════════════════════════════════════

    public function test_log_info(): void
    {
        $user = $this->createUser();
        SecurityLogger::info($user['id'], 'test.event', ['key' => 'val']);

        $logs = SecurityLogger::query(['user_id' => $user['id']]);
        $this->assertCount(1, $logs);
        $this->assertEquals('test.event', $logs[0]['event_type']);
        $this->assertEquals('info', $logs[0]['severity']);
    }

    public function test_log_warning_and_critical(): void
    {
        $user = $this->createUser();
        SecurityLogger::warning($user['id'], 'warn.event');
        SecurityLogger::critical($user['id'], 'crit.event');

        $logs = SecurityLogger::query(['user_id' => $user['id']], 10);
        $this->assertCount(2, $logs);

        $warn = SecurityLogger::query(['severity' => 'warning']);
        $this->assertCount(1, $warn);

        $crit = SecurityLogger::query(['severity' => 'critical']);
        $this->assertCount(1, $crit);
    }

    public function test_log_count(): void
    {
        $user = $this->createUser();
        SecurityLogger::info($user['id'], 'a.event');
        SecurityLogger::info($user['id'], 'b.event');
        SecurityLogger::warning($user['id'], 'c.event');

        $this->assertEquals(3, SecurityLogger::count(['user_id' => $user['id']]));
        $this->assertEquals(1, SecurityLogger::count(['event_type' => 'b.event']));
    }

    public function test_log_null_user(): void
    {
        SecurityLogger::info(null, 'system.event', ['action' => 'boot']);
        $logs = SecurityLogger::query(['event_type' => 'system.event']);
        $this->assertCount(1, $logs);
        $this->assertNull($logs[0]['user_id']);
    }

    public function test_log_purge(): void
    {
        SecurityLogger::info(null, 'old.event');
        // Manually backdate
        Database::connection()->exec(
            "UPDATE security_log SET created_at = DATE_SUB(NOW(), INTERVAL 100 DAY)"
        );
        $purged = SecurityLogger::purge(90);
        $this->assertEquals(1, $purged);
        $this->assertEquals(0, SecurityLogger::count([]));
    }

    // ══════════════════════════════════════════════
    // MFA (TOTP)
    // ══════════════════════════════════════════════

    public function test_mfa_setup_returns_secret_and_codes(): void
    {
        $user = $this->createUser();
        $result = MfaService::setup($user['id']);

        $this->assertArrayHasKey('secret', $result);
        $this->assertArrayHasKey('provisioning_uri', $result);
        $this->assertArrayHasKey('recovery_codes', $result);
        $this->assertCount(8, $result['recovery_codes']);
        $this->assertStringContainsString('otpauth://totp/', $result['provisioning_uri']);
    }

    public function test_mfa_verify_with_valid_totp(): void
    {
        $user = $this->createUser();
        $setup = MfaService::setup($user['id']);

        // Generate valid TOTP from the secret
        $code = $this->generateTotp($setup['secret']);
        MfaService::verify($user['id'], $code);

        $status = MfaService::status($user['id']);
        $this->assertTrue($status['enabled']);
    }

    public function test_mfa_verify_rejects_invalid_code(): void
    {
        $user = $this->createUser();
        MfaService::setup($user['id']);

        $this->assertApiException(400, 'VALIDATION_ERROR', function () use ($user) {
            MfaService::verify($user['id'], '000000');
        });
    }

    public function test_mfa_validate_code_after_enable(): void
    {
        $user = $this->createUser();
        $setup = MfaService::setup($user['id']);
        $code = $this->generateTotp($setup['secret']);
        MfaService::verify($user['id'], $code);

        // Now validate a fresh code
        $fresh = $this->generateTotp($setup['secret']);
        $this->assertTrue(MfaService::validateCode($user['id'], $fresh));
    }

    public function test_mfa_recovery_code_works(): void
    {
        $user = $this->createUser();
        $setup = MfaService::setup($user['id']);
        $code = $this->generateTotp($setup['secret']);
        MfaService::verify($user['id'], $code);

        // Use first recovery code
        $this->assertTrue(MfaService::validateRecoveryCode($user['id'], $setup['recovery_codes'][0]));
        // Same code cannot be reused
        $this->assertFalse(MfaService::validateRecoveryCode($user['id'], $setup['recovery_codes'][0]));
    }

    public function test_mfa_disable(): void
    {
        $user = $this->createUser();
        $setup = MfaService::setup($user['id']);
        $code = $this->generateTotp($setup['secret']);
        MfaService::verify($user['id'], $code);

        $fresh = $this->generateTotp($setup['secret']);
        MfaService::disable($user['id'], $fresh);

        $status = MfaService::status($user['id']);
        $this->assertFalse($status['enabled']);
    }

    public function test_mfa_regenerate_recovery_codes(): void
    {
        $user = $this->createUser();
        $setup = MfaService::setup($user['id']);
        $code = $this->generateTotp($setup['secret']);
        MfaService::verify($user['id'], $code);

        $newCodes = MfaService::regenerateRecoveryCodes($user['id']);
        $this->assertCount(8, $newCodes);
        // Old codes should differ
        $this->assertNotEquals($setup['recovery_codes'], $newCodes);
    }

    public function test_mfa_is_required(): void
    {
        $user = $this->createUser();
        $this->assertFalse(MfaService::isRequired($user['id']));

        $setup = MfaService::setup($user['id']);
        $code = $this->generateTotp($setup['secret']);
        MfaService::verify($user['id'], $code);

        $this->assertTrue(MfaService::isRequired($user['id']));
    }

    // Helper: generate TOTP from base32 secret
    private function generateTotp(string $base32Secret): string
    {
        // Decode base32
        $alphabet = 'ABCDEFGHIJKLMNOPQRSTUVWXYZ234567';
        $binary = '';
        $b32 = strtoupper($base32Secret);
        $buffer = 0;
        $bitsLeft = 0;
        for ($i = 0; $i < strlen($b32); $i++) {
            $val = strpos($alphabet, $b32[$i]);
            if ($val === false)
                continue;
            $buffer = ($buffer << 5) | $val;
            $bitsLeft += 5;
            if ($bitsLeft >= 8) {
                $bitsLeft -= 8;
                $binary .= chr(($buffer >> $bitsLeft) & 0xFF);
            }
        }

        $time = intdiv(time(), 30);
        $msg = pack('N*', 0) . pack('N*', $time);
        $hash = hash_hmac('sha1', $msg, $binary, true);
        $offset = ord($hash[19]) & 0x0F;
        $code = (
            ((ord($hash[$offset]) & 0x7F) << 24) |
            ((ord($hash[$offset + 1]) & 0xFF) << 16) |
            ((ord($hash[$offset + 2]) & 0xFF) << 8) |
            (ord($hash[$offset + 3]) & 0xFF)
        ) % 1000000;

        return str_pad((string) $code, 6, '0', STR_PAD_LEFT);
    }

    // ══════════════════════════════════════════════
    // DeviceTracker
    // ══════════════════════════════════════════════

    public function test_device_track_creates_new_device(): void
    {
        $user = $this->createUser();
        $device = DeviceTracker::track($user['id'], 'Mozilla/5.0 (Windows NT 10.0) Chrome/120.0', '10.0.0.1');

        $this->assertEquals($user['id'], (int) $device['user_id']);
        $this->assertEquals('Chrome', $device['browser']);
        $this->assertEquals('Windows', $device['os']);
        $this->assertEquals('desktop', $device['device_type']);
        $this->assertEquals(0, (int) $device['is_trusted']);
    }

    public function test_device_track_updates_existing(): void
    {
        $user = $this->createUser();
        $ua = 'Mozilla/5.0 (Macintosh) Safari/605.1';
        $d1 = DeviceTracker::track($user['id'], $ua, '1.1.1.1');
        $d2 = DeviceTracker::track($user['id'], $ua, '2.2.2.2');

        $this->assertEquals($d1['id'], $d2['id']);
        $this->assertEquals('2.2.2.2', $d2['ip_address']);
    }

    public function test_device_list(): void
    {
        $user = $this->createUser();
        DeviceTracker::track($user['id'], 'Chrome UA', '1.1.1.1');
        DeviceTracker::track($user['id'], 'Firefox UA', '2.2.2.2');

        $devices = DeviceTracker::listDevices($user['id']);
        $this->assertCount(2, $devices);
    }

    public function test_device_trust_and_revoke(): void
    {
        $user = $this->createUser();
        $ua = 'Mozilla/5.0 (Windows NT 10.0) Chrome/120.0';
        $device = DeviceTracker::track($user['id'], $ua, '1.1.1.1');

        $this->assertFalse(DeviceTracker::isTrusted($user['id'], $ua));
        DeviceTracker::trust($user['id'], (int) $device['id']);
        $this->assertTrue(DeviceTracker::isTrusted($user['id'], $ua));
        DeviceTracker::revoke($user['id'], (int) $device['id']);
        $this->assertFalse(DeviceTracker::isTrusted($user['id'], $ua));
    }

    public function test_device_remove(): void
    {
        $user = $this->createUser();
        $device = DeviceTracker::track($user['id'], 'TestBrowser', '1.1.1.1');

        $this->assertEquals(1, DeviceTracker::count($user['id']));
        DeviceTracker::remove($user['id'], (int) $device['id']);
        $this->assertEquals(0, DeviceTracker::count($user['id']));
    }

    public function test_device_new_logs_security_event(): void
    {
        $user = $this->createUser();
        DeviceTracker::track($user['id'], 'NewBrowser/1.0', '5.5.5.5');

        $logs = SecurityLogger::query(['user_id' => $user['id'], 'event_type' => 'device.new']);
        $this->assertCount(1, $logs);
    }

    // ══════════════════════════════════════════════
    // SessionManager
    // ══════════════════════════════════════════════

    public function test_session_create_and_validate(): void
    {
        $user = $this->createUser();
        $session = SessionManager::create($user['id']);

        $this->assertArrayHasKey('session_token', $session);
        $this->assertEquals(64, strlen($session['session_token']));
        $this->assertFalse($session['mfa_verified']);

        $validated = SessionManager::validate($session['session_token']);
        $this->assertNotNull($validated);
        $this->assertEquals($user['id'], (int) $validated['user_id']);
    }

    public function test_session_invalid_token(): void
    {
        $this->assertNull(SessionManager::validate('nonexistent-token'));
    }

    public function test_session_mfa_verified(): void
    {
        $user = $this->createUser();
        $session = SessionManager::create($user['id']);
        SessionManager::markMfaVerified($session['id']);

        $validated = SessionManager::validate($session['session_token']);
        $this->assertEquals(1, (int) $validated['mfa_verified']);
    }

    public function test_session_list_and_revoke(): void
    {
        $user = $this->createUser();
        $s1 = SessionManager::create($user['id']);
        $s2 = SessionManager::create($user['id']);

        $this->assertCount(2, SessionManager::listSessions($user['id']));

        SessionManager::revoke($user['id'], $s1['id']);
        $this->assertCount(1, SessionManager::listSessions($user['id']));
    }

    public function test_session_revoke_all(): void
    {
        $user = $this->createUser();
        SessionManager::create($user['id']);
        SessionManager::create($user['id']);
        SessionManager::create($user['id']);

        $count = SessionManager::revokeAll($user['id']);
        $this->assertEquals(3, $count);
        $this->assertEquals(0, SessionManager::countActive($user['id']));
    }

    public function test_session_revoke_all_except(): void
    {
        $user = $this->createUser();
        $keep = SessionManager::create($user['id']);
        SessionManager::create($user['id']);

        SessionManager::revokeAll($user['id'], $keep['id']);
        $this->assertEquals(1, SessionManager::countActive($user['id']));
        $this->assertNotNull(SessionManager::validate($keep['session_token']));
    }

    public function test_session_concurrent_limit(): void
    {
        $user = $this->createUser();
        for ($i = 0; $i < 11; $i++) {
            SessionManager::create($user['id']);
        }
        $this->assertEquals(10, SessionManager::countActive($user['id']));
    }

    // ══════════════════════════════════════════════
    // AbuseDetection
    // ══════════════════════════════════════════════

    public function test_abuse_record_violation(): void
    {
        $result = AbuseDetection::recordViolation('ip', '10.0.0.1', 'failed_login');
        $this->assertEquals(10, (int) $result['score']);
    }

    public function test_abuse_accumulates_score(): void
    {
        AbuseDetection::recordViolation('ip', '10.0.0.2', 'failed_login');
        AbuseDetection::recordViolation('ip', '10.0.0.2', 'failed_login');
        $score = AbuseDetection::getScore('ip', '10.0.0.2');
        $this->assertEquals(20, (int) $score['score']);
    }

    public function test_abuse_not_blocked_below_threshold(): void
    {
        AbuseDetection::recordViolation('ip', '10.0.0.3', 'failed_login');
        $this->assertFalse(AbuseDetection::isBlocked('ip', '10.0.0.3'));
    }

    public function test_abuse_manual_block_and_unblock(): void
    {
        AbuseDetection::recordViolation('ip', '10.0.0.4', 'failed_login');
        AbuseDetection::block('ip', '10.0.0.4', 3600);
        $this->assertTrue(AbuseDetection::isBlocked('ip', '10.0.0.4'));

        AbuseDetection::unblock('ip', '10.0.0.4');
        $this->assertFalse(AbuseDetection::isBlocked('ip', '10.0.0.4'));
    }

    public function test_abuse_reset(): void
    {
        AbuseDetection::recordViolation('user', '42', 'spam');
        AbuseDetection::reset('user', '42');
        $score = AbuseDetection::getScore('user', '42');
        $this->assertEquals(0, $score['score']);
    }

    public function test_abuse_cleanup(): void
    {
        AbuseDetection::recordViolation('ip', '99.0.0.1', 'failed_login');
        AbuseDetection::reset('ip', '99.0.0.1');
        // Re-insert with zero score
        Database::connection()->exec(
            "INSERT INTO abuse_scores (subject_type, subject_key, score, last_violation_at, created_at, updated_at)
             VALUES ('ip', '99.0.0.2', 0, NOW(), NOW(), NOW())"
        );
        $cleaned = AbuseDetection::cleanup();
        $this->assertGreaterThanOrEqual(1, $cleaned);
    }

    // ══════════════════════════════════════════════
    // SSO Provider CRUD
    // ══════════════════════════════════════════════

    public function test_sso_create_oidc_provider(): void
    {
        $user = $this->createUser();
        $space = $this->createSpace($user['id']);

        $provider = SsoService::createProvider($space['id'], [
            'slug' => 'google',
            'name' => 'Google SSO',
            'provider_type' => 'oidc',
            'client_id' => 'google-client-id',
            'client_secret' => 'google-secret',
            'issuer_url' => 'https://accounts.google.com',
            'authorization_url' => 'https://accounts.google.com/o/oauth2/auth',
            'token_url' => 'https://oauth2.googleapis.com/token',
            'userinfo_url' => 'https://openidconnect.googleapis.com/v1/userinfo',
        ]);

        $this->assertEquals('google', $provider['slug']);
        $this->assertEquals('oidc', $provider['provider_type']);
        $this->assertArrayNotHasKey('client_secret_enc', $provider);
    }

    public function test_sso_create_saml_provider(): void
    {
        $user = $this->createUser();
        $space = $this->createSpace($user['id']);

        $provider = SsoService::createProvider($space['id'], [
            'slug' => 'okta',
            'name' => 'Okta SAML',
            'provider_type' => 'saml',
            'saml_idp_entity_id' => 'https://okta.example.com/entity',
            'saml_sso_url' => 'https://okta.example.com/sso',
            'saml_certificate' => 'MIIC...cert...',
        ]);

        $this->assertEquals('saml', $provider['provider_type']);
    }

    public function test_sso_list_providers(): void
    {
        $user = $this->createUser();
        $space = $this->createSpace($user['id']);

        SsoService::createProvider($space['id'], [
            'slug' => 'p1',
            'name' => 'P1',
            'provider_type' => 'oidc',
        ]);
        SsoService::createProvider($space['id'], [
            'slug' => 'p2',
            'name' => 'P2',
            'provider_type' => 'saml',
        ]);

        $list = SsoService::listProviders($space['id']);
        $this->assertCount(2, $list);
    }

    public function test_sso_update_provider(): void
    {
        $user = $this->createUser();
        $space = $this->createSpace($user['id']);
        $provider = SsoService::createProvider($space['id'], [
            'slug' => 'test',
            'name' => 'Old Name',
            'provider_type' => 'oidc',
        ]);

        $updated = SsoService::updateProvider((int) $provider['id'], ['name' => 'New Name']);
        $this->assertEquals('New Name', $updated['name']);
    }

    public function test_sso_delete_provider(): void
    {
        $user = $this->createUser();
        $space = $this->createSpace($user['id']);
        $provider = SsoService::createProvider($space['id'], [
            'slug' => 'del',
            'name' => 'Delete Me',
            'provider_type' => 'oidc',
        ]);

        SsoService::deleteProvider((int) $provider['id']);

        $this->assertApiException(404, 'NOT_FOUND', function () use ($provider) {
            SsoService::getProvider((int) $provider['id']);
        });
    }

    public function test_sso_enforce_check(): void
    {
        $user = $this->createUser();
        $space = $this->createSpace($user['id']);

        $this->assertFalse(SsoService::isEnforced($space['id']));

        SsoService::createProvider($space['id'], [
            'slug' => 'enforced',
            'name' => 'Enforced',
            'provider_type' => 'oidc',
            'enforce_sso' => true,
        ]);

        $this->assertTrue(SsoService::isEnforced($space['id']));
    }

    public function test_sso_invalid_type_rejected(): void
    {
        $user = $this->createUser();
        $space = $this->createSpace($user['id']);

        $this->assertApiException(400, 'VALIDATION_ERROR', function () use ($space) {
            SsoService::createProvider($space['id'], [
                'slug' => 'bad',
                'name' => 'Bad',
                'provider_type' => 'ldap',
            ]);
        });
    }
}

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
        $this->assertStringContains('otpauth://totp/', $result['provisioning_uri']);
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
            if ($val === false) continue;
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

    // placeholder for next batch
    private function _endPart1(): void {}
}

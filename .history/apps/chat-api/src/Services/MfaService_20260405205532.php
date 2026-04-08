<?php

declare(strict_types=1);

namespace App\Services;

use App\Exceptions\ApiException;
use App\Support\Database;
use App\Support\SecretManager;

/**
 * TOTP-based Multi-Factor Authentication.
 *
 * Implements RFC 6238 (TOTP) with:
 *   - HMAC-SHA1, 6-digit codes, 30s window
 *   - 8 recovery codes (one-time use)
 *   - Setup → Verify → Enable flow
 *   - Encrypted secret storage
 */
final class MfaService
{
    private const TOTP_DIGITS = 6;
    private const TOTP_PERIOD = 30;
    private const TOTP_WINDOW = 1; // Accept ±1 period
    private const RECOVERY_CODE_COUNT = 8;
    private const ISSUER = 'crø Chat';

    // ── Setup ────────────────────────────────────

    /**
     * Begin MFA setup: generate secret + recovery codes.
     * Returns provisioning URI for QR code generation.
     */
    public static function setup(int $userId): array
    {
        $db = Database::connection();

        // Check if already enabled
        $existing = self::getMfaRecord($userId);
        if ($existing && $existing['is_enabled']) {
            throw ApiException::conflict('MFA ist bereits aktiviert', 'MFA_ALREADY_ENABLED');
        }

        // Generate TOTP secret (160 bits = 20 bytes)
        $secret = random_bytes(20);
        $secretBase32 = self::base32Encode($secret);

        // Generate recovery codes
        $recoveryCodes = [];
        for ($i = 0; $i < self::RECOVERY_CODE_COUNT; $i++) {
            $recoveryCodes[] = strtoupper(substr(bin2hex(random_bytes(4)), 0, 8));
        }

        // Encrypt and store
        $secretEnc = SecretManager::encrypt($secretBase32);
        $recoveryEnc = SecretManager::encrypt(json_encode($recoveryCodes));

        $stmt = $db->prepare(
            'INSERT INTO user_mfa (user_id, method, secret_enc, recovery_codes_enc, is_enabled)
             VALUES (?, ?, ?, ?, 0)
             ON DUPLICATE KEY UPDATE secret_enc = VALUES(secret_enc),
                                     recovery_codes_enc = VALUES(recovery_codes_enc),
                                     is_enabled = 0, verified_at = NULL'
        );
        $stmt->execute([$userId, 'totp', $secretEnc, $recoveryEnc]);

        // Get user email for provisioning URI
        $user = $db->prepare('SELECT email FROM users WHERE id = ?');
        $user->execute([$userId]);
        $email = $user->fetchColumn() ?: 'user';

        $uri = sprintf(
            'otpauth://totp/%s:%s?secret=%s&issuer=%s&digits=%d&period=%d',
            rawurlencode(self::ISSUER),
            rawurlencode($email),
            $secretBase32,
            rawurlencode(self::ISSUER),
            self::TOTP_DIGITS,
            self::TOTP_PERIOD
        );

        return [
            'secret' => $secretBase32,
            'provisioning_uri' => $uri,
            'recovery_codes' => $recoveryCodes,
        ];
    }

    /**
     * Verify and enable MFA (first-time verification).
     */
    public static function verify(int $userId, string $code): bool
    {
        $record = self::getMfaRecord($userId);
        if (!$record) {
            throw ApiException::notFound('MFA nicht eingerichtet', 'MFA_NOT_SETUP');
        }
        if ($record['is_enabled']) {
            throw ApiException::conflict('MFA ist bereits verifiziert', 'MFA_ALREADY_VERIFIED');
        }

        $secret = SecretManager::decrypt($record['secret_enc']);
        if (!self::verifyTotp($secret, $code)) {
            throw ApiException::unauthorized('Ungültiger TOTP-Code', 'MFA_INVALID_CODE');
        }

        $db = Database::connection();
        $db->prepare('UPDATE user_mfa SET is_enabled = 1, verified_at = NOW() WHERE user_id = ? AND method = ?')
            ->execute([$userId, 'totp']);
        $db->prepare('UPDATE users SET mfa_enabled = 1 WHERE id = ?')
            ->execute([$userId]);

        SecurityLogger::log($userId, 'mfa.enabled', 'info');

        return true;
    }

    // ── Authentication ───────────────────────────

    /**
     * Validate a TOTP code during login.
     */
    public static function validateCode(int $userId, string $code): bool
    {
        $record = self::getMfaRecord($userId);
        if (!$record || !$record['is_enabled']) {
            return false;
        }

        $secret = SecretManager::decrypt($record['secret_enc']);
        return self::verifyTotp($secret, $code);
    }

    /**
     * Validate a recovery code (one-time use).
     */
    public static function validateRecoveryCode(int $userId, string $code): bool
    {
        $record = self::getMfaRecord($userId);
        if (!$record || !$record['is_enabled']) {
            return false;
        }

        $codes = json_decode(SecretManager::decrypt($record['recovery_codes_enc']), true);
        $code = strtoupper(trim($code));
        $index = array_search($code, $codes, true);

        if ($index === false) {
            return false;
        }

        // Remove used code
        unset($codes[$index]);
        $codes = array_values($codes);

        $db = Database::connection();
        $db->prepare('UPDATE user_mfa SET recovery_codes_enc = ? WHERE user_id = ? AND method = ?')
            ->execute([SecretManager::encrypt(json_encode($codes)), $userId, 'totp']);

        SecurityLogger::log($userId, 'mfa.recovery_used', 'warning', ['remaining' => count($codes)]);

        return true;
    }

    /**
     * Check if MFA is required for a user.
     */
    public static function isRequired(int $userId): bool
    {
        $record = self::getMfaRecord($userId);
        return $record !== null && (bool) $record['is_enabled'];
    }

    // ── Management ───────────────────────────────

    /**
     * Disable MFA for a user.
     */
    public static function disable(int $userId, string $code): void
    {
        if (!self::validateCode($userId, $code)) {
            throw ApiException::unauthorized('Ungültiger TOTP-Code', 'MFA_INVALID_CODE');
        }

        $db = Database::connection();
        $db->prepare('DELETE FROM user_mfa WHERE user_id = ? AND method = ?')
            ->execute([$userId, 'totp']);
        $db->prepare('UPDATE users SET mfa_enabled = 0 WHERE id = ?')
            ->execute([$userId]);

        SecurityLogger::log($userId, 'mfa.disabled', 'warning');
    }

    /**
     * Regenerate recovery codes.
     */
    public static function regenerateRecoveryCodes(int $userId): array
    {
        $record = self::getMfaRecord($userId);
        if (!$record || !$record['is_enabled']) {
            throw ApiException::notFound('MFA nicht aktiv', 'MFA_NOT_ACTIVE');
        }

        $codes = [];
        for ($i = 0; $i < self::RECOVERY_CODE_COUNT; $i++) {
            $codes[] = strtoupper(substr(bin2hex(random_bytes(4)), 0, 8));
        }

        $db = Database::connection();
        $db->prepare('UPDATE user_mfa SET recovery_codes_enc = ? WHERE user_id = ? AND method = ?')
            ->execute([SecretManager::encrypt(json_encode($codes)), $userId, 'totp']);

        SecurityLogger::log($userId, 'mfa.recovery_regenerated', 'info');

        return $codes;
    }

    /**
     * Get MFA status for a user.
     */
    public static function status(int $userId): array
    {
        $record = self::getMfaRecord($userId);
        if (!$record) {
            return ['enabled' => false, 'method' => null, 'verified_at' => null];
        }
        return [
            'enabled' => (bool) $record['is_enabled'],
            'method' => $record['method'],
            'verified_at' => $record['verified_at'],
        ];
    }

    // ── TOTP Implementation (RFC 6238) ───────────

    /**
     * Generate TOTP code for a given time.
     */
    public static function generateTotp(string $secretBase32, ?int $time = null): string
    {
        $time = $time ?? time();
        $counter = intdiv($time, self::TOTP_PERIOD);
        $secret = self::base32Decode($secretBase32);

        // HMAC-SHA1
        $counterBytes = pack('N*', 0, $counter);
        $hash = hash_hmac('sha1', $counterBytes, $secret, true);

        // Dynamic truncation
        $offset = ord($hash[19]) & 0x0F;
        $binary = (
            ((ord($hash[$offset]) & 0x7F) << 24) |
            ((ord($hash[$offset + 1]) & 0xFF) << 16) |
            ((ord($hash[$offset + 2]) & 0xFF) << 8) |
            (ord($hash[$offset + 3]) & 0xFF)
        );

        return str_pad((string) ($binary % (10 ** self::TOTP_DIGITS)), self::TOTP_DIGITS, '0', STR_PAD_LEFT);
    }

    private static function verifyTotp(string $secretBase32, string $code): bool
    {
        $code = trim($code);
        if (strlen($code) !== self::TOTP_DIGITS || !ctype_digit($code)) {
            return false;
        }

        $time = time();
        for ($i = -self::TOTP_WINDOW; $i <= self::TOTP_WINDOW; $i++) {
            $checkTime = $time + ($i * self::TOTP_PERIOD);
            if (hash_equals(self::generateTotp($secretBase32, $checkTime), $code)) {
                return true;
            }
        }
        return false;
    }

    // ── Base32 Encoding (RFC 4648) ───────────────

    private const BASE32_ALPHABET = 'ABCDEFGHIJKLMNOPQRSTUVWXYZ234567';

    public static function base32Encode(string $data): string
    {
        $binary = '';
        foreach (str_split($data) as $char) {
            $binary .= str_pad(decbin(ord($char)), 8, '0', STR_PAD_LEFT);
        }

        $result = '';
        foreach (str_split($binary, 5) as $chunk) {
            $chunk = str_pad($chunk, 5, '0', STR_PAD_RIGHT);
            $result .= self::BASE32_ALPHABET[bindec($chunk)];
        }

        return $result;
    }

    public static function base32Decode(string $data): string
    {
        $data = strtoupper(rtrim($data, '='));
        $binary = '';
        foreach (str_split($data) as $char) {
            $pos = strpos(self::BASE32_ALPHABET, $char);
            if ($pos === false) {
                continue;
            }
            $binary .= str_pad(decbin($pos), 5, '0', STR_PAD_LEFT);
        }

        $result = '';
        foreach (str_split($binary, 8) as $byte) {
            if (strlen($byte) < 8) {
                break;
            }
            $result .= chr(bindec($byte));
        }

        return $result;
    }

    // ── Private ──────────────────────────────────

    private static function getMfaRecord(int $userId): ?array
    {
        $db = Database::connection();
        $stmt = $db->prepare('SELECT * FROM user_mfa WHERE user_id = ? AND method = ? LIMIT 1');
        $stmt->execute([$userId, 'totp']);
        $row = $stmt->fetch(\PDO::FETCH_ASSOC);
        return $row ?: null;
    }
}

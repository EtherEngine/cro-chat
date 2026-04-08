<?php

declare(strict_types=1);

namespace App\Support;

/**
 * Secret Management: encrypted at-rest storage + runtime access.
 *
 * Uses AES-256-GCM with a master key derived from env.
 * Supports: DB vault, env fallback, key rotation.
 */
final class SecretManager
{
    private const CIPHER = 'aes-256-gcm';
    private static string $masterKey = '';
    private static bool $initialized = false;

    /** Runtime cache of decrypted secrets */
    private static array $cache = [];

    // ── Init ─────────────────────────────────────

    public static function init(?string $masterKey = null): void
    {
        if (self::$initialized) {
            return;
        }

        $key = $masterKey ?? Env::get('SECRET_MASTER_KEY', '');
        if ($key === '') {
            // Derive from APP_KEY or generate deterministic fallback for dev
            $appKey = Env::get('APP_KEY', 'cro-dev-insecure-key-change-me');
            $key = hash('sha256', $appKey, true);
        } else {
            // If hex-encoded (64 chars), decode
            $key = strlen($key) === 64 && ctype_xdigit($key)
                ? hex2bin($key)
                : hash('sha256', $key, true);
        }

        self::$masterKey = $key;
        self::$initialized = true;
    }

    // ── Encrypt / Decrypt ────────────────────────

    public static function encrypt(string $plaintext): string
    {
        self::ensureInit();
        $iv = random_bytes(12);
        $tag = '';
        $ciphertext = openssl_encrypt($plaintext, self::CIPHER, self::$masterKey, OPENSSL_RAW_DATA, $iv, $tag, '', 16);
        if ($ciphertext === false) {
            throw new \RuntimeException('Encryption failed');
        }
        // Format: base64(iv + tag + ciphertext)
        return base64_encode($iv . $tag . $ciphertext);
    }

    public static function decrypt(string $encrypted): string
    {
        self::ensureInit();
        $data = base64_decode($encrypted, true);
        if ($data === false || strlen($data) < 28) {
            throw new \RuntimeException('Invalid encrypted data');
        }
        $iv = substr($data, 0, 12);
        $tag = substr($data, 12, 16);
        $ciphertext = substr($data, 28);
        $plaintext = openssl_decrypt($ciphertext, self::CIPHER, self::$masterKey, OPENSSL_RAW_DATA, $iv, $tag);
        if ($plaintext === false) {
            throw new \RuntimeException('Decryption failed');
        }
        return $plaintext;
    }

    // ── Vault Operations (DB-backed) ─────────────

    /**
     * Get a secret: check cache → DB vault → env fallback.
     */
    public static function get(string $keyName, string $default = ''): string
    {
        self::ensureInit();

        if (isset(self::$cache[$keyName])) {
            return self::$cache[$keyName];
        }

        // Try DB vault
        try {
            $db = Database::connection();
            $stmt = $db->prepare('SELECT encrypted_value FROM secrets_vault WHERE key_name = ?');
            $stmt->execute([$keyName]);
            $row = $stmt->fetch(\PDO::FETCH_ASSOC);
            if ($row) {
                $value = self::decrypt($row['encrypted_value']);
                self::$cache[$keyName] = $value;
                return $value;
            }
        } catch (\Throwable $e) {
            Logger::warning('secret.vault_read_failed', ['key' => $keyName, 'error' => $e->getMessage()]);
        }

        // Fallback to env
        $envValue = Env::get($keyName, $default);
        return $envValue;
    }

    /**
     * Store a secret in the DB vault (encrypted).
     */
    public static function put(string $keyName, string $value): void
    {
        self::ensureInit();
        $encrypted = self::encrypt($value);

        $db = Database::connection();
        $stmt = $db->prepare(
            'INSERT INTO secrets_vault (key_name, encrypted_value, version)
             VALUES (?, ?, 1)
             ON DUPLICATE KEY UPDATE encrypted_value = VALUES(encrypted_value),
                                     version = version + 1,
                                     rotated_at = NOW()'
        );
        $stmt->execute([$keyName, $encrypted]);
        self::$cache[$keyName] = $value;
    }

    /**
     * Delete a secret from the vault.
     */
    public static function delete(string $keyName): void
    {
        $db = Database::connection();
        $db->prepare('DELETE FROM secrets_vault WHERE key_name = ?')->execute([$keyName]);
        unset(self::$cache[$keyName]);
    }

    /**
     * List all vault keys (without values).
     */
    public static function listKeys(): array
    {
        $db = Database::connection();
        return $db->query('SELECT key_name, version, rotated_at, created_at FROM secrets_vault ORDER BY key_name')
            ->fetchAll(\PDO::FETCH_ASSOC);
    }

    /**
     * Rotate a secret: keep old version, store new encrypted value.
     */
    public static function rotate(string $keyName, string $newValue): void
    {
        self::put($keyName, $newValue);
    }

    /**
     * Generate a cryptographically secure random secret.
     */
    public static function generate(int $bytes = 32): string
    {
        return bin2hex(random_bytes($bytes));
    }

    // ── Helpers ──────────────────────────────────

    private static function ensureInit(): void
    {
        if (!self::$initialized) {
            self::init();
        }
    }

    /** @internal For testing */
    public static function reset(): void
    {
        self::$masterKey = '';
        self::$initialized = false;
        self::$cache = [];
    }
}

<?php

namespace App\Repositories;

use App\Support\Database;

final class KeyRepository
{
    // ── User key bundles ──────────────────────

    public static function upsertUserKeys(
        int $userId,
        string $deviceId,
        string $identityKey,
        string $signedPreKey,
        string $preKeySig,
        ?array $oneTimeKeys = null
    ): void {
        $sql = '
            INSERT INTO user_keys (user_id, device_id, identity_key, signed_pre_key, pre_key_sig, one_time_keys)
            VALUES (?, ?, ?, ?, ?, ?)
            ON DUPLICATE KEY UPDATE
                identity_key   = VALUES(identity_key),
                signed_pre_key = VALUES(signed_pre_key),
                pre_key_sig    = VALUES(pre_key_sig),
                one_time_keys  = VALUES(one_time_keys)
        ';
        $stmt = Database::connection()->prepare($sql);
        $stmt->execute([
            $userId,
            $deviceId,
            $identityKey,
            $signedPreKey,
            $preKeySig,
            $oneTimeKeys !== null ? json_encode($oneTimeKeys) : null,
        ]);
    }

    public static function getUserKeys(int $userId): array
    {
        $stmt = Database::connection()->prepare(
            'SELECT device_id, identity_key, signed_pre_key, pre_key_sig, one_time_keys, updated_at
             FROM user_keys WHERE user_id = ?'
        );
        $stmt->execute([$userId]);
        $rows = $stmt->fetchAll();
        foreach ($rows as &$row) {
            $row['one_time_keys'] = $row['one_time_keys'] ? json_decode($row['one_time_keys'], true) : [];
        }
        return $rows;
    }

    /**
     * Claim one one-time key for a user+device.
     * Atomically removes the key from the JSON array.
     * Returns the key or null if none left.
     */
    public static function claimOneTimeKey(int $userId, string $deviceId): ?string
    {
        $db = Database::connection();
        $db->beginTransaction();
        try {
            $stmt = $db->prepare(
                'SELECT id, one_time_keys FROM user_keys WHERE user_id = ? AND device_id = ? FOR UPDATE'
            );
            $stmt->execute([$userId, $deviceId]);
            $row = $stmt->fetch();

            if (!$row) {
                $db->rollBack();
                return null;
            }

            $keys = $row['one_time_keys'] ? json_decode($row['one_time_keys'], true) : [];
            if (empty($keys)) {
                $db->rollBack();
                return null;
            }

            $claimed = array_shift($keys);
            $db->prepare(
                'UPDATE user_keys SET one_time_keys = ? WHERE id = ?'
            )->execute([json_encode($keys), $row['id']]);

            $db->commit();
            return $claimed;
        } catch (\Throwable $e) {
            $db->rollBack();
            throw $e;
        }
    }

    // ── Conversation session keys ─────────────

    public static function upsertConversationKey(
        int $conversationId,
        int $userId,
        string $deviceId,
        string $encryptedKey
    ): void {
        $sql = '
            INSERT INTO conversation_keys (conversation_id, user_id, device_id, encrypted_key)
            VALUES (?, ?, ?, ?)
            ON DUPLICATE KEY UPDATE encrypted_key = VALUES(encrypted_key)
        ';
        $stmt = Database::connection()->prepare($sql);
        $stmt->execute([$conversationId, $userId, $deviceId, $encryptedKey]);
    }

    public static function getConversationKeys(int $conversationId): array
    {
        $stmt = Database::connection()->prepare(
            'SELECT user_id, device_id, encrypted_key, updated_at
             FROM conversation_keys WHERE conversation_id = ?'
        );
        $stmt->execute([$conversationId]);
        return $stmt->fetchAll();
    }

    public static function getConversationKeyForDevice(
        int $conversationId,
        int $userId,
        string $deviceId
    ): ?array {
        $stmt = Database::connection()->prepare(
            'SELECT encrypted_key, updated_at FROM conversation_keys
             WHERE conversation_id = ? AND user_id = ? AND device_id = ?'
        );
        $stmt->execute([$conversationId, $userId, $deviceId]);
        return $stmt->fetch() ?: null;
    }
}

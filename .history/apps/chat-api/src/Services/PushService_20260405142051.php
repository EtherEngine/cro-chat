<?php

declare(strict_types=1);

namespace App\Services;

use App\Repositories\DeviceRepository;
use App\Support\Logger;

/**
 * Push notification delivery service.
 *
 * Supports Web Push (VAPID) for browsers and Tauri desktops.
 * Architecture allows adding FCM/APNs by extending sendToSubscription().
 *
 * Web Push is implemented with raw HTTP using VAPID + ECDSA signing.
 * No external PHP library required — uses openssl_* functions directly.
 */
final class PushService
{
    /**
     * Send a push notification to all active devices of a user in a space.
     *
     * @return array{sent: int, failed: int, errors: string[]}
     */
    public static function sendToUser(int $userId, int $spaceId, array $notificationPayload, ?int $notificationId = null): array
    {
        $subscriptions = DeviceRepository::activeForUserInSpace($userId, $spaceId);
        $result = ['sent' => 0, 'failed' => 0, 'errors' => []];

        foreach ($subscriptions as $sub) {
            try {
                self::sendToSubscription($sub, $notificationPayload, $notificationId);
                DeviceRepository::touch((int) $sub['id']);
                $result['sent']++;
            } catch (\Throwable $e) {
                $result['failed']++;
                $result['errors'][] = "device={$sub['device_id']}: {$e->getMessage()}";

                // If endpoint returned 410 Gone or 404, subscription is dead
                if (str_contains($e->getMessage(), '410') || str_contains($e->getMessage(), '404')) {
                    DeviceRepository::deactivate((int) $sub['id'], $userId);
                }

                DeviceRepository::logDelivery(
                    (int) $sub['id'],
                    $notificationId,
                    'failed',
                    $e->getMessage()
                );
            }
        }

        return $result;
    }

    /**
     * Send a push notification to a single subscription.
     */
    public static function sendToSubscription(array $subscription, array $payload, ?int $notificationId = null): void
    {
        $platform = $subscription['platform'];

        match ($platform) {
            'web', 'desktop' => self::sendWebPush($subscription, $payload, $notificationId),
            // Future: 'android' => self::sendFcm($subscription, $payload),
            // Future: 'ios' => self::sendApns($subscription, $payload),
            default => throw new \RuntimeException("Unsupported push platform: $platform"),
        };
    }

    /**
     * Send a Web Push notification using VAPID.
     */
    private static function sendWebPush(array $subscription, array $payload, ?int $notificationId = null): void
    {
        $endpoint = $subscription['endpoint'] ?? null;
        if (!$endpoint) {
            throw new \RuntimeException('No push endpoint configured');
        }

        $spaceId = (int) $subscription['space_id'];
        $vapid = DeviceRepository::getVapidKeys($spaceId);
        if (!$vapid) {
            throw new \RuntimeException('VAPID keys not configured for space');
        }

        $p256dh = $subscription['p256dh_key'] ?? null;
        $auth = $subscription['auth_key'] ?? null;

        if (!$p256dh || !$auth) {
            throw new \RuntimeException('Missing client encryption keys');
        }

        // Encode the payload as JSON
        $payloadJson = json_encode($payload, JSON_UNESCAPED_UNICODE);

        // Build VAPID JWT
        $jwt = self::buildVapidJwt($endpoint, $vapid['private_key'], $vapid['public_key']);

        // Encrypt the payload using the subscription keys (aes128gcm)
        $encrypted = self::encryptPayload($payloadJson, $p256dh, $auth);

        // Send via cURL
        $headers = [
            'Content-Type: application/octet-stream',
            'Content-Encoding: aes128gcm',
            'TTL: 86400',
            'Urgency: high',
            "Authorization: vapid t=$jwt, k={$vapid['public_key']}",
            'Content-Length: ' . strlen($encrypted),
        ];

        $ch = curl_init($endpoint);
        curl_setopt_array($ch, [
            CURLOPT_POST => true,
            CURLOPT_POSTFIELDS => $encrypted,
            CURLOPT_HTTPHEADER => $headers,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT => 10,
            CURLOPT_CONNECTTIMEOUT => 5,
        ]);

        $response = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $curlError = curl_error($ch);
        curl_close($ch);

        if ($curlError) {
            throw new \RuntimeException("cURL error: $curlError");
        }

        if ($httpCode >= 400) {
            throw new \RuntimeException("Push endpoint returned HTTP $httpCode: $response");
        }

        // Log successful delivery
        DeviceRepository::logDelivery(
            (int) $subscription['id'],
            $notificationId,
            'sent'
        );

        Logger::debug('push.sent', [
            'subscription_id' => $subscription['id'],
            'notification_id' => $notificationId,
            'http_code' => $httpCode,
        ]);
    }

    /**
     * Build a VAPID JWT for Web Push authentication.
     */
    private static function buildVapidJwt(string $endpoint, string $privateKeyB64, string $publicKeyB64): string
    {
        $audience = parse_url($endpoint, PHP_URL_SCHEME) . '://' . parse_url($endpoint, PHP_URL_HOST);
        $expiration = time() + 43200; // 12 hours

        $header = self::base64UrlEncode(json_encode(['typ' => 'JWT', 'alg' => 'ES256']));
        $payload = self::base64UrlEncode(json_encode([
            'aud' => $audience,
            'exp' => $expiration,
            'sub' => 'mailto:noreply@cro.dev',
        ]));

        $signingInput = "$header.$payload";

        // Decode the raw private key from URL-safe base64
        $privateKeyRaw = self::base64UrlDecode($privateKeyB64);

        // Build a DER-encoded PKCS#8 private key for ES256 (P-256)
        $der = self::buildEcPrivateKeyDer($privateKeyRaw);
        $pem = "-----BEGIN EC PRIVATE KEY-----\n" . chunk_split(base64_encode($der), 64, "\n") . "-----END EC PRIVATE KEY-----";

        $key = openssl_pkey_get_private($pem);
        if (!$key) {
            throw new \RuntimeException('Failed to load VAPID private key');
        }

        $sig = '';
        if (!openssl_sign($signingInput, $sig, $key, OPENSSL_ALGO_SHA256)) {
            throw new \RuntimeException('Failed to sign VAPID JWT');
        }

        // Convert DER signature to raw R+S (64 bytes)
        $rawSig = self::derSignatureToRaw($sig);

        return "$header.$payload." . self::base64UrlEncode($rawSig);
    }

    /**
     * Encrypt payload using ECDH + HKDF + AES-128-GCM (aes128gcm encoding).
     *
     * This is a simplified implementation. For production at scale,
     * consider using a dedicated Web Push library.
     */
    private static function encryptPayload(string $payload, string $clientPublicKeyB64, string $authSecretB64): string
    {
        $clientPublicKey = self::base64UrlDecode($clientPublicKeyB64);
        $authSecret = self::base64UrlDecode($authSecretB64);

        // Generate an ephemeral ECDH key pair
        $ecKey = openssl_pkey_new(['curve_name' => 'prime256v1', 'private_key_type' => OPENSSL_KEYTYPE_EC]);
        $details = openssl_pkey_get_details($ecKey);
        $localPublicKey = $details['ec']['x'] . $details['ec']['y'];
        // Uncompressed point format: 0x04 || x || y
        $localPublicKeyUncompressed = "\x04" . $localPublicKey;

        // Derive shared secret via ECDH (using openssl)
        // We need the client's public key as a PEM for openssl_pkey_derive
        $clientPem = self::ecPublicKeyToPem($clientPublicKey);
        $clientKey = openssl_pkey_get_public($clientPem);
        if (!$clientKey) {
            throw new \RuntimeException('Invalid client public key');
        }

        $sharedSecret = openssl_pkey_derive($clientKey, $ecKey);
        if ($sharedSecret === false) {
            throw new \RuntimeException('ECDH key agreement failed');
        }

        // HKDF for auth: IKM=sharedSecret, salt=authSecret, info="WebPush: info\0" || client || server
        $ikm_info = "WebPush: info\0" . $clientPublicKey . $localPublicKeyUncompressed;
        $ikm = hash_hkdf('sha256', $sharedSecret, 32, $ikm_info, $authSecret);

        // Derive content encryption key (CEK) and nonce
        $salt = random_bytes(16);
        $cek = hash_hkdf('sha256', $ikm, 16, "Content-Encoding: aes128gcm\0", $salt);
        $nonce = hash_hkdf('sha256', $ikm, 12, "Content-Encoding: nonce\0", $salt);

        // Pad the payload (add 2-byte padding length + delimiter)
        $padded = $payload . "\x02";

        // Encrypt with AES-128-GCM
        $tag = '';
        $encrypted = openssl_encrypt($padded, 'aes-128-gcm', $cek, OPENSSL_RAW_DATA, $nonce, $tag);
        if ($encrypted === false) {
            throw new \RuntimeException('AES-GCM encryption failed');
        }

        // Build the aes128gcm header: salt(16) || record_size(4) || keyid_len(1) || keyid
        $recordSize = pack('N', 4096);
        $keyIdLen = chr(strlen($localPublicKeyUncompressed));
        $header = $salt . $recordSize . $keyIdLen . $localPublicKeyUncompressed;

        return $header . $encrypted . $tag;
    }

    /**
     * Build a notification payload from an in-app notification.
     */
    public static function buildPayload(array $notification): array
    {
        $type = $notification['type'] ?? 'notification';
        $actorName = $notification['actor_name'] ?? $notification['display_name'] ?? 'Jemand';
        $channelId = $notification['channel_id'] ?? null;
        $conversationId = $notification['conversation_id'] ?? null;
        $messageId = $notification['message_id'] ?? null;
        $threadId = $notification['thread_id'] ?? null;

        // Build title based on notification type
        $title = match ($type) {
            'mention' => "$actorName hat dich erwähnt",
            'dm' => "Neue Nachricht von $actorName",
            'thread_reply' => "$actorName hat im Thread geantwortet",
            'reaction' => "$actorName hat reagiert",
            default => "Neue Benachrichtigung",
        };

        // Build deep link path
        $deepLink = '/';
        if ($channelId) {
            $deepLink = "/channel/$channelId" . ($messageId ? "/message/$messageId" : '');
        } elseif ($conversationId) {
            $deepLink = "/conversation/$conversationId" . ($messageId ? "/message/$messageId" : '');
        }
        if ($threadId) {
            $deepLink .= "?thread=$threadId";
        }

        return [
            'title' => $title,
            'body' => $notification['body'] ?? $notification['message_body'] ?? '',
            'icon' => '/icons/icon-192.png',
            'badge' => '/icons/badge-72.png',
            'tag' => "notification-{$notification['id']}",
            'data' => [
                'notification_id' => $notification['id'] ?? null,
                'type' => $type,
                'channel_id' => $channelId,
                'conversation_id' => $conversationId,
                'message_id' => $messageId,
                'thread_id' => $threadId,
                'deep_link' => $deepLink,
            ],
        ];
    }

    // ── Helper methods ──────────────────────────────────────────────

    private static function base64UrlEncode(string $data): string
    {
        return rtrim(strtr(base64_encode($data), '+/', '-_'), '=');
    }

    private static function base64UrlDecode(string $data): string
    {
        return base64_decode(strtr($data, '-_', '+/') . str_repeat('=', (4 - strlen($data) % 4) % 4));
    }

    /**
     * Build a DER EC private key structure for P-256.
     */
    private static function buildEcPrivateKeyDer(string $rawKey): string
    {
        // SEC1 EC private key DER structure for P-256
        $oid = "\x06\x08\x2a\x86\x48\xce\x3d\x03\x01\x07"; // OID for prime256v1
        $keyData = "\x04\x20" . $rawKey; // OCTET STRING with the 32-byte private key
        $params = "\xa0" . chr(strlen($oid)) . $oid;

        $seq = $keyData . $params;
        $version = "\x02\x01\x01"; // version 1
        $seq = $version . $seq;

        return "\x30" . chr(strlen($seq)) . $seq;
    }

    /**
     * Convert a DER-encoded ECDSA signature to raw R+S format (64 bytes for P-256).
     */
    private static function derSignatureToRaw(string $der): string
    {
        // Parse DER: 0x30 <len> 0x02 <rlen> <r> 0x02 <slen> <s>
        $offset = 2; // skip SEQUENCE tag + length
        if (ord($der[1]) > 127) {
            $offset += (ord($der[1]) & 0x7f);
        }

        // R integer
        $rLen = ord($der[$offset + 1]);
        $r = substr($der, $offset + 2, $rLen);
        $offset += 2 + $rLen;

        // S integer
        $sLen = ord($der[$offset + 1]);
        $s = substr($der, $offset + 2, $sLen);

        // Pad/trim to 32 bytes each
        $r = str_pad(ltrim($r, "\x00"), 32, "\x00", STR_PAD_LEFT);
        $s = str_pad(ltrim($s, "\x00"), 32, "\x00", STR_PAD_LEFT);

        return $r . $s;
    }

    /**
     * Convert an uncompressed EC public key to PEM format for openssl_pkey_get_public.
     */
    private static function ecPublicKeyToPem(string $rawPublicKey): string
    {
        // Ensure uncompressed format
        if ($rawPublicKey[0] !== "\x04" && strlen($rawPublicKey) === 64) {
            $rawPublicKey = "\x04" . $rawPublicKey;
        }

        // Build SubjectPublicKeyInfo DER
        $oid = "\x06\x07\x2a\x86\x48\xce\x3d\x02\x01"; // EC public key OID
        $curveOid = "\x06\x08\x2a\x86\x48\xce\x3d\x03\x01\x07"; // prime256v1

        $algoSeq = "\x30" . chr(strlen($oid . $curveOid)) . $oid . $curveOid;
        $bitString = "\x03" . chr(strlen($rawPublicKey) + 1) . "\x00" . $rawPublicKey;

        $spki = $algoSeq . $bitString;
        $der = "\x30" . chr(strlen($spki)) . $spki;

        return "-----BEGIN PUBLIC KEY-----\n" . chunk_split(base64_encode($der), 64, "\n") . "-----END PUBLIC KEY-----";
    }

    /**
     * Generate VAPID key pair for a space (if not already set).
     *
     * @return array{public_key: string, private_key: string}
     */
    public static function generateVapidKeys(int $spaceId): array
    {
        $existing = DeviceRepository::getVapidKeys($spaceId);
        if ($existing) {
            return ['public_key' => $existing['public_key'], 'private_key' => $existing['private_key']];
        }

        $key = openssl_pkey_new(['curve_name' => 'prime256v1', 'private_key_type' => OPENSSL_KEYTYPE_EC]);
        $details = openssl_pkey_get_details($key);

        $publicKey = self::base64UrlEncode("\x04" . $details['ec']['x'] . $details['ec']['y']);
        $privateKey = self::base64UrlEncode($details['ec']['d']);

        DeviceRepository::storeVapidKeys($spaceId, $publicKey, $privateKey);

        return ['public_key' => $publicKey, 'private_key' => $privateKey];
    }
}

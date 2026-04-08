<?php

declare(strict_types=1);

namespace App\Services;

use App\Support\Database;
use App\Support\SecurityLogger;

/**
 * Device tracking — fingerprinting, trust management, anomaly detection.
 */
final class DeviceTracker
{
    /** Register or update device for a user. Returns device row. */
    public static function track(int $userId, ?string $userAgent = null, ?string $ip = null): array
    {
        $ua = $userAgent ?? ($_SERVER['HTTP_USER_AGENT'] ?? '');
        $ip = $ip ?? ($_SERVER['REMOTE_ADDR'] ?? '0.0.0.0');

        $parsed = self::parseUserAgent($ua);
        $hash = self::fingerprint($userId, $parsed['browser'], $parsed['os'], $ua);

        $pdo = Database::connection();

        // Check existing
        $stmt = $pdo->prepare(
            'SELECT * FROM user_devices WHERE user_id = ? AND device_hash = ?'
        );
        $stmt->execute([$userId, $hash]);
        $device = $stmt->fetch(\PDO::FETCH_ASSOC);

        if ($device) {
            $pdo->prepare(
                'UPDATE user_devices SET ip_address = ?, last_active_at = NOW() WHERE id = ?'
            )->execute([$ip, $device['id']]);
            $device['ip_address'] = $ip;
            $device['last_active_at'] = date('Y-m-d H:i:s');
            return $device;
        }

        // New device — log security event
        $stmt = $pdo->prepare(
            'INSERT INTO user_devices
             (user_id, device_hash, device_name, device_type, browser, os, ip_address,
              is_trusted, last_active_at, first_seen_at)
             VALUES (?, ?, ?, ?, ?, ?, ?, 0, NOW(), NOW())'
        );
        $stmt->execute([
            $userId,
            $hash,
            $parsed['device_name'],
            $parsed['device_type'],
            $parsed['browser'],
            $parsed['os'],
            $ip,
        ]);

        $id = (int) $pdo->lastInsertId();
        SecurityLogger::warning($userId, 'device.new', [
            'device_id'   => $id,
            'browser'     => $parsed['browser'],
            'os'          => $parsed['os'],
            'ip'          => $ip,
        ]);

        $stmt = $pdo->prepare('SELECT * FROM user_devices WHERE id = ?');
        $stmt->execute([$id]);
        return $stmt->fetch(\PDO::FETCH_ASSOC);
    }

    /** List all devices for a user. */
    public static function listDevices(int $userId): array
    {
        $pdo = Database::connection();
        $stmt = $pdo->prepare(
            'SELECT * FROM user_devices WHERE user_id = ? ORDER BY last_active_at DESC'
        );
        $stmt->execute([$userId]);
        return $stmt->fetchAll(\PDO::FETCH_ASSOC);
    }

    /** Trust a device. */
    public static function trust(int $userId, int $deviceId): void
    {
        $pdo = Database::connection();
        $pdo->prepare(
            'UPDATE user_devices SET is_trusted = 1 WHERE id = ? AND user_id = ?'
        )->execute([$deviceId, $userId]);
        SecurityLogger::info($userId, 'device.trusted', ['device_id' => $deviceId]);
    }

    /** Revoke trust. */
    public static function revoke(int $userId, int $deviceId): void
    {
        $pdo = Database::connection();
        $pdo->prepare(
            'UPDATE user_devices SET is_trusted = 0 WHERE id = ? AND user_id = ?'
        )->execute([$deviceId, $userId]);
        SecurityLogger::info($userId, 'device.revoked', ['device_id' => $deviceId]);
    }

    /** Remove a device entirely. */
    public static function remove(int $userId, int $deviceId): void
    {
        $pdo = Database::connection();
        // Also kill linked sessions
        $pdo->prepare(
            'UPDATE user_sessions SET is_active = 0 WHERE device_id = ? AND user_id = ?'
        )->execute([$deviceId, $userId]);
        $pdo->prepare(
            'DELETE FROM user_devices WHERE id = ? AND user_id = ?'
        )->execute([$deviceId, $userId]);
        SecurityLogger::info($userId, 'device.removed', ['device_id' => $deviceId]);
    }

    /** Check if current device is trusted. */
    public static function isTrusted(int $userId, ?string $userAgent = null): bool
    {
        $ua = $userAgent ?? ($_SERVER['HTTP_USER_AGENT'] ?? '');
        $parsed = self::parseUserAgent($ua);
        $hash = self::fingerprint($userId, $parsed['browser'], $parsed['os'], $ua);

        $pdo = Database::connection();
        $stmt = $pdo->prepare(
            'SELECT is_trusted FROM user_devices WHERE user_id = ? AND device_hash = ?'
        );
        $stmt->execute([$userId, $hash]);
        $row = $stmt->fetch(\PDO::FETCH_ASSOC);
        return $row && (int) $row['is_trusted'] === 1;
    }

    /** Count user devices. */
    public static function count(int $userId): int
    {
        $pdo = Database::connection();
        $stmt = $pdo->prepare('SELECT COUNT(*) FROM user_devices WHERE user_id = ?');
        $stmt->execute([$userId]);
        return (int) $stmt->fetchColumn();
    }

    // ── Helpers ──────────────────────────────────

    private static function fingerprint(int $userId, string $browser, string $os, string $ua): string
    {
        return hash('sha256', $userId . '|' . $browser . '|' . $os . '|' . $ua);
    }

    private static function parseUserAgent(string $ua): array
    {
        $browser = 'Unknown';
        $os = 'Unknown';
        $type = 'unknown';

        // Browser detection
        if (preg_match('/Firefox\/[\d.]+/', $ua)) {
            $browser = 'Firefox';
        } elseif (preg_match('/Edg\/[\d.]+/', $ua)) {
            $browser = 'Edge';
        } elseif (preg_match('/Chrome\/[\d.]+/', $ua)) {
            $browser = 'Chrome';
        } elseif (preg_match('/Safari\/[\d.]+/', $ua) && !str_contains($ua, 'Chrome')) {
            $browser = 'Safari';
        }

        // OS detection
        if (str_contains($ua, 'Windows')) {
            $os = 'Windows';
            $type = 'desktop';
        } elseif (str_contains($ua, 'Macintosh')) {
            $os = 'macOS';
            $type = 'desktop';
        } elseif (str_contains($ua, 'Linux') && !str_contains($ua, 'Android')) {
            $os = 'Linux';
            $type = 'desktop';
        } elseif (str_contains($ua, 'Android')) {
            $os = 'Android';
            $type = 'mobile';
        } elseif (str_contains($ua, 'iPhone') || str_contains($ua, 'iPad')) {
            $os = 'iOS';
            $type = str_contains($ua, 'iPad') ? 'tablet' : 'mobile';
        }

        return [
            'browser'     => $browser,
            'os'          => $os,
            'device_type' => $type,
            'device_name' => $browser . ' on ' . $os,
        ];
    }
}

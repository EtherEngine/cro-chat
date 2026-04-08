<?php

declare(strict_types=1);

namespace App\Services;

use App\Exceptions\ApiException;
use App\Support\Database;
use App\Support\SecurityLogger;

/**
 * DB-backed session management with device linking, concurrent limits, force logout.
 */
final class SessionManager
{
    private const MAX_SESSIONS_PER_USER = 10;
    private const DEFAULT_LIFETIME      = 7200; // 2 hours

    /** Create a new session for user + device. */
    public static function create(int $userId, ?int $deviceId = null): array
    {
        $pdo = Database::connection();

        // Enforce concurrent session limit
        $stmt = $pdo->prepare(
            'SELECT COUNT(*) FROM user_sessions WHERE user_id = ? AND is_active = 1'
        );
        $stmt->execute([$userId]);
        $count = (int) $stmt->fetchColumn();

        if ($count >= self::MAX_SESSIONS_PER_USER) {
            // Kill oldest active session
            $pdo->prepare(
                'UPDATE user_sessions SET is_active = 0
                 WHERE user_id = ? AND is_active = 1
                 ORDER BY last_activity_at ASC LIMIT 1'
            )->execute([$userId]);
        }

        $token = bin2hex(random_bytes(32)); // 64-char hex
        $lifetime = (int) (\App\Support\Env::get('SESSION_LIFETIME', (string) self::DEFAULT_LIFETIME));
        $expiresAt = date('Y-m-d H:i:s', time() + $lifetime);

        $stmt = $pdo->prepare(
            'INSERT INTO user_sessions
             (user_id, session_token, device_id, ip_address, user_agent,
              is_active, mfa_verified, expires_at, last_activity_at, created_at)
             VALUES (?, ?, ?, ?, ?, 1, 0, ?, NOW(), NOW())'
        );
        $stmt->execute([
            $userId,
            $token,
            $deviceId,
            $_SERVER['REMOTE_ADDR'] ?? '0.0.0.0',
            mb_substr($_SERVER['HTTP_USER_AGENT'] ?? '', 0, 500),
            $expiresAt,
        ]);

        $id = (int) $pdo->lastInsertId();
        SecurityLogger::info($userId, 'session.created', ['session_id' => $id]);

        return [
            'id'               => $id,
            'session_token'    => $token,
            'expires_at'       => $expiresAt,
            'mfa_verified'     => false,
        ];
    }

    /** Validate and refresh a session. Returns user_id or null. */
    public static function validate(string $token): ?array
    {
        $pdo = Database::connection();
        $stmt = $pdo->prepare(
            'SELECT * FROM user_sessions
             WHERE session_token = ? AND is_active = 1 AND expires_at > NOW()'
        );
        $stmt->execute([$token]);
        $session = $stmt->fetch(\PDO::FETCH_ASSOC);

        if (!$session) {
            return null;
        }

        // Touch last activity
        $pdo->prepare(
            'UPDATE user_sessions SET last_activity_at = NOW() WHERE id = ?'
        )->execute([$session['id']]);

        return $session;
    }

    /** Mark session as MFA-verified. */
    public static function markMfaVerified(int $sessionId): void
    {
        $pdo = Database::connection();
        $pdo->prepare(
            'UPDATE user_sessions SET mfa_verified = 1 WHERE id = ?'
        )->execute([$sessionId]);
    }

    /** List active sessions for a user. */
    public static function listSessions(int $userId): array
    {
        $pdo = Database::connection();
        $stmt = $pdo->prepare(
            'SELECT s.id, s.device_id, s.ip_address, s.user_agent, s.mfa_verified,
                    s.expires_at, s.last_activity_at, s.created_at,
                    d.device_name, d.browser, d.os
             FROM user_sessions s
             LEFT JOIN user_devices d ON d.id = s.device_id
             WHERE s.user_id = ? AND s.is_active = 1 AND s.expires_at > NOW()
             ORDER BY s.last_activity_at DESC'
        );
        $stmt->execute([$userId]);
        return $stmt->fetchAll(\PDO::FETCH_ASSOC);
    }

    /** Revoke a specific session. */
    public static function revoke(int $userId, int $sessionId): void
    {
        $pdo = Database::connection();
        $stmt = $pdo->prepare(
            'UPDATE user_sessions SET is_active = 0 WHERE id = ? AND user_id = ?'
        );
        $stmt->execute([$sessionId, $userId]);
        SecurityLogger::info($userId, 'session.revoked', ['session_id' => $sessionId]);
    }

    /** Revoke all sessions for a user (force logout everywhere). */
    public static function revokeAll(int $userId, ?int $exceptSessionId = null): int
    {
        $pdo = Database::connection();
        if ($exceptSessionId) {
            $stmt = $pdo->prepare(
                'UPDATE user_sessions SET is_active = 0
                 WHERE user_id = ? AND is_active = 1 AND id != ?'
            );
            $stmt->execute([$userId, $exceptSessionId]);
        } else {
            $stmt = $pdo->prepare(
                'UPDATE user_sessions SET is_active = 0 WHERE user_id = ? AND is_active = 1'
            );
            $stmt->execute([$userId]);
        }

        $count = $stmt->rowCount();
        if ($count > 0) {
            SecurityLogger::warning($userId, 'session.revoke_all', ['count' => $count]);
        }
        return $count;
    }

    /** Count active sessions. */
    public static function countActive(int $userId): int
    {
        $pdo = Database::connection();
        $stmt = $pdo->prepare(
            'SELECT COUNT(*) FROM user_sessions WHERE user_id = ? AND is_active = 1 AND expires_at > NOW()'
        );
        $stmt->execute([$userId]);
        return (int) $stmt->fetchColumn();
    }

    /** Cleanup expired sessions. */
    public static function cleanup(): int
    {
        $pdo = Database::connection();
        $stmt = $pdo->prepare(
            'DELETE FROM user_sessions WHERE is_active = 0 OR expires_at < NOW()'
        );
        $stmt->execute();
        return $stmt->rowCount();
    }

    /** Extend session lifetime. */
    public static function extend(int $sessionId, int $additionalSeconds = 3600): void
    {
        $pdo = Database::connection();
        $pdo->prepare(
            'UPDATE user_sessions
             SET expires_at = DATE_ADD(expires_at, INTERVAL ? SECOND)
             WHERE id = ? AND is_active = 1'
        )->execute([$additionalSeconds, $sessionId]);
    }
}

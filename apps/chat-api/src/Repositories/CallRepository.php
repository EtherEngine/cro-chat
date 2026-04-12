<?php

declare(strict_types=1);

namespace App\Repositories;

use App\Support\Database;

final class CallRepository
{
    // ── Call CRUD ──────────────────────────────────

    /**
     * Create a new call record (status = initiated).
     * Also creates two call_sessions rows (caller + callee).
     */
    public static function create(int $conversationId, int $callerUserId, int $calleeUserId): array
    {
        $db = Database::connection();

        $stmt = $db->prepare(
            'INSERT INTO calls (conversation_id, caller_user_id, callee_user_id, status)
             VALUES (?, ?, ?, ?)'
        );
        $stmt->execute([$conversationId, $callerUserId, $calleeUserId, 'initiated']);
        $callId = (int) $db->lastInsertId();

        // Create per-participant session rows
        $sessStmt = $db->prepare(
            'INSERT INTO call_sessions (call_id, user_id, role) VALUES (?, ?, ?)'
        );
        $sessStmt->execute([$callId, $callerUserId, 'caller']);
        $sessStmt->execute([$callId, $calleeUserId, 'callee']);

        return self::find($callId);
    }

    public static function find(int $id): ?array
    {
        $stmt = Database::connection()->prepare(
            'SELECT id, conversation_id, caller_user_id, callee_user_id, status,
                    started_at, answered_at, ended_at, duration_seconds, end_reason, created_at
             FROM calls WHERE id = ?'
        );
        $stmt->execute([$id]);
        $row = $stmt->fetch();
        return $row ?: null;
    }

    /**
     * Transition call to a new status.  Returns updated row.
     */
    public static function updateStatus(int $callId, string $status, ?string $endReason = null): ?array
    {
        $sets = ['status = ?'];
        $params = [$status];

        if ($status === 'ringing') {
            // no extra columns
        }

        if ($status === 'accepted') {
            $sets[] = 'answered_at = NOW()';
        }

        if (in_array($status, ['ended', 'rejected', 'missed', 'failed'], true)) {
            $sets[] = 'ended_at = NOW()';
            $sets[] = 'duration_seconds = IF(answered_at IS NOT NULL, TIMESTAMPDIFF(SECOND, answered_at, NOW()), NULL)';
        }

        if ($endReason !== null) {
            $sets[] = 'end_reason = ?';
            $params[] = $endReason;
        }

        $params[] = $callId;
        $sql = 'UPDATE calls SET ' . implode(', ', $sets) . ' WHERE id = ?';
        Database::connection()->prepare($sql)->execute($params);

        return self::find($callId);
    }

    /**
     * Find an active call (initiated, ringing, or accepted) for a conversation.
     * Prevents concurrent calls on the same conversation.
     *
     * @param bool $forUpdate  If true, acquires a row-level exclusive lock
     *                         (SELECT … FOR UPDATE) to prevent race conditions.
     *                         Must be called inside a transaction.
     */
    public static function findActiveForConversation(int $conversationId, bool $forUpdate = false): ?array
    {
        $sql = "SELECT id, conversation_id, caller_user_id, callee_user_id, status,
                       started_at, answered_at, ended_at, duration_seconds, end_reason, created_at
                FROM calls
                WHERE conversation_id = ? AND status IN ('initiated', 'ringing', 'accepted')
                ORDER BY created_at DESC LIMIT 1";
        if ($forUpdate) {
            $sql .= ' FOR UPDATE';
        }
        $stmt = Database::connection()->prepare($sql);
        $stmt->execute([$conversationId]);
        $row = $stmt->fetch();
        return $row ?: null;
    }

    /**
     * Check if a user is currently in an active call (any conversation).
     * Used to prevent a user from being in two calls simultaneously.
     *
     * @param bool $forUpdate  Acquire row-level lock (must be inside a transaction).
     */
    public static function findActiveForUser(int $userId, bool $forUpdate = false): ?array
    {
        $sql = "SELECT id, conversation_id, caller_user_id, callee_user_id, status,
                       started_at, answered_at, ended_at, duration_seconds, end_reason, created_at
                FROM calls
                WHERE (caller_user_id = ? OR callee_user_id = ?)
                  AND status IN ('initiated', 'ringing', 'accepted')
                ORDER BY created_at DESC LIMIT 1";
        if ($forUpdate) {
            $sql .= ' FOR UPDATE';
        }
        $stmt = Database::connection()->prepare($sql);
        $stmt->execute([$userId, $userId]);
        $row = $stmt->fetch();
        return $row ?: null;
    }

    /**
     * Expire stale ringing calls that exceeded the timeout.
     * Returns the number of calls transitioned to "missed".
     *
     * @param int $timeoutSeconds  Seconds after which a ringing call is considered missed.
     */
    public static function expireStaleRingingCalls(int $timeoutSeconds = 45): array
    {
        $stmt = Database::connection()->prepare(
            "SELECT id FROM calls
             WHERE status = 'ringing'
               AND started_at < DATE_SUB(NOW(), INTERVAL ? SECOND)"
        );
        $stmt->execute([$timeoutSeconds]);
        $rows = $stmt->fetchAll();
        return array_map(fn(array $r) => (int) $r['id'], $rows);
    }

    /**
     * Call history for a conversation, newest first.
     */
    public static function historyForConversation(int $conversationId, int $limit = 50, int $offset = 0): array
    {
        $stmt = Database::connection()->prepare(
            'SELECT c.id, c.conversation_id, c.caller_user_id, c.callee_user_id, c.status,
                    c.started_at, c.answered_at, c.ended_at, c.duration_seconds, c.end_reason, c.created_at,
                    u1.display_name AS caller_name, u1.avatar_color AS caller_avatar_color,
                    u2.display_name AS callee_name, u2.avatar_color AS callee_avatar_color
             FROM calls c
             JOIN users u1 ON u1.id = c.caller_user_id
             JOIN users u2 ON u2.id = c.callee_user_id
             WHERE c.conversation_id = ?
               AND c.status IN (\'ended\', \'rejected\', \'missed\', \'failed\')
             ORDER BY c.created_at DESC
             LIMIT ? OFFSET ?'
        );
        $stmt->execute([$conversationId, $limit, $offset]);
        $rows = $stmt->fetchAll();

        return array_map([self::class, 'formatHistoryRow'], $rows);
    }

    private static function formatHistoryRow(array $row): array
    {
        return [
            'id' => (int) $row['id'],
            'conversation_id' => (int) $row['conversation_id'],
            'caller_user_id' => (int) $row['caller_user_id'],
            'callee_user_id' => (int) $row['callee_user_id'],
            'status' => $row['status'],
            'started_at' => $row['started_at'],
            'answered_at' => $row['answered_at'],
            'ended_at' => $row['ended_at'],
            'duration_seconds' => $row['duration_seconds'] !== null ? (int) $row['duration_seconds'] : null,
            'end_reason' => $row['end_reason'],
            'created_at' => $row['created_at'],
            'caller' => [
                'id' => (int) $row['caller_user_id'],
                'display_name' => $row['caller_name'],
                'avatar_color' => $row['caller_avatar_color'],
            ],
            'callee' => [
                'id' => (int) $row['callee_user_id'],
                'display_name' => $row['callee_name'],
                'avatar_color' => $row['callee_avatar_color'],
            ],
        ];
    }

    // ── Call Sessions ─────────────────────────────

    /**
     * Get both session rows for a call.
     */
    public static function sessions(int $callId): array
    {
        $stmt = Database::connection()->prepare(
            'SELECT id, call_id, user_id, role, joined_at, left_at, muted, ice_state, created_at
             FROM call_sessions WHERE call_id = ? ORDER BY role ASC'
        );
        $stmt->execute([$callId]);
        $rows = $stmt->fetchAll();

        return array_map(function (array $r): array {
            $r['muted'] = (bool) $r['muted'];
            return $r;
        }, $rows);
    }

    /**
     * Mark a participant as joined (WebRTC peer connected).
     */
    public static function markSessionJoined(int $callId, int $userId): void
    {
        Database::connection()->prepare(
            'UPDATE call_sessions SET joined_at = NOW() WHERE call_id = ? AND user_id = ?'
        )->execute([$callId, $userId]);
    }

    /**
     * Mark a participant as left (WebRTC peer disconnected / hung up).
     */
    public static function markSessionLeft(int $callId, int $userId): void
    {
        Database::connection()->prepare(
            'UPDATE call_sessions SET left_at = NOW() WHERE call_id = ? AND user_id = ?'
        )->execute([$callId, $userId]);
    }

    /**
     * Update mute state for a participant.
     */
    public static function updateMuteState(int $callId, int $userId, bool $muted): void
    {
        Database::connection()->prepare(
            'UPDATE call_sessions SET muted = ? WHERE call_id = ? AND user_id = ?'
        )->execute([(int) $muted, $callId, $userId]);
    }

    /**
     * Update ICE connection state for a participant.
     */
    public static function updateIceState(int $callId, int $userId, string $iceState): void
    {
        Database::connection()->prepare(
            'UPDATE call_sessions SET ice_state = ? WHERE call_id = ? AND user_id = ?'
        )->execute([$iceState, $callId, $userId]);
    }

    // ── ICE Candidates ────────────────────────────

    /** Max ICE candidates stored per call (prevents DB flooding). */
    private const MAX_ICE_CANDIDATES_PER_CALL = 100;

    public static function storeIceCandidate(int $callId, int $senderId, array $candidate): void
    {
        $db = Database::connection();

        // Guard: limit total stored candidates per call
        $stmt = $db->prepare('SELECT COUNT(*) FROM call_ice_candidates WHERE call_id = ?');
        $stmt->execute([$callId]);
        if ((int) $stmt->fetchColumn() >= self::MAX_ICE_CANDIDATES_PER_CALL) {
            return; // silently drop — ICE gathering produces many candidates, excess is harmless
        }

        $db->prepare(
            'INSERT INTO call_ice_candidates (call_id, sender_id, candidate) VALUES (?, ?, ?)'
        )->execute([$callId, $senderId, json_encode($candidate, JSON_UNESCAPED_UNICODE)]);
    }

    public static function pendingIceCandidates(int $callId, int $receiverId): array
    {
        $stmt = Database::connection()->prepare(
            'SELECT id, call_id, sender_id, candidate, created_at
             FROM call_ice_candidates
             WHERE call_id = ? AND sender_id != ?
             ORDER BY created_at ASC'
        );
        $stmt->execute([$callId, $receiverId]);
        $rows = $stmt->fetchAll();

        return array_map(function (array $row): array {
            $row['candidate'] = json_decode($row['candidate'], true);
            return $row;
        }, $rows);
    }

    public static function purgeStaleIceCandidates(): int
    {
        $stmt = Database::connection()->prepare(
            'DELETE FROM call_ice_candidates WHERE created_at < DATE_SUB(NOW(), INTERVAL 60 SECOND)'
        );
        $stmt->execute();
        return $stmt->rowCount();
    }
}

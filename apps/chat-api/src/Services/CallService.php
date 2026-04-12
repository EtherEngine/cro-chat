<?php

declare(strict_types=1);

namespace App\Services;

use App\Exceptions\ApiException;
use App\Repositories\CallRepository;
use App\Repositories\ConversationRepository;
use App\Repositories\EventRepository;
use App\Repositories\MessageRepository;
use App\Repositories\UserRepository;
use App\Services\AbuseDetection;
use App\Services\AnalyticsService;
use App\Services\NotificationService;
use App\Support\Database;
use App\Support\SecurityLogger;

final class CallService
{
    /** Terminal statuses – no further transitions allowed. */
    private const TERMINAL_STATUSES = ['rejected', 'missed', 'ended', 'failed'];

    /**
     * Allowed status transitions.
     *
     *   initiated → ringing  | failed
     *   ringing   → accepted | rejected | missed | failed
     *   accepted  → ended    | failed
     */
    private const TRANSITIONS = [
        'initiated' => ['ringing', 'failed'],
        'ringing' => ['accepted', 'rejected', 'missed', 'failed'],
        'accepted' => ['ended', 'failed'],
    ];

    /** Ringing timeout in seconds. After this, unanswered calls become "missed". */
    public const RINGING_TIMEOUT_SECONDS = 45;

    // ── Public API ──────────────────────────────────

    /**
     * Initiate a 1:1 audio call.
     *
     * Validates (outside transaction – fast-fail):
     *  1. Conversation exists
     *  2. Conversation is a 1:1 DM (is_group = false)
     *  3. Conversation has exactly 2 members
     *  4. Caller is a member
     *
     * Inside transaction (with row-level locking – race-safe):
     *  5. No active call on this conversation  (SELECT … FOR UPDATE)
     *  6. Caller is not already in another active call
     *  7. Callee is not already in another active call ("busy")
     *  8. Create call + sessions, transition to ringing, publish event
     */
    public static function initiate(int $conversationId, int $callerId): array
    {
        // ── Abuse gate: blocked users cannot initiate calls ──────
        if (AbuseDetection::isBlocked('user', (string) $callerId)) {
            throw ApiException::forbidden(
                'Dein Account ist vorübergehend gesperrt',
                'USER_BLOCKED'
            );
        }

        // ── Fast-fail validation (no lock needed) ────────────────
        $conv = ConversationRepository::find($conversationId);
        if (!$conv) {
            throw ApiException::notFound('Gespräch nicht gefunden', 'CONVERSATION_NOT_FOUND');
        }

        if ((bool) $conv['is_group']) {
            throw ApiException::validation(
                'Audio-Anrufe sind nur in 1:1-Gesprächen möglich',
                'CALL_GROUP_NOT_SUPPORTED'
            );
        }

        if (!ConversationRepository::isMember($conversationId, $callerId)) {
            throw ApiException::forbidden('Kein Zugriff auf dieses Gespräch', 'CONVERSATION_ACCESS_DENIED');
        }

        // Verify exactly 2 members (paranoid guard against data inconsistency)
        $members = ConversationRepository::members($conversationId);
        if (count($members) !== 2) {
            throw ApiException::validation(
                'Audio-Anrufe erfordern exakt 2 Teilnehmer',
                'CALL_INVALID_MEMBER_COUNT'
            );
        }

        // Determine callee
        $callee = null;
        foreach ($members as $m) {
            if ((int) $m['id'] !== $callerId) {
                $callee = $m;
                break;
            }
        }
        if (!$callee) {
            throw ApiException::validation('Kein Gesprächspartner gefunden', 'CALLEE_NOT_FOUND');
        }

        $calleeUserId = (int) $callee['id'];

        // ── Atomic creation with row-level locking ───────────────
        $call = Database::transaction(function () use ($conversationId, $callerId, $calleeUserId) {
            $db = Database::connection();

            // Lock: no concurrent active call on this conversation
            $active = CallRepository::findActiveForConversation($conversationId, forUpdate: true);
            if ($active) {
                throw ApiException::conflict(
                    'Es läuft bereits ein Anruf in diesem Gespräch',
                    'CALL_ALREADY_ACTIVE',
                    ['active_call' => $active]
                );
            }

            // Deadlock prevention: acquire per-user busy-check locks in ascending
            // user_id order.  Without this, T1(Alice→Bob) locks Alice then waits
            // for Bob while T2(Bob→Alice) holds Bob and waits for Alice — classic
            // deadlock.  Consistent lock ordering eliminates the cycle.
            $lowerUid = min($callerId, $calleeUserId);
            $higherUid = max($callerId, $calleeUserId);
            $lowerBusy = CallRepository::findActiveForUser($lowerUid, forUpdate: true);
            $higherBusy = CallRepository::findActiveForUser($higherUid, forUpdate: true);

            $callerBusy = ($lowerUid === $callerId) ? $lowerBusy : $higherBusy;
            $calleeBusy = ($lowerUid === $callerId) ? $higherBusy : $lowerBusy;

            if ($callerBusy) {
                throw ApiException::conflict(
                    'Du befindest dich bereits in einem anderen Anruf',
                    'CALLER_BUSY'
                );
            }

            if ($calleeBusy) {
                throw ApiException::conflict(
                    'Der Gesprächspartner befindet sich bereits in einem Anruf',
                    'CALLEE_BUSY'
                );
            }

            // DND check inside the transaction (TOCTOU guard) — re-read callee
            // status under the existing row locks so an in-flight DND toggle is
            // either fully committed before we read or fully after we insert.
            $dndStmt = $db->prepare('SELECT status FROM users WHERE id = ?');
            $dndStmt->execute([$calleeUserId]);
            $calleeStatus = $dndStmt->fetchColumn();
            if ($calleeStatus === 'dnd') {
                throw ApiException::conflict(
                    'Der Gesprächspartner möchte nicht gestört werden',
                    'CALLEE_DND'
                );
            }

            // 1. Create call record (status=initiated) + call_sessions rows
            $call = CallRepository::create($conversationId, $callerId, $calleeUserId);
            $callId = (int) $call['id'];

            // 2. Transition to ringing
            $call = CallRepository::updateStatus($callId, 'ringing');

            // 3. Publish real-time event so callee sees the incoming call.
            // Published to user:{calleeUserId} — always subscribed regardless of which
            // conversation is currently open in the UI.
            EventRepository::publish('call.ringing', "user:$calleeUserId", [
                'call_id' => $callId,
                'conversation_id' => $conversationId,
                'caller_user_id' => $callerId,
                'callee_user_id' => $calleeUserId,
            ]);

            // 4. Update presence: caller → in_call, callee → ringing
            UserRepository::setCallPresence($callerId, 'in_call');
            UserRepository::setCallPresence($calleeUserId, 'ringing');
            self::publishPresenceChange($callerId, 'in_call');
            self::publishPresenceChange($calleeUserId, 'ringing');

            // 5. Notify callee (in-app + push) — latency-critical, always sync
            NotificationService::notifyIncomingCall($calleeUserId, $callerId, $conversationId, $callId);

            return $call;
        });

        // ── Observability: structured log + analytics event ──
        self::logCallEvent('call.initiated', $call, $callerId, [
            'callee_user_id' => $calleeUserId,
        ]);
        self::trackCallAnalytics('call.initiated', $call, $callerId);

        return $call;
    }

    /**
     * Accept a ringing call (callee only).
     * Uses FOR UPDATE lock to prevent double-accept race.
     */
    public static function accept(int $callId, int $userId): array
    {
        // Fast-fail outside transaction
        $call = self::requireCall($callId);
        self::requireParticipant($call, $userId);

        if ((int) $call['callee_user_id'] !== $userId) {
            throw ApiException::forbidden('Nur der Angerufene kann den Anruf annehmen', 'CALL_NOT_CALLEE');
        }

        $updated = Database::transaction(function () use ($callId, $userId) {
            // Re-read with lock to prevent concurrent accept/reject race
            $call = self::requireCallForUpdate($callId);
            self::requireTransition($call, 'accepted');

            // Re-verify conversation membership inside transaction (TOCTOU guard)
            if (!ConversationRepository::isMember((int) $call['conversation_id'], $userId)) {
                throw ApiException::forbidden('Kein Zugriff auf dieses Gespräch', 'CONVERSATION_ACCESS_DENIED');
            }

            $updated = CallRepository::updateStatus($callId, 'accepted');
            CallRepository::markSessionJoined($callId, (int) $call['caller_user_id']);
            CallRepository::markSessionJoined($callId, $userId);

            // Both participants are now in the call
            UserRepository::setCallPresence((int) $call['caller_user_id'], 'in_call');
            UserRepository::setCallPresence($userId, 'in_call');
            self::publishPresenceChange((int) $call['caller_user_id'], 'in_call');
            self::publishPresenceChange($userId, 'in_call');

            $convId = (int) $call['conversation_id'];
            $callerId2 = (int) $call['caller_user_id'];
            // Notify both participants (caller learns callee accepted)
            EventRepository::publish('call.accepted', "user:$callerId2", [
                'call_id' => $callId,
                'conversation_id' => $convId,
                'callee_user_id' => $userId,
            ]);
            EventRepository::publish('call.accepted', "user:$userId", [
                'call_id' => $callId,
                'conversation_id' => $convId,
                'callee_user_id' => $userId,
            ]);

            return $updated;
        });

        // ── Observability: structured log + analytics event ──
        self::logCallEvent('call.accepted', $updated, $userId);
        self::trackCallAnalytics('call.accepted', $updated, $userId);

        return $updated;
    }

    /**
     * Reject a ringing call (callee only).
     * Uses FOR UPDATE lock to prevent double-reject / accept-reject race.
     */
    public static function reject(int $callId, int $userId): array
    {
        $call = self::requireCall($callId);
        self::requireParticipant($call, $userId);

        if ((int) $call['callee_user_id'] !== $userId) {
            throw ApiException::forbidden('Nur der Angerufene kann den Anruf ablehnen', 'CALL_NOT_CALLEE');
        }

        return self::terminateCallLocked($callId, 'rejected', 'rejected');
    }

    /**
     * Cancel a ringing call (caller only).
     * Stored as "missed" with end_reason "caller_cancelled".
     * Uses FOR UPDATE lock to prevent cancel/accept race.
     */
    public static function cancel(int $callId, int $userId): array
    {
        $call = self::requireCall($callId);
        self::requireParticipant($call, $userId);

        if ((int) $call['caller_user_id'] !== $userId) {
            throw ApiException::forbidden('Nur der Anrufer kann den Anruf abbrechen', 'CALL_NOT_CALLER');
        }

        return self::terminateCallLocked($callId, 'missed', 'caller_cancelled');
    }

    /**
     * End an active (accepted) call.  Either party may hang up.
     * Uses FOR UPDATE lock to prevent double-hangup race.
     */
    public static function hangup(int $callId, int $userId): array
    {
        $call = self::requireCall($callId);
        self::requireParticipant($call, $userId);

        return self::terminateCallLocked($callId, 'ended', 'hangup');
    }

    /**
     * Mark a ringing call as missed (timeout).  Called by a server-side worker.
     * Uses FOR UPDATE to prevent race with a concurrent accept.
     */
    public static function markMissed(int $callId): array
    {
        return self::terminateCallLocked($callId, 'missed', 'timeout');
    }

    /**
     * Mark a call as failed (technical error).  Either party or worker.
     */
    public static function markFailed(int $callId, string $reason = 'network_error'): array
    {
        return self::terminateCallLocked($callId, 'failed', $reason);
    }

    /**
     * Expire all ringing calls that exceeded the timeout.
     * Meant to be called from a periodic worker / cron.
     *
     * @return int  Number of calls marked as missed.
     */
    public static function reapStaleCalls(): int
    {
        $staleIds = CallRepository::expireStaleRingingCalls(self::RINGING_TIMEOUT_SECONDS);
        $count = 0;
        foreach ($staleIds as $callId) {
            try {
                self::markMissed($callId);
                $count++;
            } catch (\Throwable) {
                // Call may have transitioned concurrently — skip silently
            }
        }
        return $count;
    }

    // ── Read-only ────────────────────────────────────

    /**
     * Get call details (participant only).
     */
    public static function show(int $callId, int $userId): array
    {
        $call = self::requireCall($callId);
        self::requireParticipant($call, $userId);
        $call['sessions'] = CallRepository::sessions($callId);
        return $call;
    }

    /**
     * Get the active call for a direct conversation, or null if none.
     * "Active" means status ∈ {initiated, ringing, accepted}.
     */
    public static function active(int $conversationId, int $userId): ?array
    {
        ConversationService::requireMember($conversationId, $userId);

        $call = CallRepository::findActiveForConversation($conversationId);
        if (!$call) {
            return null;
        }

        $call['sessions'] = CallRepository::sessions((int) $call['id']);
        return $call;
    }

    /**
     * Call history for a conversation (member only).
     */
    public static function history(int $conversationId, int $userId, int $limit = 50, int $offset = 0): array
    {
        ConversationService::requireMember($conversationId, $userId);
        return CallRepository::historyForConversation($conversationId, $limit, $offset);
    }

    /**
     * Build the ICE server list for the calling user.
     *
     * STUN servers are returned as-is (public, no credentials).
     * TURN credentials are generated per-user with HMAC-SHA1 and
     * expire after the configured TTL (RFC draft: TURN REST API).
     *
     * Security:
     *  - User must be in an active call to receive TURN credentials.
     *  - The shared secret (TURN_SECRET) never leaves the backend.
     *  - Credentials are short-lived (default 1 h).
     *
     * @return array{ice_servers: list<array>, ice_transport_policy: string}
     */
    public static function iceServers(int $userId): array
    {
        // Only issue TURN credentials if the user is actually in a call.
        // STUN is always safe to return (public servers, no credentials).
        $activeCall = CallRepository::findActiveForUser($userId);

        $config = require __DIR__ . '/../Config/webrtc.php';

        $servers = [];

        // ── STUN ──
        foreach ($config['stun'] as $url) {
            $servers[] = ['urls' => $url];
        }

        // ── TURN (only when in an active call + configured) ──
        $turnUrl = $config['turn']['url'] ?? '';
        $turnSecret = $config['turn']['secret'] ?? '';

        if ($turnUrl !== '' && $turnSecret !== '' && $activeCall !== null) {
            $ttl = $config['turn']['credential_ttl'] ?? 3600;
            $transport = $config['turn']['transport'] ?? 'udp';
            $expiry = time() + $ttl;

            // Username format: <expiry>:<userId> (coturn --use-auth-secret)
            $username = $expiry . ':' . $userId;
            $credential = base64_encode(hash_hmac('sha1', $username, $turnSecret, true));

            // Primary: UDP relay
            $servers[] = [
                'urls' => $turnUrl . '?transport=' . $transport,
                'username' => $username,
                'credential' => $credential,
            ];

            // Fallback: TCP relay (for restrictive firewalls)
            if ($transport === 'udp') {
                $servers[] = [
                    'urls' => $turnUrl . '?transport=tcp',
                    'username' => $username,
                    'credential' => $credential,
                ];
            }
        }

        return [
            'ice_servers' => $servers,
            'ice_transport_policy' => $config['ice_transport_policy'] ?? 'all',
        ];
    }

    // ── Internal helpers ─────────────────────────────

    private static function requireCall(int $callId): array
    {
        $call = CallRepository::find($callId);
        if (!$call) {
            throw ApiException::notFound('Anruf nicht gefunden', 'CALL_NOT_FOUND');
        }
        return $call;
    }

    /**
     * Re-read a call row with an exclusive lock.  Must be inside a transaction.
     */
    private static function requireCallForUpdate(int $callId): array
    {
        $db = Database::connection();
        $stmt = $db->prepare(
            'SELECT id, conversation_id, caller_user_id, callee_user_id, status,
                    started_at, answered_at, ended_at, duration_seconds, end_reason, created_at
             FROM calls WHERE id = ? FOR UPDATE'
        );
        $stmt->execute([$callId]);
        $call = $stmt->fetch();
        if (!$call) {
            throw ApiException::notFound('Anruf nicht gefunden', 'CALL_NOT_FOUND');
        }
        return $call;
    }

    private static function requireParticipant(array $call, int $userId): void
    {
        if ((int) $call['caller_user_id'] !== $userId && (int) $call['callee_user_id'] !== $userId) {
            SecurityLogger::warning($userId, 'call.access_denied', [
                'call_id' => (int) $call['id'],
                'conversation_id' => (int) $call['conversation_id'],
            ]);
            AbuseDetection::recordViolation('user', (string) $userId, 'forbidden_access');
            throw ApiException::forbidden('Kein Zugriff auf diesen Anruf', 'CALL_ACCESS_DENIED');
        }
    }

    private static function requireTransition(array $call, string $targetStatus): void
    {
        $current = $call['status'];
        $allowed = self::TRANSITIONS[$current] ?? [];
        if (!in_array($targetStatus, $allowed, true)) {
            throw ApiException::conflict(
                "Ungültiger Statusübergang: $current → $targetStatus",
                'CALL_INVALID_TRANSITION'
            );
        }
    }

    /**
     * Locked terminal-state transition.
     *
     * Acquires an exclusive row lock on the call, re-validates the transition,
     * updates status, marks both sessions as left, and publishes the hangup event.
     * This prevents every known race condition (double-hangup, accept-while-cancel, etc.).
     *
     * Idempotent: if the call is already in a terminal state, it is returned
     * as-is without throwing.  This allows safe retries from both parties and
     * from the beforeunload cleanup path.
     */
    private static function terminateCallLocked(int $callId, string $status, string $reason): array
    {
        $transitioned = false;

        $result = Database::transaction(function () use ($callId, $status, $reason, &$transitioned) {
            $call = self::requireCallForUpdate($callId);

            // Idempotent: already in a terminal state → return silently.
            if (in_array($call['status'], self::TERMINAL_STATUSES, true)) {
                return $call;
            }

            self::requireTransition($call, $status);
            $transitioned = true;

            $updated = CallRepository::updateStatus($callId, $status, $reason);

            CallRepository::markSessionLeft($callId, (int) $call['caller_user_id']);
            CallRepository::markSessionLeft($callId, (int) $call['callee_user_id']);

            // Restore presence: call states → online
            $callerId = (int) $call['caller_user_id'];
            $calleeId = (int) $call['callee_user_id'];
            UserRepository::clearCallPresence($callerId);
            UserRepository::clearCallPresence($calleeId);
            self::publishPresenceChange($callerId, 'online');
            self::publishPresenceChange($calleeId, 'online');

            $convId = (int) $call['conversation_id'];

            // Publish a specific event per terminal status
            $eventType = match ($status) {
                'rejected' => 'call.rejected',
                'failed' => 'call.failed',
                default => 'call.ended',   // ended, missed
            };

            // Notify both participants via their personal user rooms (always subscribed)
            $payload = [
                'call_id' => $callId,
                'conversation_id' => $convId,
                'caller_user_id' => $callerId,
                'callee_user_id' => $calleeId,
                'status' => $status,
                'reason' => $reason,
            ];
            EventRepository::publish($eventType, "user:$callerId", $payload);
            EventRepository::publish($eventType, "user:$calleeId", $payload);

            // ── Persist call-history message in conversation timeline ──
            self::createCallHistoryMessage($updated, $convId);

            // ── Dispatch call notifications (no self-notification) ──
            if ($status === 'missed') {
                NotificationService::notifyMissedCall(
                    $calleeId,
                    $callerId,
                    $convId,
                    $callId,
                    $reason
                );
            } elseif ($status === 'rejected') {
                NotificationService::notifyCallRejected(
                    $callerId,
                    $calleeId,
                    $convId,
                    $callId
                );
            }

            return $updated;
        });

        // ── Observability: structured log + analytics event ──
        // Skip if this was an idempotent no-op (call was already in a terminal
        // state before this call — the transition did not actually happen).
        if (!$transitioned) {
            return $result;
        }

        // Map terminal status to the matching analytics event type
        $analyticsEvent = match ($result['status'] ?? $status) {
            'rejected' => 'call.rejected',
            'missed' => 'call.missed',
            'failed' => 'call.failed',
            'ended' => 'call.ended',
            default => 'call.ended',
        };
        $actorId = (int) ($result['caller_user_id'] ?? 0);
        self::logCallEvent($analyticsEvent, $result, $actorId, [
            'end_reason' => $reason,
        ]);
        self::trackCallAnalytics($analyticsEvent, $result, $actorId);

        return $result;
    }

    /**
     * Create a structured call-history message in the conversation timeline.
     *
     * The message user_id is set to the caller so the avatar/name attribution
     * makes sense ("Heather hat angerufen").  The body stores JSON metadata
     * for frontend rendering; it is never shown as raw text.
     *
     * Idempotent — MessageRepository::createCallMessage guards against duplicates.
     */
    private static function createCallHistoryMessage(array $call, int $conversationId): void
    {
        $callId = (int) $call['id'];
        $callerId = (int) $call['caller_user_id'];
        $calleeId = (int) $call['callee_user_id'];

        $meta = [
            'call_id' => $callId,
            'status' => $call['status'],
            'end_reason' => $call['end_reason'] ?? null,
            'duration_seconds' => isset($call['duration_seconds']) ? (int) $call['duration_seconds'] : null,
            'caller_user_id' => $callerId,
            'callee_user_id' => $calleeId,
            'started_at' => $call['started_at'] ?? null,
            'answered_at' => $call['answered_at'] ?? null,
            'ended_at' => $call['ended_at'] ?? null,
        ];

        $msg = MessageRepository::createCallMessage($callerId, $conversationId, $callId, $meta);

        // Publish as a real-time message so the conversation updates live
        EventRepository::publish('message.created', "conversation:$conversationId", $msg);
    }

    /**
     * Broadcast a presence change so co-members can update their UI
     * immediately without waiting for the next poll cycle.
     *
     * Publishes to a global 'presence' pseudo-room that the realtime
     * server will broadcast to all clients of users in the same spaces.
     */
    private static function publishPresenceChange(int $userId, string $status): void
    {
        $stmt = Database::connection()->prepare(
            'SELECT space_id FROM space_members WHERE user_id = ?'
        );
        $stmt->execute([$userId]);
        $rows = $stmt->fetchAll();

        // Publish once per space — the poller broadcasts to all room subscribers
        foreach ($rows as $row) {
            $spaceId = (int) $row['space_id'];
            EventRepository::publish('presence.changed', "space:$spaceId", [
                'user_id' => $userId,
                'status' => $status,
            ]);
        }
    }

    // ── Observability helpers ────────────────────────

    /**
     * Structured audit log for call lifecycle events.
     */
    private static function logCallEvent(string $event, array $call, int $actorId, array $extra = []): void
    {
        SecurityLogger::info($actorId, $event, array_merge([
            'call_id' => (int) $call['id'],
            'conversation_id' => (int) $call['conversation_id'],
            'status' => $call['status'],
            'duration_seconds' => $call['duration_seconds'] ?? null,
            'end_reason' => $call['end_reason'] ?? null,
        ], $extra));
    }

    /**
     * Fire a product analytics event for call activity.
     * Silently swallows errors to never break the call flow.
     */
    private static function trackCallAnalytics(string $eventType, array $call, int $actorId): void
    {
        try {
            $conv = ConversationRepository::find((int) $call['conversation_id']);
            $spaceId = $conv ? (int) $conv['space_id'] : 0;
            if ($spaceId === 0) {
                return;
            }
            AnalyticsService::trackProduct($spaceId, $actorId, $eventType, null, [
                'call_id' => (int) $call['id'],
                'duration_seconds' => $call['duration_seconds'] ?? null,
                'end_reason' => $call['end_reason'] ?? null,
            ]);
        } catch (\Throwable) {
            // Analytics must never break call flow
        }
    }
}

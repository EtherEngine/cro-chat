<?php

declare(strict_types=1);

namespace App\Services;

use App\Exceptions\ApiException;
use App\Repositories\CallRepository;
use App\Repositories\UserRepository;
use App\Support\Database;

/**
 * Dev-only service for simulating audio call scenarios without a real browser peer.
 *
 * Creates genuine call records via the production CallService so that all
 * side-effects (DB rows, domain events, presence changes, push notifications)
 * fire exactly as in production.  The simulation artefact is purely that the
 * "bot" caller has no real WebRTC peer — it is a human-controlled stub.
 *
 * ⚠️  Only instantiated/called from DevCallController, which enforces APP_ENV=local.
 *     Never loaded in production.
 */
final class DevCallService
{
    /**
     * E-mail of the dev bot account.  Must exist in the local database
     * (created by seed.sql).  This is the user that appears as "caller"
     * in every simulated incoming call.
     */
    public const BOT_EMAIL = 'heather.mason@cro.dev';

    // ── Scenario catalog ─────────────────────────────────────

    /**
     * Returns the list of all available simulation scenarios shown in the
     * CallSimulatorPanel.
     */
    public static function scenarios(): array
    {
        return [
            [
                'id' => 'ring_only',
                'label' => 'Nur klingeln',
                'description' => 'Bot ruft an. Klingelt bis du annimmst / ablehnst oder den Bot‑Stop drückst.',
                'bot_actions' => ['cancel'],
            ],
            [
                'id' => 'ring_then_cancel',
                'label' => 'Klingeln → Bot bricht ab',
                'description' => 'Bot ruft an. Klick "Bot: Abbrechen" → verpasster Anruf.',
                'bot_actions' => ['cancel'],
            ],
            [
                'id' => 'ring_then_hangup',
                'label' => 'Anrufen → Bot legt auf',
                'description' => 'Nimm an und klick dann "Bot: Auflegen" → Anruf beendet.',
                'bot_actions' => ['hangup'],
            ],
        ];
    }

    // ── Simulate incoming call ────────────────────────────────

    /**
     * Simulate an incoming call: the dev bot dials the target user.
     *
     * Uses CallService::initiate() directly, producing:
     *   • Real `calls` DB row (status=ringing)
     *   • Real `call.ringing` domain event → WebSocket delivery
     *   • Real presence changes (bot=in_call, target=ringing)
     *   • Real push notification to target
     *
     * @param int    $targetUserId  The user who should see the incoming ring.
     * @param string $scenario      Scenario ID from scenarios().
     *
     * @throws ApiException  BOT_NOT_FOUND | DEV_BOT_SELF_CALL | DEV_NO_SHARED_SPACE
     */
    public static function simulateIncomingCall(int $targetUserId, string $scenario): array
    {
        $bot = self::requireBotUser();
        $botUserId = (int) $bot['id'];

        if ($botUserId === $targetUserId) {
            throw ApiException::validation(
                'Bot-Nutzer und Zielnutzer dürfen nicht identisch sein.',
                'DEV_BOT_SELF_CALL'
            );
        }

        $spaceId = self::requireSharedSpace($botUserId, $targetUserId);

        // Act as the bot user for the service call
        $prevSession = $_SESSION['user_id'] ?? null;
        $_SESSION['user_id'] = $botUserId;

        try {
            $conv = ConversationService::getOrCreateDirect($spaceId, $botUserId, $targetUserId);
            $convId = (int) $conv['id'];

            // initiate() creates the real call record + all side-effects
            $call = CallService::initiate($convId, $botUserId);
        } finally {
            // Restore the original session user so the HTTP response handler
            // still sees the authenticated dev user.
            $_SESSION['user_id'] = $prevSession;
        }

        return [
            'call' => $call,
            'bot_user_id' => $botUserId,
            'bot_display_name' => $bot['display_name'],
            'scenario' => $scenario,
        ];
    }

    // ── Bot actions ───────────────────────────────────────────

    /**
     * Perform a bot-side action on an ongoing simulated call.
     *
     * The caller of this endpoint is the authenticated dev user; but the
     * action is executed by the bot (the call's actual caller).
     *
     * Supported actions:
     *   cancel  — bot cancels as caller while still ringing (→ missed)
     *   hangup  — bot hangs up an accepted call (→ ended)
     *
     * @throws ApiException  CALL_NOT_FOUND | DEV_BOT_NOT_CALLER | DEV_UNKNOWN_ACTION
     */
    public static function botAction(int $callId, string $action): array
    {
        $bot = self::requireBotUser();
        $botUserId = (int) $bot['id'];

        $call = CallRepository::find($callId);
        if (!$call) {
            throw ApiException::notFound('Anruf nicht gefunden', 'CALL_NOT_FOUND');
        }

        if ((int) $call['caller_user_id'] !== $botUserId) {
            throw ApiException::forbidden(
                'Dieser Anruf wurde nicht vom Dev-Bot initiiert.',
                'DEV_BOT_NOT_CALLER'
            );
        }

        $prevSession = $_SESSION['user_id'] ?? null;
        $_SESSION['user_id'] = $botUserId;

        try {
            $result = match ($action) {
                'cancel' => CallService::cancel($callId, $botUserId),
                'hangup' => CallService::hangup($callId, $botUserId),
                default => throw ApiException::validation(
                    "Unbekannte Bot-Aktion: {$action}",
                    'DEV_UNKNOWN_ACTION'
                ),
            };
        } finally {
            $_SESSION['user_id'] = $prevSession;
        }

        return $result;
    }

    // ── Force-reset stuck presence ────────────────────────────

    /**
     * Forcibly clear any stuck call_presence for the target user and the dev bot.
     * Also cancels any still-active (non-terminal) calls between them.
     *
     * Safe to call multiple times — idempotent.
     */
    public static function forceResetPresence(int $targetUserId): void
    {
        $bot = self::requireBotUser();
        $botUserId = (int) $bot['id'];

        // Cancel any still-ringing or active calls between bot and target
        $stmt = Database::connection()->prepare(
            "SELECT id FROM calls
              WHERE status NOT IN ('ended','missed','rejected','failed')
                AND (
                  (caller_user_id = ? AND callee_user_id = ?)
                  OR
                  (caller_user_id = ? AND callee_user_id = ?)
                )
              ORDER BY id DESC
              LIMIT 5"
        );
        $stmt->execute([$botUserId, $targetUserId, $targetUserId, $botUserId]);
        $rows = $stmt->fetchAll(\PDO::FETCH_ASSOC);

        $prevSession = $_SESSION['user_id'] ?? null;
        $_SESSION['user_id'] = $botUserId;

        try {
            foreach ($rows as $row) {
                try {
                    CallService::cancel((int) $row['id'], $botUserId);
                } catch (\Throwable) {
                    // best-effort — may already be transitioning
                }
            }
        } finally {
            $_SESSION['user_id'] = $prevSession;
        }

        // Direct DB clear as fallback — handles edge cases where cancel
        // didn't publish the presence event (e.g. already terminal state).
        UserRepository::clearCallPresence($targetUserId);
        UserRepository::clearCallPresence($botUserId);
        CallService::publishPresenceChangePublic($targetUserId, 'online');
        CallService::publishPresenceChangePublic($botUserId, 'online');
    }

    // ── Internal helpers ──────────────────────────────────────
    {
        $bot = UserRepository::findByEmail(self::BOT_EMAIL);
        if (!$bot) {
            throw ApiException::notFound(
                sprintf(
                    'Dev-Bot-Nutzer "%s" nicht gefunden. Bitte seed.sql ausführen.',
                    self::BOT_EMAIL
                ),
                'DEV_BOT_NOT_FOUND'
            );
        }
        return $bot;
    }

    /**
     * Find a space that both users share (needed for getOrCreateDirect).
     */
    private static function requireSharedSpace(int $userA, int $userB): int
    {
        $stmt = Database::connection()->prepare(
            'SELECT sm1.space_id
               FROM space_members sm1
               JOIN space_members sm2
                 ON sm2.space_id = sm1.space_id AND sm2.user_id = ?
              WHERE sm1.user_id = ?
              LIMIT 1'
        );
        $stmt->execute([$userB, $userA]);
        $row = $stmt->fetch();

        if (!$row) {
            throw ApiException::validation(
                'Bot und Zielnutzer teilen keinen gemeinsamen Space. Bitte überprüfe seed.sql.',
                'DEV_NO_SHARED_SPACE'
            );
        }
        return (int) $row['space_id'];
    }
}

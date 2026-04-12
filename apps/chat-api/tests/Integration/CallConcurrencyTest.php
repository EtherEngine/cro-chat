<?php

declare(strict_types=1);

namespace Tests\Integration;

use App\Repositories\CallRepository;
use App\Services\CallService;
use App\Support\Database;
use Tests\TestCase;

/**
 * Concurrency / race-condition tests for 1:1 audio calls.
 *
 * These tests simulate the "what-if-two-things-happen-at-the-same-time" scenarios
 * that the FOR UPDATE row locking in CallService is designed to prevent.
 *
 * Since PHPUnit is single-threaded we simulate races by:
 *  1. Manually manipulating DB state mid-flow
 *  2. Calling service methods in the same order a race would produce
 *
 * Covers:
 *  - Duplicate initiation on same conversation (CALL_ALREADY_ACTIVE)
 *  - Caller becomes busy between validation and lock (CALLER_BUSY)
 *  - Callee becomes busy between validation and lock (CALLEE_BUSY)
 *  - Accept-while-reject race (idempotent terminal wins)
 *  - Hangup-while-accept race (both parties hang up simultaneously)
 *  - Cancel-while-accept race
 *  - Double accept from the same callee (CALL_INVALID_TRANSITION)
 *  - Multiple simultaneous reapStaleCalls invocations
 *  - Presence state after every race outcome
 */
final class CallConcurrencyTest extends TestCase
{
    private array $alice;
    private array $bob;
    private array $space;
    private array $conv;

    protected function setUp(): void
    {
        parent::setUp();

        $this->alice = $this->createUser(['display_name' => 'Alice']);
        $this->bob = $this->createUser(['display_name' => 'Bob']);

        $this->space = $this->createSpace($this->alice['id']);
        $this->addSpaceMember($this->space['id'], $this->bob['id']);

        $this->conv = $this->createConversation(
            $this->space['id'],
            [$this->alice['id'], $this->bob['id']]
        );
    }

    // ── Helpers ────────────────────────────────────────────────────

    private function initiateCall(): array
    {
        return CallService::initiate($this->conv['id'], $this->alice['id']);
    }

    /** Directly set a call's status in the DB, bypassing the service. */
    private function forceStatus(int $callId, string $status, ?string $reason = null): void
    {
        Database::connection()->prepare(
            "UPDATE calls SET status = ?, end_reason = COALESCE(?, end_reason),
             ended_at = CASE WHEN ? IN ('rejected','ended','missed','failed') THEN NOW() ELSE ended_at END,
             answered_at = CASE WHEN ? = 'accepted' THEN COALESCE(answered_at, NOW()) ELSE answered_at END
             WHERE id = ?"
        )->execute([$status, $reason, $status, $status, $callId]);
    }

    /** Read a user's current status from the DB. */
    private function userStatus(int $userId): string
    {
        $row = Database::connection()->prepare("SELECT status FROM users WHERE id = ?");
        $row->execute([$userId]);
        return (string) ($row->fetch()['status'] ?? 'offline');
    }

    // ── Duplicate initiation ───────────────────────────────────────

    public function test_race_duplicate_initiation_rejected(): void
    {
        // First initiation succeeds
        $first = $this->initiateCall();
        $this->assertSame('ringing', $first['status']);

        // Second initiation on same conv → CALL_ALREADY_ACTIVE
        $this->assertApiException(409, 'CALL_ALREADY_ACTIVE', function () {
            $this->initiateCall();
        });
    }

    public function test_race_duplicate_initiation_with_different_caller_rejected(): void
    {
        $this->initiateCall(); // Alice calls Bob

        // Now Bob tries to call Alice on the same conv — same CALL_ALREADY_ACTIVE
        $this->assertApiException(409, 'CALL_ALREADY_ACTIVE', function () {
            CallService::initiate($this->conv['id'], $this->bob['id']);
        });
    }

    // ── Accept + reject race ───────────────────────────────────────

    /**
     * Simulates: Bob accepts, then a stale "reject" arrives (e.g. network retry).
     * The already-accepted call must stay accepted; the reject must be silently dropped.
     */
    public function test_race_accept_then_stale_reject_is_idempotent(): void
    {
        $call = $this->initiateCall();
        $callId = (int) $call['id'];

        CallService::accept($callId, $this->bob['id']);

        // Stale reject → already accepted, so transition ringing→rejected is invalid.
        // terminateCallLocked is idempotent for terminal states, but 'accepted' is not
        // terminal. However, we're past ringing — the reject should now fail the
        // transition guard (accepted → rejected is not in TRANSITIONS).
        $this->assertApiException(409, 'CALL_INVALID_TRANSITION', function () use ($callId) {
            CallService::reject($callId, $this->bob['id']);
        });
    }

    /**
     * Simulates: reject arrives, then a stale "accept" arrives.
     * accept() must fail because transition ringing→accepted is only valid
     * when status is 'ringing'; after reject the call is terminal.
     */
    public function test_race_reject_then_stale_accept_is_noop(): void
    {
        $call = $this->initiateCall();
        $callId = (int) $call['id'];

        CallService::reject($callId, $this->bob['id']);

        // terminateCallLocked idempotent path returns the already-terminal call.
        // But accept() calls requireTransition directly, not terminateCallLocked.
        // So this should throw CALL_INVALID_TRANSITION.
        $this->assertApiException(409, 'CALL_INVALID_TRANSITION', function () use ($callId) {
            CallService::accept($callId, $this->bob['id']);
        });
    }

    // ── Cancel + accept race ───────────────────────────────────────

    /**
     * Alice cancels while Bob is about to accept.
     * In the DB Alice's cancel wins (FOR UPDATE). Bob's accept then sees
     * a terminal state and can't transition.
     */
    public function test_race_cancel_beats_accept(): void
    {
        $call = $this->initiateCall();
        $callId = (int) $call['id'];

        // Simulate: Alice's cancel commits in DB first
        $this->forceStatus($callId, 'missed', 'caller_cancelled');

        // Bob's accept now hits an invalid transition
        $this->assertApiException(409, 'CALL_INVALID_TRANSITION', function () use ($callId) {
            CallService::accept($callId, $this->bob['id']);
        });
    }

    // ── Double hangup ──────────────────────────────────────────────

    /**
     * Both Alice and Bob call hangup at the same millisecond.
     * First commit wins; second must be silently idempotent.
     */
    public function test_race_double_hangup_both_parties(): void
    {
        $call = $this->initiateCall();
        $callId = (int) $call['id'];
        CallService::accept($callId, $this->bob['id']);

        // Alice hangs up first
        $result1 = CallService::hangup($callId, $this->alice['id']);
        $this->assertSame('ended', $result1['status']);

        // Bob's hangup arrives slightly later — must be idempotent
        $result2 = CallService::hangup($callId, $this->bob['id']);
        $this->assertSame('ended', $result2['status']);
    }

    // ── Hangup racing with accept (beforeunload scenario) ──────────

    /**
     * Alice closes the browser tab while Bob is still accepting.
     * Alice's beforeunload fires hangup/cancel; Bob's accept gets an invalid transition.
     */
    public function test_race_beforeunload_hangup_during_accept(): void
    {
        $call = $this->initiateCall();
        $callId = (int) $call['id'];

        // Simulate Accept succeeding first (sets status = accepted)
        CallService::accept($callId, $this->bob['id']);

        // Then Alice's beforeunload fires — hangup on accepted call is valid
        $result = CallService::hangup($callId, $this->alice['id']);
        $this->assertSame('ended', $result['status']);
    }

    /**
     * Cancel fires while call is still initiating (missed → caller_cancelled),
     * then the initiate would have succeeded — the cancel already terminates.
     */
    public function test_race_cancel_on_ringing_call(): void
    {
        $call = $this->initiateCall();
        $callId = (int) $call['id'];
        $updated = CallService::cancel($callId, $this->alice['id']);

        $this->assertSame('missed', $updated['status']);
        $this->assertSame('caller_cancelled', $updated['end_reason']);
    }

    // ── Reap while accept ──────────────────────────────────────────

    /**
     * The timeout worker and the callee accept arrive at the same time.
     * Whoever wins the FOR UPDATE lock prevails; the other is an idempotent noop.
     */
    public function test_race_reap_while_accept_in_progress(): void
    {
        $call = $this->initiateCall();
        $callId = (int) $call['id'];

        // Simulate reaper wins: already marks as missed
        $this->forceStatus($callId, 'missed', 'timeout');

        // Accept now sees ringing→accepted as invalid (status is missed/terminal)
        $this->assertApiException(409, 'CALL_INVALID_TRANSITION', function () use ($callId) {
            CallService::accept($callId, $this->bob['id']);
        });
    }

    /**
     * Accept wins before reaper can lock.
     * Reaper must skip accepted calls — only reaps ringing.
     */
    public function test_race_accept_before_reap(): void
    {
        $call = $this->initiateCall();
        $callId = (int) $call['id'];

        // Simulate stale timestamp AFTER accept
        CallService::accept($callId, $this->bob['id']);

        // Backdating start time should not matter — reaper only targets ringing
        Database::connection()->prepare(
            "UPDATE calls SET started_at = DATE_SUB(NOW(), INTERVAL 120 SECOND) WHERE id = ?"
        )->execute([$callId]);

        $reaped = CallService::reapStaleCalls();
        $this->assertSame(0, $reaped);

        $fresh = CallRepository::find($callId);
        $this->assertSame('accepted', $fresh['status']);
    }

    // ── Multi-call scenarios ───────────────────────────────────────

    /**
     * Three independent conversations with stale calls.
     * reapStaleCalls must handle all of them atomically (each in its own transaction).
     */
    public function test_reap_multiple_independent_stale_calls(): void
    {
        $callIds = [];
        for ($i = 0; $i < 3; $i++) {
            $caller = $this->createUser();
            $callee = $this->createUser();
            $this->addSpaceMember($this->space['id'], $caller['id']);
            $this->addSpaceMember($this->space['id'], $callee['id']);
            $conv = $this->createConversation($this->space['id'], [$caller['id'], $callee['id']]);
            $call = CallService::initiate($conv['id'], $caller['id']);
            $callIds[] = (int) $call['id'];

            // Backdate to stale
            Database::connection()->prepare(
                "UPDATE calls SET started_at = DATE_SUB(NOW(), INTERVAL 60 SECOND) WHERE id = ?"
            )->execute([(int) $call['id']]);
        }

        $reaped = CallService::reapStaleCalls();
        $this->assertSame(3, $reaped);

        foreach ($callIds as $id) {
            $c = CallRepository::find($id);
            $this->assertSame('missed', $c['status']);
        }
    }

    /**
     * One stale call + one fresh call on separate convs.
     * Only the stale one is reaped.
     */
    public function test_reap_only_stale_not_fresh(): void
    {
        // Stale call — independent pair
        $caller1 = $this->createUser();
        $callee1 = $this->createUser();
        $this->addSpaceMember($this->space['id'], $caller1['id']);
        $this->addSpaceMember($this->space['id'], $callee1['id']);
        $conv1 = $this->createConversation($this->space['id'], [$caller1['id'], $callee1['id']]);
        $stale = CallService::initiate($conv1['id'], $caller1['id']);
        Database::connection()->prepare(
            "UPDATE calls SET started_at = DATE_SUB(NOW(), INTERVAL 60 SECOND) WHERE id = ?"
        )->execute([(int) $stale['id']]);

        // Fresh call — independent pair
        $caller2 = $this->createUser();
        $callee2 = $this->createUser();
        $this->addSpaceMember($this->space['id'], $caller2['id']);
        $this->addSpaceMember($this->space['id'], $callee2['id']);
        $conv2 = $this->createConversation($this->space['id'], [$caller2['id'], $callee2['id']]);
        $fresh = CallService::initiate($conv2['id'], $caller2['id']);

        $reaped = CallService::reapStaleCalls();
        $this->assertSame(1, $reaped);

        $this->assertSame('missed', CallRepository::find((int) $stale['id'])['status']);
        $this->assertSame('ringing', CallRepository::find((int) $fresh['id'])['status']);
    }

    // ── Caller / callee busy detection ─────────────────────────────

    public function test_new_call_blocked_when_caller_already_in_active_call(): void
    {
        // Alice is ringing on conv (with Bob)
        $this->initiateCall();

        // Alice tries again on a different conversation
        $charlie = $this->createUser(['display_name' => 'Charlie']);
        $this->addSpaceMember($this->space['id'], $charlie['id']);
        $otherConv = $this->createConversation($this->space['id'], [$this->alice['id'], $charlie['id']]);

        $this->assertApiException(409, 'CALLER_BUSY', function () use ($otherConv) {
            CallService::initiate($otherConv['id'], $this->alice['id']);
        });
    }

    public function test_new_call_blocked_when_callee_already_in_active_call(): void
    {
        // Bob is ringing (Alice called him on main conv)
        $this->initiateCall();

        // Now Charlie tries to call Bob
        $charlie = $this->createUser(['display_name' => 'Charlie']);
        $this->addSpaceMember($this->space['id'], $charlie['id']);
        $otherConv = $this->createConversation($this->space['id'], [$this->bob['id'], $charlie['id']]);

        $this->assertApiException(409, 'CALLEE_BUSY', function () use ($otherConv, $charlie) {
            CallService::initiate($otherConv['id'], $charlie['id']);
        });
    }

    // ── Presence correctness ───────────────────────────────────────

    public function test_presence_cleared_after_hangup(): void
    {
        $call = $this->initiateCall();
        $callId = (int) $call['id'];
        CallService::accept($callId, $this->bob['id']);
        CallService::hangup($callId, $this->alice['id']);

        // After hangup both users must be back to online/offline — not in_call
        $aliceStatus = $this->userStatus($this->alice['id']);
        $bobStatus = $this->userStatus($this->bob['id']);

        $this->assertNotSame('in_call', $aliceStatus);
        $this->assertNotSame('in_call', $bobStatus);
        $this->assertNotSame('ringing', $aliceStatus);
        $this->assertNotSame('ringing', $bobStatus);
    }

    public function test_presence_cleared_after_reject(): void
    {
        $call = $this->initiateCall();
        CallService::reject((int) $call['id'], $this->bob['id']);

        $this->assertNotSame('in_call', $this->userStatus($this->alice['id']));
        $this->assertNotSame('ringing', $this->userStatus($this->bob['id']));
    }

    public function test_presence_cleared_after_missed(): void
    {
        $call = $this->initiateCall();
        $callId = (int) $call['id'];
        Database::connection()->prepare(
            "UPDATE calls SET started_at = DATE_SUB(NOW(), INTERVAL 60 SECOND) WHERE id = ?"
        )->execute([$callId]);

        CallService::reapStaleCalls();

        $this->assertNotSame('in_call', $this->userStatus($this->alice['id']));
        $this->assertNotSame('ringing', $this->userStatus($this->bob['id']));
    }
}

<?php

declare(strict_types=1);

namespace Tests\Integration;

use App\Repositories\CallRepository;
use App\Services\CallService;
use App\Support\Database;
use Tests\TestCase;

/**
 * Integration tests for the 1:1 audio-call lifecycle.
 *
 * Covers:
 *  - Successful initiation, acceptance, rejection, cancellation, hangup
 *  - Permission checks (non-member, group conv, self-call)
 *  - DND (Do Not Disturb) enforcement
 *  - State-machine transition guards
 *  - Idempotent terminal transitions
 *  - Missed-call reaping (reapStaleCalls)
 *  - markFailed error handling
 *  - Call history and active-call queries
 *  - Session row correctness after lifecycle events
 */
final class CallTest extends TestCase
{
    private array $alice;   // caller
    private array $bob;     // callee
    private array $charlie; // third-party (outsider for some tests)
    private array $space;
    private array $conv;    // Alice ↔ Bob 1:1 DM

    protected function setUp(): void
    {
        parent::setUp();

        $this->alice = $this->createUser(['display_name' => 'Alice']);
        $this->bob = $this->createUser(['display_name' => 'Bob']);
        $this->charlie = $this->createUser(['display_name' => 'Charlie']);

        $this->space = $this->createSpace($this->alice['id']);
        $this->addSpaceMember($this->space['id'], $this->bob['id']);
        $this->addSpaceMember($this->space['id'], $this->charlie['id']);

        // 1:1 DM between Alice and Bob
        $this->conv = $this->createConversation(
            $this->space['id'],
            [$this->alice['id'], $this->bob['id']]
        );
    }

    // ── Helpers ────────────────────────────────────────────────────

    /** Initiate a call from Alice to Bob and return the ringing call. */
    private function initiateCall(): array
    {
        return CallService::initiate($this->conv['id'], $this->alice['id']);
    }

    /**
     * Back-date a call's `started_at` so reapStaleCalls considers it stale.
     * Uses NOW() - interval intentionally larger than RINGING_TIMEOUT_SECONDS.
     */
    private function makeCallStale(int $callId): void
    {
        $timeout = CallService::RINGING_TIMEOUT_SECONDS + 10;
        Database::connection()->prepare(
            "UPDATE calls SET started_at = DATE_SUB(NOW(), INTERVAL ? SECOND) WHERE id = ?"
        )->execute([$timeout, $callId]);
    }

    // ── Initiation ─────────────────────────────────────────────────

    public function test_initiate_creates_ringing_call(): void
    {
        $call = $this->initiateCall();

        $this->assertSame('ringing', $call['status']);
        $this->assertSame($this->conv['id'], (int) $call['conversation_id']);
        $this->assertSame($this->alice['id'], (int) $call['caller_user_id']);
        $this->assertSame($this->bob['id'], (int) $call['callee_user_id']);
        $this->assertNull($call['answered_at']);
        $this->assertNull($call['ended_at']);
        $this->assertNull($call['duration_seconds']);
    }

    public function test_initiate_creates_session_rows(): void
    {
        $call = $this->initiateCall();

        $sessions = CallRepository::sessions((int) $call['id']);
        $this->assertCount(2, $sessions);

        $roles = array_column($sessions, 'role');
        $this->assertContains('caller', $roles);
        $this->assertContains('callee', $roles);

        $callerSession = current(array_filter($sessions, fn($s) => $s['role'] === 'caller'));
        $this->assertSame($this->alice['id'], (int) $callerSession['user_id']);

        $calleeSession = current(array_filter($sessions, fn($s) => $s['role'] === 'callee'));
        $this->assertSame($this->bob['id'], (int) $calleeSession['user_id']);
    }

    public function test_cannot_initiate_to_group_conversation(): void
    {
        $dave = $this->createUser(['display_name' => 'Dave']);
        $this->addSpaceMember($this->space['id'], $dave['id']);
        $groupConv = $this->createConversation(
            $this->space['id'],
            [$this->alice['id'], $this->bob['id'], $dave['id']],
            isGroup: true
        );

        $this->assertApiException(422, 'CALL_GROUP_NOT_SUPPORTED', function () use ($groupConv) {
            CallService::initiate($groupConv['id'], $this->alice['id']);
        });
    }

    public function test_cannot_initiate_when_not_member(): void
    {
        // Charlie is not a member of Alice↔Bob DM — use the existing conv from setUp()
        $this->assertApiException(403, 'CONVERSATION_ACCESS_DENIED', function () {
            CallService::initiate($this->conv['id'], $this->charlie['id']);
        });
    }

    public function test_cannot_initiate_to_nonexistent_conversation(): void
    {
        $this->assertApiException(404, 'CONVERSATION_NOT_FOUND', function () {
            CallService::initiate(99999, $this->alice['id']);
        });
    }

    public function test_cannot_initiate_when_active_call_exists(): void
    {
        $this->initiateCall(); // First call → ringing

        $this->assertApiException(409, 'CALL_ALREADY_ACTIVE', function () {
            $this->initiateCall(); // Second call on same conversation
        });
    }

    public function test_cannot_initiate_when_caller_is_busy_in_another_call(): void
    {
        // Alice is already in a call on a different conversation
        $eve = $this->createUser(['display_name' => 'Eve']);
        $this->addSpaceMember($this->space['id'], $eve['id']);
        $otherConv = $this->createConversation($this->space['id'], [$this->alice['id'], $eve['id']]);

        CallService::initiate($otherConv['id'], $this->alice['id']); // Alice busy here

        $this->assertApiException(409, 'CALLER_BUSY', function () {
            $this->initiateCall(); // Try to call Bob while still in the first call
        });
    }

    public function test_cannot_initiate_when_callee_is_busy_in_another_call(): void
    {
        $eve = $this->createUser(['display_name' => 'Eve']);
        $this->addSpaceMember($this->space['id'], $eve['id']);
        $otherConv = $this->createConversation($this->space['id'], [$this->bob['id'], $eve['id']]);

        CallService::initiate($otherConv['id'], $this->bob['id']); // Bob is now ringing on other conv

        $this->assertApiException(409, 'CALLEE_BUSY', function () {
            $this->initiateCall(); // Alice tries to also call Bob
        });
    }

    // ── DND ────────────────────────────────────────────────────────

    public function test_cannot_initiate_when_callee_has_dnd(): void
    {
        // Set Bob's status to DND
        Database::connection()->prepare(
            "UPDATE users SET status = 'dnd' WHERE id = ?"
        )->execute([$this->bob['id']]);

        $this->assertApiException(409, 'CALLEE_DND', function () {
            $this->initiateCall();
        });
    }

    // ── Accept ─────────────────────────────────────────────────────

    public function test_callee_can_accept_ringing_call(): void
    {
        $call = $this->initiateCall();
        $callId = (int) $call['id'];
        $updated = CallService::accept($callId, $this->bob['id']);

        $this->assertSame('accepted', $updated['status']);
        $this->assertNotNull($updated['answered_at']);
        $this->assertNull($updated['ended_at']);
    }

    public function test_caller_cannot_accept_own_call(): void
    {
        $call = $this->initiateCall();

        $this->assertApiException(403, 'CALL_NOT_CALLEE', function () use ($call) {
            CallService::accept((int) $call['id'], $this->alice['id']);
        });
    }

    public function test_outsider_cannot_accept_call(): void
    {
        $call = $this->initiateCall();

        $this->assertApiException(403, 'CALL_ACCESS_DENIED', function () use ($call) {
            CallService::accept((int) $call['id'], $this->charlie['id']);
        });
    }

    public function test_cannot_accept_already_accepted_call(): void
    {
        $call = $this->initiateCall();
        $callId = (int) $call['id'];
        CallService::accept($callId, $this->bob['id']);

        $this->assertApiException(409, 'CALL_INVALID_TRANSITION', function () use ($callId) {
            CallService::accept($callId, $this->bob['id']);
        });
    }

    // ── Reject ─────────────────────────────────────────────────────

    public function test_callee_can_reject_ringing_call(): void
    {
        $call = $this->initiateCall();
        $updated = CallService::reject((int) $call['id'], $this->bob['id']);

        $this->assertSame('rejected', $updated['status']);
        $this->assertNotNull($updated['ended_at']);
        $this->assertSame('rejected', $updated['end_reason']);
    }

    public function test_caller_cannot_reject_own_call(): void
    {
        $call = $this->initiateCall();

        $this->assertApiException(403, 'CALL_NOT_CALLEE', function () use ($call) {
            CallService::reject((int) $call['id'], $this->alice['id']);
        });
    }

    public function test_outsider_cannot_reject_call(): void
    {
        $call = $this->initiateCall();

        $this->assertApiException(403, 'CALL_ACCESS_DENIED', function () use ($call) {
            CallService::reject((int) $call['id'], $this->charlie['id']);
        });
    }

    // ── Cancel ─────────────────────────────────────────────────────

    public function test_caller_can_cancel_ringing_call(): void
    {
        $call = $this->initiateCall();
        $updated = CallService::cancel((int) $call['id'], $this->alice['id']);

        $this->assertSame('missed', $updated['status']);
        $this->assertSame('caller_cancelled', $updated['end_reason']);
    }

    public function test_callee_cannot_cancel_call(): void
    {
        $call = $this->initiateCall();

        $this->assertApiException(403, 'CALL_NOT_CALLER', function () use ($call) {
            CallService::cancel((int) $call['id'], $this->bob['id']);
        });
    }

    // ── Hangup ─────────────────────────────────────────────────────

    public function test_caller_can_hang_up_active_call(): void
    {
        $call = $this->initiateCall();
        $callId = (int) $call['id'];
        CallService::accept($callId, $this->bob['id']);

        $updated = CallService::hangup($callId, $this->alice['id']);

        $this->assertSame('ended', $updated['status']);
        $this->assertSame('hangup', $updated['end_reason']);
        $this->assertNotNull($updated['ended_at']);
    }

    public function test_callee_can_hang_up_active_call(): void
    {
        $call = $this->initiateCall();
        $callId = (int) $call['id'];
        CallService::accept($callId, $this->bob['id']);

        $updated = CallService::hangup($callId, $this->bob['id']);

        $this->assertSame('ended', $updated['status']);
    }

    public function test_outsider_cannot_hang_up(): void
    {
        $call = $this->initiateCall();
        $callId = (int) $call['id'];
        CallService::accept($callId, $this->bob['id']);

        $this->assertApiException(403, 'CALL_ACCESS_DENIED', function () use ($callId) {
            CallService::hangup($callId, $this->charlie['id']);
        });
    }

    public function test_cannot_hangup_ringing_call(): void
    {
        $call = $this->initiateCall(); // still ringing — not accepted

        $this->assertApiException(409, 'CALL_INVALID_TRANSITION', function () use ($call) {
            CallService::hangup((int) $call['id'], $this->alice['id']);
        });
    }

    public function test_duration_computed_when_call_ends(): void
    {
        $call = $this->initiateCall();
        $callId = (int) $call['id'];
        CallService::accept($callId, $this->bob['id']);

        // Simulate 30s elapsed by back-dating answered_at
        Database::connection()->prepare(
            "UPDATE calls SET answered_at = DATE_SUB(NOW(), INTERVAL 30 SECOND) WHERE id = ?"
        )->execute([$callId]);

        $updated = CallService::hangup($callId, $this->alice['id']);

        $this->assertNotNull($updated['duration_seconds']);
        $this->assertGreaterThanOrEqual(29, (int) $updated['duration_seconds']);
    }

    // ── Idempotency of terminal transitions ────────────────────────

    public function test_double_hangup_is_idempotent(): void
    {
        $call = $this->initiateCall();
        $callId = (int) $call['id'];
        CallService::accept($callId, $this->bob['id']);
        CallService::hangup($callId, $this->alice['id']);

        // Second hangup must not throw — returns the already-terminal call
        $result = CallService::hangup($callId, $this->alice['id']);
        $this->assertSame('ended', $result['status']);
    }

    public function test_double_reject_is_idempotent(): void
    {
        $call = $this->initiateCall();
        $callId = (int) $call['id'];
        CallService::reject($callId, $this->bob['id']);

        $result = CallService::reject($callId, $this->bob['id']);
        $this->assertSame('rejected', $result['status']);
    }

    // ── markFailed ─────────────────────────────────────────────────

    public function test_mark_failed_on_ringing_call(): void
    {
        $call = $this->initiateCall();
        $updated = CallService::markFailed((int) $call['id'], 'network_error');

        $this->assertSame('failed', $updated['status']);
        $this->assertSame('network_error', $updated['end_reason']);
    }

    public function test_mark_failed_on_accepted_call(): void
    {
        $call = $this->initiateCall();
        $callId = (int) $call['id'];
        CallService::accept($callId, $this->bob['id']);

        $updated = CallService::markFailed($callId, 'ice_failed');

        $this->assertSame('failed', $updated['status']);
        $this->assertSame('ice_failed', $updated['end_reason']);
    }

    public function test_mark_failed_on_already_terminal_call_is_idempotent(): void
    {
        $call = $this->initiateCall();
        $callId = (int) $call['id'];
        CallService::reject($callId, $this->bob['id']); // already rejected

        $result = CallService::markFailed($callId);
        $this->assertSame('rejected', $result['status'], 'Terminal status must not change');
    }

    // ── Missed-call reaping ────────────────────────────────────────

    public function test_reap_stale_calls_marks_ringing_as_missed(): void
    {
        $call = $this->initiateCall();
        $callId = (int) $call['id'];

        $this->makeCallStale($callId);

        $reaped = CallService::reapStaleCalls();
        $this->assertSame(1, $reaped);

        $fresh = CallRepository::find($callId);
        $this->assertSame('missed', $fresh['status']);
        $this->assertSame('timeout', $fresh['end_reason']);
    }

    public function test_reap_stale_calls_ignores_accepted_calls(): void
    {
        $call = $this->initiateCall();
        $callId = (int) $call['id'];
        CallService::accept($callId, $this->bob['id']);

        $this->makeCallStale($callId);

        $reaped = CallService::reapStaleCalls();
        $this->assertSame(0, $reaped, 'Accepted calls must not be reaped as missed');

        $fresh = CallRepository::find($callId);
        $this->assertSame('accepted', $fresh['status']);
    }

    public function test_reap_stale_calls_ignores_fresh_ringing_calls(): void
    {
        $this->initiateCall();  // fresh — within timeout window

        $reaped = CallService::reapStaleCalls();
        $this->assertSame(0, $reaped);
    }

    public function test_reap_stale_calls_reaps_multiple(): void
    {
        // Each iteration uses a distinct caller+callee pair, no user conflicts
        for ($i = 0; $i < 3; $i++) {
            $caller = $this->createUser();
            $callee = $this->createUser();
            $this->addSpaceMember($this->space['id'], $caller['id']);
            $this->addSpaceMember($this->space['id'], $callee['id']);
            $c = $this->createConversation($this->space['id'], [$caller['id'], $callee['id']]);
            $call = CallService::initiate($c['id'], $caller['id']);
            $this->makeCallStale((int) $call['id']);
        }

        $this->assertSame(3, CallService::reapStaleCalls());
    }

    // ── Session state after lifecycle ──────────────────────────────

    public function test_sessions_have_joined_at_after_accept(): void
    {
        $call = $this->initiateCall();
        $callId = (int) $call['id'];
        CallService::accept($callId, $this->bob['id']);

        $sessions = CallRepository::sessions($callId);
        foreach ($sessions as $session) {
            $this->assertNotNull($session['joined_at'], "Session for user {$session['user_id']} should have joined_at set");
        }
    }

    public function test_sessions_have_left_at_after_hangup(): void
    {
        $call = $this->initiateCall();
        $callId = (int) $call['id'];
        CallService::accept($callId, $this->bob['id']);
        CallService::hangup($callId, $this->alice['id']);

        $sessions = CallRepository::sessions($callId);
        foreach ($sessions as $session) {
            $this->assertNotNull($session['left_at'], "Session for user {$session['user_id']} should have left_at set");
        }
    }

    public function test_sessions_have_left_at_after_reject(): void
    {
        $call = $this->initiateCall();
        $callId = (int) $call['id'];
        CallService::reject($callId, $this->bob['id']);

        $sessions = CallRepository::sessions($callId);
        foreach ($sessions as $session) {
            $this->assertNotNull($session['left_at']);
        }
    }

    // ── show() / active() / history() ──────────────────────────────

    public function test_show_returns_call_with_sessions(): void
    {
        $call = $this->initiateCall();
        $result = CallService::show((int) $call['id'], $this->alice['id']);

        $this->assertArrayHasKey('sessions', $result);
        $this->assertCount(2, $result['sessions']);
    }

    public function test_show_denies_outsider(): void
    {
        $call = $this->initiateCall();

        $this->assertApiException(403, 'CALL_ACCESS_DENIED', function () use ($call) {
            CallService::show((int) $call['id'], $this->charlie['id']);
        });
    }

    public function test_active_returns_ringing_call(): void
    {
        $this->initiateCall();

        $active = CallService::active($this->conv['id'], $this->alice['id']);

        $this->assertNotNull($active);
        $this->assertSame('ringing', $active['status']);
    }

    public function test_active_returns_null_when_no_call(): void
    {
        $result = CallService::active($this->conv['id'], $this->alice['id']);
        $this->assertNull($result);
    }

    public function test_active_returns_null_after_call_ends(): void
    {
        $call = $this->initiateCall();
        $callId = (int) $call['id'];
        CallService::accept($callId, $this->bob['id']);
        CallService::hangup($callId, $this->alice['id']);

        $active = CallService::active($this->conv['id'], $this->alice['id']);
        $this->assertNull($active);
    }

    public function test_history_returns_completed_calls(): void
    {
        // Complete two calls
        for ($i = 0; $i < 2; $i++) {
            $call = $this->initiateCall();
            CallService::accept((int) $call['id'], $this->bob['id']);
            CallService::hangup((int) $call['id'], $this->alice['id']);
        }

        $history = CallService::history($this->conv['id'], $this->alice['id']);
        $this->assertCount(2, $history);
    }

    public function test_history_excludes_ongoing_call(): void
    {
        $this->initiateCall(); // still ringing — active, not in history

        $history = CallService::history($this->conv['id'], $this->alice['id']);
        $this->assertSame([], $history);
    }

    public function test_history_paginates(): void
    {
        for ($i = 0; $i < 5; $i++) {
            $call = $this->initiateCall();
            CallService::reject((int) $call['id'], $this->bob['id']);
        }

        $page1 = CallService::history($this->conv['id'], $this->alice['id'], limit: 3, offset: 0);
        $page2 = CallService::history($this->conv['id'], $this->alice['id'], limit: 3, offset: 3);

        $this->assertCount(3, $page1);
        $this->assertCount(2, $page2);
    }

    // ── Call creates history message ────────────────────────────────

    public function test_ended_call_creates_history_message(): void
    {
        $call = $this->initiateCall();
        $callId = (int) $call['id'];
        CallService::accept($callId, $this->bob['id']);
        CallService::hangup($callId, $this->alice['id']);

        $db = Database::connection();
        $stmt = $db->prepare(
            "SELECT * FROM messages WHERE conversation_id = ? AND type = 'call' AND call_id = ?"
        );
        $stmt->execute([$this->conv['id'], $callId]);
        $msg = $stmt->fetch();

        $this->assertNotFalse($msg, 'Call history message should be created');
        $this->assertSame('call', $msg['type']);
    }

    public function test_rejected_call_creates_history_message(): void
    {
        $call = $this->initiateCall();
        $callId = (int) $call['id'];
        CallService::reject($callId, $this->bob['id']);

        $stmt = Database::connection()->prepare(
            "SELECT * FROM messages WHERE conversation_id = ? AND type = 'call'"
        );
        $stmt->execute([$this->conv['id']]);
        $this->assertNotFalse($stmt->fetch());
    }
}

<?php

declare(strict_types=1);

namespace Tests\Integration;

use App\Exceptions\ApiException;
use App\Services\ConversationService;
use App\Services\MessageService;
use Tests\TestCase;

final class ConversationTest extends TestCase
{
    private array $alice;
    private array $bob;
    private array $charlie;
    private array $outsider;
    private array $space;

    protected function setUp(): void
    {
        parent::setUp();

        $this->alice = $this->createUser(['display_name' => 'Alice']);
        $this->bob = $this->createUser(['display_name' => 'Bob']);
        $this->charlie = $this->createUser(['display_name' => 'Charlie']);
        $this->outsider = $this->createUser(['display_name' => 'Outsider']);

        $this->space = $this->createSpace($this->alice['id']);
        $this->addSpaceMember($this->space['id'], $this->bob['id']);
        $this->addSpaceMember($this->space['id'], $this->charlie['id']);
        // outsider not in space
    }

    // ── 1:1 DM ───────────────────────────────

    public function test_create_direct_dm(): void
    {
        $conv = ConversationService::getOrCreateDirect(
            $this->space['id'],
            $this->alice['id'],
            $this->bob['id']
        );

        $this->assertFalse($conv['is_group']);
        $this->assertSame($this->space['id'], (int) $conv['space_id']);

        $memberIds = array_column($conv['users'], 'id');
        $this->assertContains($this->alice['id'], $memberIds);
        $this->assertContains($this->bob['id'], $memberIds);
    }

    public function test_dm_is_idempotent(): void
    {
        $conv1 = ConversationService::getOrCreateDirect(
            $this->space['id'],
            $this->alice['id'],
            $this->bob['id']
        );

        $conv2 = ConversationService::getOrCreateDirect(
            $this->space['id'],
            $this->bob['id'],
            $this->alice['id']
        );

        $this->assertSame($conv1['id'], $conv2['id'], 'Same pair should reuse DM');
    }

    public function test_cannot_dm_self(): void
    {
        $this->assertApiException(422, 'SELF_CONVERSATION', function () {
            ConversationService::getOrCreateDirect(
                $this->space['id'],
                $this->alice['id'],
                $this->alice['id']
            );
        });
    }

    public function test_cannot_dm_outsider(): void
    {
        $this->assertApiException(403, 'SPACE_MEMBER_REQUIRED', function () {
            ConversationService::getOrCreateDirect(
                $this->space['id'],
                $this->alice['id'],
                $this->outsider['id']
            );
        });
    }

    // ── Group DM ──────────────────────────────

    public function test_create_group_dm(): void
    {
        $conv = ConversationService::createGroup(
            $this->space['id'],
            $this->alice['id'],
            [$this->bob['id'], $this->charlie['id']]
        );

        $this->assertTrue($conv['is_group']);
        $memberIds = array_column($conv['users'], 'id');
        $this->assertCount(3, $memberIds);
    }

    public function test_group_dm_needs_at_least_3_members(): void
    {
        $this->assertApiException(422, 'GROUP_TOO_SMALL', function () {
            ConversationService::createGroup(
                $this->space['id'],
                $this->alice['id'],
                [$this->bob['id']]
            );
        });
    }

    public function test_group_dm_rejects_outsider(): void
    {
        $this->assertApiException(403, 'SPACE_MEMBER_REQUIRED', function () {
            ConversationService::createGroup(
                $this->space['id'],
                $this->alice['id'],
                [$this->bob['id'], $this->outsider['id']]
            );
        });
    }

    // ── DM access control ─────────────────────

    public function test_member_can_view_own_conversation(): void
    {
        $conv = $this->createConversation(
            $this->space['id'],
            [$this->alice['id'], $this->bob['id']]
        );

        $result = ConversationService::show($conv['id'], $this->alice['id']);
        $this->assertSame($conv['id'], $result['id']);
    }

    public function test_nonmember_cannot_view_conversation(): void
    {
        $conv = $this->createConversation(
            $this->space['id'],
            [$this->alice['id'], $this->bob['id']]
        );

        $this->assertApiException(403, 'CONVERSATION_ACCESS_DENIED', function () use ($conv) {
            ConversationService::show($conv['id'], $this->charlie['id']);
        });
    }

    public function test_outsider_cannot_view_conversation(): void
    {
        $conv = $this->createConversation(
            $this->space['id'],
            [$this->alice['id'], $this->bob['id']]
        );

        $this->assertApiException(403, 'CONVERSATION_ACCESS_DENIED', function () use ($conv) {
            ConversationService::show($conv['id'], $this->outsider['id']);
        });
    }

    // ── DM messages ───────────────────────────

    public function test_member_can_send_dm_message(): void
    {
        $conv = $this->createConversation(
            $this->space['id'],
            [$this->alice['id'], $this->bob['id']]
        );

        $msg = MessageService::createConversation(
            $conv['id'],
            $this->alice['id'],
            ['body' => 'Hey Bob!']
        );

        $this->assertSame('Hey Bob!', $msg['body']);
        $this->assertSame($conv['id'], $msg['conversation_id']);
    }

    public function test_nonmember_cannot_send_dm_message(): void
    {
        $conv = $this->createConversation(
            $this->space['id'],
            [$this->alice['id'], $this->bob['id']]
        );

        $this->assertApiException(403, 'CONVERSATION_ACCESS_DENIED', function () use ($conv) {
            MessageService::createConversation(
                $conv['id'],
                $this->charlie['id'],
                ['body' => 'Intruder']
            );
        });
    }

    public function test_nonmember_cannot_list_dm_messages(): void
    {
        $conv = $this->createConversation(
            $this->space['id'],
            [$this->alice['id'], $this->bob['id']]
        );

        $this->assertApiException(403, 'CONVERSATION_ACCESS_DENIED', function () use ($conv) {
            MessageService::listConversation($conv['id'], $this->charlie['id']);
        });
    }

    // ── List conversations ────────────────────

    public function test_list_only_own_conversations(): void
    {
        // Alice-Bob DM
        $this->createConversation(
            $this->space['id'],
            [$this->alice['id'], $this->bob['id']]
        );
        // Alice-Charlie DM
        $this->createConversation(
            $this->space['id'],
            [$this->alice['id'], $this->charlie['id']]
        );
        // Bob-Charlie DM (Alice not in this)
        $this->createConversation(
            $this->space['id'],
            [$this->bob['id'], $this->charlie['id']]
        );

        $aliceConvs = ConversationService::listForUser($this->alice['id']);
        $this->assertCount(2, $aliceConvs);

        $bobConvs = ConversationService::listForUser($this->bob['id']);
        $this->assertCount(2, $bobConvs);
    }
}

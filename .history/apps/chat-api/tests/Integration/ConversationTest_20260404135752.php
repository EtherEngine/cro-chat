<?php

declare(strict_types=1);

namespace Tests\Integration;

use App\Exceptions\ApiException;
use App\Repositories\ConversationRepository;
use App\Services\ConversationService;
use App\Services\MessageService;
use App\Support\Database;
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

    // ── Group DM: title & avatar ──────────────

    public function test_create_group_dm_with_title(): void
    {
        $conv = ConversationService::createGroup(
            $this->space['id'],
            $this->alice['id'],
            [$this->bob['id'], $this->charlie['id']],
            'Projektgruppe'
        );

        $this->assertTrue($conv['is_group']);
        $this->assertSame('Projektgruppe', $conv['title']);
        $this->assertSame($this->alice['id'], (int) $conv['created_by']);
    }

    public function test_rename_group_dm(): void
    {
        $conv = ConversationService::createGroup(
            $this->space['id'],
            $this->alice['id'],
            [$this->bob['id'], $this->charlie['id']],
            'Alt'
        );

        $updated = ConversationService::rename($conv['id'], $this->bob['id'], 'Neuer Name');
        $this->assertSame('Neuer Name', $updated['title']);

        // Verify domain event
        $events = Database::connection()->query(
            "SELECT * FROM domain_events WHERE event_type = 'conversation.updated'"
        )->fetchAll(\PDO::FETCH_ASSOC);
        $this->assertCount(1, $events);
        $this->assertSame("conversation:{$conv['id']}", $events[0]['room']);
    }

    public function test_rename_non_group_dm_fails(): void
    {
        $conv = $this->createConversation(
            $this->space['id'],
            [$this->alice['id'], $this->bob['id']],
            false
        );

        $this->assertApiException(422, 'GROUP_ONLY', function () use ($conv) {
            ConversationService::rename($conv['id'], $this->alice['id'], 'Nope');
        });
    }

    public function test_non_member_cannot_rename(): void
    {
        $conv = ConversationService::createGroup(
            $this->space['id'],
            $this->alice['id'],
            [$this->bob['id'], $this->charlie['id']],
            'Titel'
        );

        $this->assertApiException(403, 'CONVERSATION_ACCESS_DENIED', function () use ($conv) {
            ConversationService::rename($conv['id'], $this->outsider['id'], 'Hack');
        });
    }

    public function test_update_avatar(): void
    {
        $conv = ConversationService::createGroup(
            $this->space['id'],
            $this->alice['id'],
            [$this->bob['id'], $this->charlie['id']]
        );

        $updated = ConversationService::updateAvatar($conv['id'], $this->alice['id'], 'https://example.com/avatar.png');
        $this->assertSame('https://example.com/avatar.png', $updated['avatar_url']);
    }

    // ── Group DM: member management ───────────

    public function test_add_member_to_group_dm(): void
    {
        $dave = $this->createUser(['display_name' => 'Dave']);
        $this->addSpaceMember($this->space['id'], $dave['id']);

        $conv = ConversationService::createGroup(
            $this->space['id'],
            $this->alice['id'],
            [$this->bob['id'], $this->charlie['id']]
        );

        $updated = ConversationService::addMember($conv['id'], $this->alice['id'], $dave['id']);
        $memberIds = array_column($updated['users'], 'id');
        $this->assertContains($dave['id'], $memberIds);
        $this->assertCount(4, $memberIds);

        // Verify domain event
        $events = Database::connection()->query(
            "SELECT * FROM domain_events WHERE event_type = 'conversation.member_added'"
        )->fetchAll(\PDO::FETCH_ASSOC);
        $this->assertCount(1, $events);
    }

    public function test_add_duplicate_member_is_idempotent(): void
    {
        $conv = ConversationService::createGroup(
            $this->space['id'],
            $this->alice['id'],
            [$this->bob['id'], $this->charlie['id']]
        );

        // Adding Bob again should not error
        $updated = ConversationService::addMember($conv['id'], $this->alice['id'], $this->bob['id']);
        $memberIds = array_column($updated['users'], 'id');
        $this->assertCount(3, $memberIds); // still 3
    }

    public function test_cannot_add_member_to_1_1_dm(): void
    {
        $conv = $this->createConversation(
            $this->space['id'],
            [$this->alice['id'], $this->bob['id']],
            false
        );

        $this->assertApiException(422, 'GROUP_ONLY', function () use ($conv) {
            ConversationService::addMember($conv['id'], $this->alice['id'], $this->charlie['id']);
        });
    }

    public function test_cannot_add_non_space_member(): void
    {
        $conv = ConversationService::createGroup(
            $this->space['id'],
            $this->alice['id'],
            [$this->bob['id'], $this->charlie['id']]
        );

        $this->assertApiException(403, 'SPACE_MEMBER_REQUIRED', function () use ($conv) {
            ConversationService::addMember($conv['id'], $this->alice['id'], $this->outsider['id']);
        });
    }

    public function test_non_member_cannot_add_member(): void
    {
        $dave = $this->createUser(['display_name' => 'Dave']);
        $this->addSpaceMember($this->space['id'], $dave['id']);

        $conv = ConversationService::createGroup(
            $this->space['id'],
            $this->alice['id'],
            [$this->bob['id'], $this->charlie['id']]
        );

        $this->assertApiException(403, 'CONVERSATION_ACCESS_DENIED', function () use ($conv, $dave) {
            ConversationService::addMember($conv['id'], $dave['id'], $dave['id']);
        });
    }

    // ── Group DM: remove member ───────────────

    public function test_creator_can_remove_member(): void
    {
        $conv = ConversationService::createGroup(
            $this->space['id'],
            $this->alice['id'],
            [$this->bob['id'], $this->charlie['id']]
        );

        $updated = ConversationService::removeMember($conv['id'], $this->alice['id'], $this->bob['id']);
        $memberIds = array_column($updated['users'], 'id');
        $this->assertNotContains($this->bob['id'], $memberIds);
        $this->assertCount(2, $memberIds);

        // Verify domain event
        $events = Database::connection()->query(
            "SELECT * FROM domain_events WHERE event_type = 'conversation.member_removed'"
        )->fetchAll(\PDO::FETCH_ASSOC);
        $this->assertCount(1, $events);
    }

    public function test_non_creator_cannot_remove_others(): void
    {
        $conv = ConversationService::createGroup(
            $this->space['id'],
            $this->alice['id'],
            [$this->bob['id'], $this->charlie['id']]
        );

        $this->assertApiException(403, 'CREATOR_ONLY', function () use ($conv) {
            ConversationService::removeMember($conv['id'], $this->bob['id'], $this->charlie['id']);
        });
    }

    public function test_member_can_leave_group_dm(): void
    {
        $conv = ConversationService::createGroup(
            $this->space['id'],
            $this->alice['id'],
            [$this->bob['id'], $this->charlie['id']]
        );

        $result = ConversationService::removeMember($conv['id'], $this->bob['id'], $this->bob['id']);
        $this->assertTrue($result['left']);
        $this->assertSame($conv['id'], $result['conversation_id']);

        // Bob can no longer see the conversation
        $this->assertApiException(403, 'CONVERSATION_ACCESS_DENIED', function () use ($conv) {
            ConversationService::show($conv['id'], $this->bob['id']);
        });
    }

    public function test_cannot_remove_if_only_2_members_remain(): void
    {
        $conv = $this->createConversation(
            $this->space['id'],
            [$this->alice['id'], $this->bob['id']],
            true,
            $this->alice['id']
        );

        $this->assertApiException(422, 'GROUP_TOO_SMALL', function () use ($conv) {
            ConversationService::removeMember($conv['id'], $this->alice['id'], $this->bob['id']);
        });
    }

    public function test_cannot_remove_from_1_1_dm(): void
    {
        $conv = $this->createConversation(
            $this->space['id'],
            [$this->alice['id'], $this->bob['id']],
            false
        );

        $this->assertApiException(422, 'GROUP_ONLY', function () use ($conv) {
            ConversationService::removeMember($conv['id'], $this->alice['id'], $this->bob['id']);
        });
    }

    // ── Group DM: new columns in responses ────

    public function test_show_returns_group_dm_fields(): void
    {
        $conv = ConversationService::createGroup(
            $this->space['id'],
            $this->alice['id'],
            [$this->bob['id'], $this->charlie['id']],
            'Team Chat'
        );

        $result = ConversationService::show($conv['id'], $this->alice['id']);
        $this->assertSame('Team Chat', $result['title']);
        $this->assertSame($this->alice['id'], (int) $result['created_by']);
        $this->assertArrayHasKey('avatar_url', $result);
    }

    public function test_list_returns_group_dm_fields(): void
    {
        ConversationService::createGroup(
            $this->space['id'],
            $this->alice['id'],
            [$this->bob['id'], $this->charlie['id']],
            'Gruppenname'
        );

        $conversations = ConversationService::listForUser($this->alice['id']);
        $this->assertCount(1, $conversations);
        $this->assertSame('Gruppenname', $conversations[0]['title']);
        $this->assertSame($this->alice['id'], (int) $conversations[0]['created_by']);
    }
}

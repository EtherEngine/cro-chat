<?php

declare(strict_types=1);

namespace Tests\Integration;

use App\Repositories\MentionRepository;
use App\Services\MentionService;
use App\Services\MessageService;
use App\Services\ThreadService;

final class MentionTest extends TestCase
{
    // ── Parsing ───────────────────────────────

    public function test_parseMentions_extracts_single_name(): void
    {
        $result = MentionService::parseMentions('Hey @Alice check this');
        $this->assertContains('Alice', $result);
    }

    public function test_parseMentions_extracts_multiple_names(): void
    {
        $result = MentionService::parseMentions('@Alice und @Bob bitte lesen');
        $this->assertContains('Alice', $result);
        $this->assertContains('Bob', $result);
    }

    public function test_parseMentions_deduplicates(): void
    {
        $result = MentionService::parseMentions('@Alice sagt @Alice');
        $this->assertCount(1, $result);
    }

    public function test_parseMentions_handles_no_mentions(): void
    {
        $result = MentionService::parseMentions('Keine Mentions hier');
        $this->assertEmpty($result);
    }

    public function test_parseMentions_at_start_of_string(): void
    {
        $result = MentionService::parseMentions('@Alice');
        $this->assertContains('Alice', $result);
    }

    public function test_parseMentions_ignores_email_like(): void
    {
        // Embedded in a word — should NOT match (no word boundary)
        $result = MentionService::parseMentions('email:test@Alice.com');
        $this->assertNotContains('Alice.com', $result);
    }

    // ── Resolve against permissions ───────────

    public function test_resolve_public_channel_matches_space_member(): void
    {
        $owner = $this->createUser(['display_name' => 'Alice']);
        $bob = $this->createUser(['display_name' => 'Bob']);
        $space = $this->createSpace($owner['id']);
        $this->addSpaceMember($space['id'], $bob['id']);
        $channel = $this->createChannel($space['id'], $owner['id']); // public by default

        $ids = MentionService::resolveMentions(['Bob'], $channel['id'], null);
        $this->assertContains($bob['id'], $ids);
    }

    public function test_resolve_public_channel_rejects_non_space_member(): void
    {
        $owner = $this->createUser(['display_name' => 'Alice']);
        $outsider = $this->createUser(['display_name' => 'Outsider']);
        $space = $this->createSpace($owner['id']);
        $channel = $this->createChannel($space['id'], $owner['id']);

        $ids = MentionService::resolveMentions(['Outsider'], $channel['id'], null);
        $this->assertEmpty($ids);
    }

    public function test_resolve_private_channel_only_members(): void
    {
        $owner = $this->createUser(['display_name' => 'Alice']);
        $member = $this->createUser(['display_name' => 'Bob']);
        $nonMember = $this->createUser(['display_name' => 'Charlie']);
        $space = $this->createSpace($owner['id']);
        $this->addSpaceMember($space['id'], $member['id']);
        $this->addSpaceMember($space['id'], $nonMember['id']);
        $channel = $this->createChannel($space['id'], $owner['id'], ['is_private' => 1]);
        $this->addChannelMember($channel['id'], $member['id']);

        $ids = MentionService::resolveMentions(['Bob', 'Charlie'], $channel['id'], null);
        $this->assertContains($member['id'], $ids);
        $this->assertNotContains($nonMember['id'], $ids);
    }

    public function test_resolve_conversation_only_participants(): void
    {
        $alice = $this->createUser(['display_name' => 'Alice']);
        $bob = $this->createUser(['display_name' => 'Bob']);
        $charlie = $this->createUser(['display_name' => 'Charlie']);
        $space = $this->createSpace($alice['id']);
        $this->addSpaceMember($space['id'], $bob['id']);
        $this->addSpaceMember($space['id'], $charlie['id']);
        $conv = $this->createConversation($space['id'], [$alice['id'], $bob['id']]);

        $ids = MentionService::resolveMentions(['Bob', 'Charlie'], null, $conv['id']);
        $this->assertContains($bob['id'], $ids);
        $this->assertNotContains($charlie['id'], $ids);
    }

    public function test_resolve_case_insensitive(): void
    {
        $owner = $this->createUser(['display_name' => 'Alice']);
        $bob = $this->createUser(['display_name' => 'Bob']);
        $space = $this->createSpace($owner['id']);
        $this->addSpaceMember($space['id'], $bob['id']);
        $channel = $this->createChannel($space['id'], $owner['id']);

        $ids = MentionService::resolveMentions(['bob'], $channel['id'], null);
        $this->assertContains($bob['id'], $ids);
    }

    // ── processMentions integration ───────────

    public function test_create_channel_message_stores_mentions(): void
    {
        $alice = $this->createUser(['display_name' => 'Alice']);
        $bob = $this->createUser(['display_name' => 'Bob']);
        $space = $this->createSpace($alice['id']);
        $this->addSpaceMember($space['id'], $bob['id']);
        $channel = $this->createChannel($space['id'], $alice['id']);
        $this->addChannelMember($channel['id'], $bob['id']);

        $this->actingAs($alice['id']);
        $msg = MessageService::createChannel($channel['id'], $alice['id'], [
            'body' => 'Hey @Bob schau dir das an',
        ]);

        $mentions = MentionRepository::forMessage($msg['id']);
        $this->assertCount(1, $mentions);
        $this->assertSame($bob['id'], $mentions[0]['user_id']);
    }

    public function test_create_channel_message_does_not_self_mention(): void
    {
        $alice = $this->createUser(['display_name' => 'Alice']);
        $space = $this->createSpace($alice['id']);
        $channel = $this->createChannel($space['id'], $alice['id']);

        $this->actingAs($alice['id']);
        $msg = MessageService::createChannel($channel['id'], $alice['id'], [
            'body' => 'Ich erwähne @Alice selbst',
        ]);

        $mentions = MentionRepository::forMessage($msg['id']);
        $this->assertEmpty($mentions);
    }

    public function test_create_conversation_message_stores_mentions(): void
    {
        $alice = $this->createUser(['display_name' => 'Alice']);
        $bob = $this->createUser(['display_name' => 'Bob']);
        $space = $this->createSpace($alice['id']);
        $this->addSpaceMember($space['id'], $bob['id']);
        $conv = $this->createConversation($space['id'], [$alice['id'], $bob['id']]);

        $this->actingAs($alice['id']);
        $msg = MessageService::createConversation($conv['id'], $alice['id'], [
            'body' => 'Hey @Bob',
        ]);

        $mentions = MentionRepository::forMessage($msg['id']);
        $this->assertCount(1, $mentions);
        $this->assertSame($bob['id'], $mentions[0]['user_id']);
    }

    public function test_edit_message_reprocesses_mentions(): void
    {
        $alice = $this->createUser(['display_name' => 'Alice']);
        $bob = $this->createUser(['display_name' => 'Bob']);
        $charlie = $this->createUser(['display_name' => 'Charlie']);
        $space = $this->createSpace($alice['id']);
        $this->addSpaceMember($space['id'], $bob['id']);
        $this->addSpaceMember($space['id'], $charlie['id']);
        $channel = $this->createChannel($space['id'], $alice['id']);
        $this->addChannelMember($channel['id'], $bob['id']);
        $this->addChannelMember($channel['id'], $charlie['id']);

        $this->actingAs($alice['id']);
        $msg = MessageService::createChannel($channel['id'], $alice['id'], [
            'body' => 'Hey @Bob',
        ]);

        $mentions = MentionRepository::forMessage($msg['id']);
        $this->assertCount(1, $mentions);
        $this->assertSame($bob['id'], $mentions[0]['user_id']);

        // Edit to mention Charlie instead
        $updated = MessageService::update($msg['id'], $alice['id'], [
            'body' => 'Hey @Charlie',
        ]);

        $mentions = MentionRepository::forMessage($msg['id']);
        $this->assertCount(1, $mentions);
        $this->assertSame($charlie['id'], $mentions[0]['user_id']);
    }

    // ── Thread mentions ───────────────────────

    public function test_thread_reply_stores_mentions(): void
    {
        $alice = $this->createUser(['display_name' => 'Alice']);
        $bob = $this->createUser(['display_name' => 'Bob']);
        $space = $this->createSpace($alice['id']);
        $this->addSpaceMember($space['id'], $bob['id']);
        $channel = $this->createChannel($space['id'], $alice['id']);
        $this->addChannelMember($channel['id'], $bob['id']);

        $rootMsg = $this->createMessage($alice['id'], $channel['id'], null, 'Root');
        $this->actingAs($alice['id']);

        $result = ThreadService::startThread($rootMsg['id'], $alice['id'], [
            'body' => 'Thread mit @Bob',
        ]);

        $mentions = MentionRepository::forMessage($result['message']['id']);
        $this->assertCount(1, $mentions);
        $this->assertSame($bob['id'], $mentions[0]['user_id']);
    }

    public function test_thread_createReply_stores_mentions(): void
    {
        $alice = $this->createUser(['display_name' => 'Alice']);
        $bob = $this->createUser(['display_name' => 'Bob']);
        $space = $this->createSpace($alice['id']);
        $this->addSpaceMember($space['id'], $bob['id']);
        $channel = $this->createChannel($space['id'], $alice['id']);
        $this->addChannelMember($channel['id'], $bob['id']);

        $rootMsg = $this->createMessage($alice['id'], $channel['id'], null, 'Root');
        $this->actingAs($bob['id']);

        // Start thread first
        $result = ThreadService::startThread($rootMsg['id'], $bob['id'], [
            'body' => 'Reply 1',
        ]);

        // Create second reply mentioning Alice
        $this->actingAs($bob['id']);
        $reply = ThreadService::createReply($result['thread']['id'], $bob['id'], [
            'body' => 'Hey @Alice hier im Thread',
        ]);

        $mentions = MentionRepository::forMessage($reply['id']);
        $this->assertCount(1, $mentions);
        $this->assertSame($alice['id'], $mentions[0]['user_id']);
    }

    // ── Hydration ─────────────────────────────

    public function test_mentions_hydrated_in_channel_feed(): void
    {
        $alice = $this->createUser(['display_name' => 'Alice']);
        $bob = $this->createUser(['display_name' => 'Bob']);
        $space = $this->createSpace($alice['id']);
        $this->addSpaceMember($space['id'], $bob['id']);
        $channel = $this->createChannel($space['id'], $alice['id']);
        $this->addChannelMember($channel['id'], $bob['id']);

        $this->actingAs($alice['id']);
        MessageService::createChannel($channel['id'], $alice['id'], [
            'body' => 'Hi @Bob',
        ]);

        $feed = MessageService::listChannel($channel['id'], $alice['id']);
        $msg = $feed['messages'][0];
        $this->assertArrayHasKey('mentions', $msg);
        $this->assertCount(1, $msg['mentions']);
        $this->assertSame($bob['id'], $msg['mentions'][0]['user_id']);
        $this->assertSame('Bob', $msg['mentions'][0]['display_name']);
    }

    public function test_message_without_mentions_has_empty_array(): void
    {
        $alice = $this->createUser(['display_name' => 'Alice']);
        $space = $this->createSpace($alice['id']);
        $channel = $this->createChannel($space['id'], $alice['id']);

        $this->actingAs($alice['id']);
        $msg = MessageService::createChannel($channel['id'], $alice['id'], [
            'body' => 'Keine Mentions',
        ]);

        $this->assertArrayHasKey('mentions', $msg);
        $this->assertEmpty($msg['mentions']);
    }

    // ── Autocomplete ──────────────────────────

    public function test_search_returns_matching_space_members(): void
    {
        $alice = $this->createUser(['display_name' => 'Alice']);
        $bob = $this->createUser(['display_name' => 'Bob']);
        $charlie = $this->createUser(['display_name' => 'Charlie']);
        $space = $this->createSpace($alice['id']);
        $this->addSpaceMember($space['id'], $bob['id']);
        $this->addSpaceMember($space['id'], $charlie['id']);

        $results = MentionService::searchUsers('Bo', $space['id'], null, null);
        $this->assertCount(1, $results);
        $this->assertSame($bob['id'], $results[0]['id']);
    }

    public function test_search_private_channel_only_channel_members(): void
    {
        $alice = $this->createUser(['display_name' => 'Alice']);
        $bob = $this->createUser(['display_name' => 'Bob']);
        $charlie = $this->createUser(['display_name' => 'Charlie']);
        $space = $this->createSpace($alice['id']);
        $this->addSpaceMember($space['id'], $bob['id']);
        $this->addSpaceMember($space['id'], $charlie['id']);
        $channel = $this->createChannel($space['id'], $alice['id'], ['is_private' => 1]);
        $this->addChannelMember($channel['id'], $bob['id']);

        // Bob is channel member, should appear
        $results = MentionService::searchUsers('B', $space['id'], $channel['id'], null);
        $names = array_column($results, 'display_name');
        $this->assertContains('Bob', $names);

        // Charlie is not channel member, should not appear
        $results = MentionService::searchUsers('Cha', $space['id'], $channel['id'], null);
        $this->assertEmpty($results);
    }

    public function test_search_conversation_only_participants(): void
    {
        $alice = $this->createUser(['display_name' => 'Alice']);
        $bob = $this->createUser(['display_name' => 'Bob']);
        $charlie = $this->createUser(['display_name' => 'Charlie']);
        $space = $this->createSpace($alice['id']);
        $this->addSpaceMember($space['id'], $bob['id']);
        $this->addSpaceMember($space['id'], $charlie['id']);
        $conv = $this->createConversation($space['id'], [$alice['id'], $bob['id']]);

        $results = MentionService::searchUsers('B', $space['id'], null, $conv['id']);
        $this->assertCount(1, $results);
        $this->assertSame($bob['id'], $results[0]['id']);

        // Charlie not in conversation
        $results = MentionService::searchUsers('Cha', $space['id'], null, $conv['id']);
        $this->assertEmpty($results);
    }

    // ── MentionRepository batch ───────────────

    public function test_forMessages_batch_loads(): void
    {
        $alice = $this->createUser(['display_name' => 'Alice']);
        $bob = $this->createUser(['display_name' => 'Bob']);
        $space = $this->createSpace($alice['id']);
        $this->addSpaceMember($space['id'], $bob['id']);
        $channel = $this->createChannel($space['id'], $alice['id']);
        $this->addChannelMember($channel['id'], $bob['id']);

        $msg1 = $this->createMessage($alice['id'], $channel['id'], null, 'Msg 1');
        $msg2 = $this->createMessage($alice['id'], $channel['id'], null, 'Msg 2');

        MentionRepository::store($msg1['id'], [$bob['id']]);
        MentionRepository::store($msg2['id'], [$bob['id']]);

        $map = MentionRepository::forMessages([$msg1['id'], $msg2['id']]);
        $this->assertArrayHasKey($msg1['id'], $map);
        $this->assertArrayHasKey($msg2['id'], $map);
        $this->assertCount(1, $map[$msg1['id']]);
        $this->assertCount(1, $map[$msg2['id']]);
    }

    public function test_forMessages_empty_returns_empty(): void
    {
        $map = MentionRepository::forMessages([]);
        $this->assertEmpty($map);
    }

    public function test_deleteForMessage_removes_mentions(): void
    {
        $alice = $this->createUser(['display_name' => 'Alice']);
        $bob = $this->createUser(['display_name' => 'Bob']);
        $space = $this->createSpace($alice['id']);
        $channel = $this->createChannel($space['id'], $alice['id']);

        $msg = $this->createMessage($alice['id'], $channel['id'], null, 'Test');
        MentionRepository::store($msg['id'], [$bob['id']]);
        $this->assertCount(1, MentionRepository::forMessage($msg['id']));

        MentionRepository::deleteForMessage($msg['id']);
        $this->assertEmpty(MentionRepository::forMessage($msg['id']));
    }

    // ── Domain events ─────────────────────────

    public function test_mention_created_event_published(): void
    {
        $alice = $this->createUser(['display_name' => 'Alice']);
        $bob = $this->createUser(['display_name' => 'Bob']);
        $space = $this->createSpace($alice['id']);
        $this->addSpaceMember($space['id'], $bob['id']);
        $channel = $this->createChannel($space['id'], $alice['id']);
        $this->addChannelMember($channel['id'], $bob['id']);

        $this->actingAs($alice['id']);
        MessageService::createChannel($channel['id'], $alice['id'], [
            'body' => 'Hey @Bob',
        ]);

        $db = \App\Support\Database::connection();
        $events = $db->query(
            "SELECT event_type, payload FROM domain_events WHERE event_type = 'mention.created'"
        )->fetchAll();

        $this->assertCount(1, $events);
        $payload = json_decode($events[0]['payload'], true);
        $this->assertSame($bob['id'], $payload['mentioned_user_id']);
        $this->assertSame($alice['id'], $payload['author_id']);
    }
}

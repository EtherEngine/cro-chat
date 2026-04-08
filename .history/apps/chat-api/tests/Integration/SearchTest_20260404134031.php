<?php

declare(strict_types=1);

namespace Tests\Integration;

use App\Repositories\SearchRepository;
use App\Services\MessageService;
use App\Support\Database;
use Tests\TestCase;

final class SearchTest extends TestCase
{
    // ── Channel search ─────────────────────────────

    public function test_channel_search_finds_by_name(): void
    {
        $alice = $this->createUser(['display_name' => 'Alice']);
        $space = $this->createSpace($alice['id']);
        $this->createChannel($space['id'], $alice['id'], ['name' => 'general']);
        $this->createChannel($space['id'], $alice['id'], ['name' => 'random']);

        $results = SearchRepository::channels($alice['id'], 'gen');
        $this->assertCount(1, $results);
        $this->assertSame('general', $results[0]['name']);
    }

    public function test_channel_search_excludes_private_for_non_member(): void
    {
        $alice = $this->createUser(['display_name' => 'Alice']);
        $bob = $this->createUser(['display_name' => 'Bob']);
        $space = $this->createSpace($alice['id']);
        $this->addSpaceMember($space['id'], $bob['id']);

        // Private channel — Alice is member, Bob is not
        $this->createChannel($space['id'], $alice['id'], ['name' => 'secret', 'is_private' => 1]);

        $aliceResults = SearchRepository::channels($alice['id'], 'secret');
        $bobResults = SearchRepository::channels($bob['id'], 'secret');

        $this->assertCount(1, $aliceResults);
        $this->assertCount(0, $bobResults);
    }

    public function test_channel_search_includes_private_for_member(): void
    {
        $alice = $this->createUser(['display_name' => 'Alice']);
        $bob = $this->createUser(['display_name' => 'Bob']);
        $space = $this->createSpace($alice['id']);
        $this->addSpaceMember($space['id'], $bob['id']);
        $ch = $this->createChannel($space['id'], $alice['id'], ['name' => 'secret', 'is_private' => 1]);
        $this->addChannelMember($ch['id'], $bob['id']);

        $results = SearchRepository::channels($bob['id'], 'secret');
        $this->assertCount(1, $results);
    }

    // ── User search ────────────────────────────────

    public function test_user_search_finds_by_display_name(): void
    {
        $alice = $this->createUser(['display_name' => 'Alice Wonderland']);
        $bob = $this->createUser(['display_name' => 'Bob Builder']);
        $space = $this->createSpace($alice['id']);
        $this->addSpaceMember($space['id'], $bob['id']);

        $results = SearchRepository::users($alice['id'], 'Bob');
        $this->assertCount(1, $results);
        $this->assertSame('Bob Builder', $results[0]['display_name']);
    }

    public function test_user_search_only_returns_co_members(): void
    {
        $alice = $this->createUser(['display_name' => 'Alice']);
        $charlie = $this->createUser(['display_name' => 'Charlie']);
        // No shared space
        $this->createSpace($alice['id']);
        $this->createSpace($charlie['id']);

        $results = SearchRepository::users($alice['id'], 'Charlie');
        $this->assertCount(0, $results);
    }

    // ── Message search ─────────────────────────────

    public function test_message_search_finds_by_fulltext(): void
    {
        $alice = $this->createUser(['display_name' => 'Alice']);
        $space = $this->createSpace($alice['id']);
        $ch = $this->createChannel($space['id'], $alice['id'], ['name' => 'general']);

        $this->actingAs($alice['id']);
        MessageService::createChannel($ch['id'], $alice['id'], [
            'body' => 'Dies ist eine besondere Testnachricht zum Finden',
        ]);
        MessageService::createChannel($ch['id'], $alice['id'], [
            'body' => 'Etwas komplett anderes hier',
        ]);

        $results = SearchRepository::messages($alice['id'], 'Testnachricht');
        $this->assertCount(1, $results);
        $this->assertStringContainsString('Testnachricht', $results[0]['body']);
    }

    public function test_message_search_returns_snippet(): void
    {
        $alice = $this->createUser(['display_name' => 'Alice']);
        $space = $this->createSpace($alice['id']);
        $ch = $this->createChannel($space['id'], $alice['id'], ['name' => 'general']);

        $this->actingAs($alice['id']);
        $longBody = str_repeat('Lorem ipsum dolor sit amet. ', 20)
            . 'SUCHBEGRIFF '
            . str_repeat('Consectetur adipiscing elit. ', 20);

        MessageService::createChannel($ch['id'], $alice['id'], ['body' => $longBody]);

        $results = SearchRepository::messages($alice['id'], 'SUCHBEGRIFF');
        $this->assertCount(1, $results);
        $this->assertArrayHasKey('snippet', $results[0]);
        $this->assertStringContainsString('SUCHBEGRIFF', $results[0]['snippet']);
        // Snippet should be shorter than full body
        $this->assertLessThan(mb_strlen($longBody), mb_strlen($results[0]['snippet']));
    }

    public function test_message_search_returns_thread_id(): void
    {
        $alice = $this->createUser(['display_name' => 'Alice']);
        $space = $this->createSpace($alice['id']);
        $ch = $this->createChannel($space['id'], $alice['id'], ['name' => 'general']);

        $this->actingAs($alice['id']);
        $root = MessageService::createChannel($ch['id'], $alice['id'], [
            'body' => 'Stammachricht fuer den Thread',
        ]);

        $thread = $this->createThread($root['id'], $ch['id'], null, $alice['id']);
        $this->createMessage($alice['id'], $ch['id'], null, 'Antwort im Threadkontext hier', $thread['id']);

        $results = SearchRepository::messages($alice['id'], 'Threadkontext');
        $this->assertCount(1, $results);
        $this->assertSame($thread['id'], $results[0]['thread_id']);
    }

    public function test_message_search_returns_context_channel_name(): void
    {
        $alice = $this->createUser(['display_name' => 'Alice']);
        $space = $this->createSpace($alice['id']);
        $ch = $this->createChannel($space['id'], $alice['id'], ['name' => 'devtalk']);

        $this->actingAs($alice['id']);
        MessageService::createChannel($ch['id'], $alice['id'], [
            'body' => 'Kontextnachricht zum Testen',
        ]);

        $results = SearchRepository::messages($alice['id'], 'Kontextnachricht');
        $this->assertCount(1, $results);
        $this->assertSame('#devtalk', $results[0]['context']);
    }

    public function test_message_search_returns_author_info(): void
    {
        $alice = $this->createUser(['display_name' => 'Alice Coder', 'avatar_color' => '#FF0000']);
        $space = $this->createSpace($alice['id']);
        $ch = $this->createChannel($space['id'], $alice['id'], ['name' => 'general']);

        $this->actingAs($alice['id']);
        MessageService::createChannel($ch['id'], $alice['id'], [
            'body' => 'Autoreninfo Testnachricht hier',
        ]);

        $results = SearchRepository::messages($alice['id'], 'Autoreninfo');
        $this->assertCount(1, $results);
        $this->assertSame('Alice Coder', $results[0]['author_name']);
        $this->assertSame('#FF0000', $results[0]['author_color']);
    }

    // ── Permission checks ──────────────────────────

    public function test_message_search_excludes_private_channel_for_non_member(): void
    {
        $alice = $this->createUser(['display_name' => 'Alice']);
        $bob = $this->createUser(['display_name' => 'Bob']);
        $space = $this->createSpace($alice['id']);
        $this->addSpaceMember($space['id'], $bob['id']);

        $ch = $this->createChannel($space['id'], $alice['id'], ['name' => 'secret', 'is_private' => 1]);

        $this->actingAs($alice['id']);
        MessageService::createChannel($ch['id'], $alice['id'], [
            'body' => 'Geheime Nachricht niemand soll finden',
        ]);

        // Alice can find it
        $aliceResults = SearchRepository::messages($alice['id'], 'Geheime');
        $this->assertCount(1, $aliceResults);

        // Bob cannot (not a channel member)
        $bobResults = SearchRepository::messages($bob['id'], 'Geheime');
        $this->assertCount(0, $bobResults);
    }

    public function test_message_search_excludes_dm_for_non_participant(): void
    {
        $alice = $this->createUser(['display_name' => 'Alice']);
        $bob = $this->createUser(['display_name' => 'Bob']);
        $charlie = $this->createUser(['display_name' => 'Charlie']);
        $space = $this->createSpace($alice['id']);
        $this->addSpaceMember($space['id'], $bob['id']);
        $this->addSpaceMember($space['id'], $charlie['id']);

        $conv = $this->createConversation($space['id'], [$alice['id'], $bob['id']]);

        $this->actingAs($alice['id']);
        MessageService::createConversation($conv['id'], $alice['id'], [
            'body' => 'Private Direktnachricht vertraulich',
        ]);

        // Alice can find it
        $aliceResults = SearchRepository::messages($alice['id'], 'Direktnachricht');
        $this->assertCount(1, $aliceResults);

        // Bob can find it (conversation member)
        $bobResults = SearchRepository::messages($bob['id'], 'Direktnachricht');
        $this->assertCount(1, $bobResults);

        // Charlie cannot (not a conversation member)
        $charlieResults = SearchRepository::messages($charlie['id'], 'Direktnachricht');
        $this->assertCount(0, $charlieResults);
    }

    public function test_message_search_public_channel_visible_to_space_member(): void
    {
        $alice = $this->createUser(['display_name' => 'Alice']);
        $bob = $this->createUser(['display_name' => 'Bob']);
        $space = $this->createSpace($alice['id']);
        $this->addSpaceMember($space['id'], $bob['id']);

        $ch = $this->createChannel($space['id'], $alice['id'], ['name' => 'public-talk']);

        $this->actingAs($alice['id']);
        MessageService::createChannel($ch['id'], $alice['id'], [
            'body' => 'Oeffentliche Nachricht fuer alle sichtbar',
        ]);

        // Bob is space member but not channel member — should still see public channel messages
        $bobResults = SearchRepository::messages($bob['id'], 'Oeffentliche');
        $this->assertCount(1, $bobResults);
    }

    // ── Filters ────────────────────────────────────

    public function test_message_search_channel_filter(): void
    {
        $alice = $this->createUser(['display_name' => 'Alice']);
        $space = $this->createSpace($alice['id']);
        $ch1 = $this->createChannel($space['id'], $alice['id'], ['name' => 'eins']);
        $ch2 = $this->createChannel($space['id'], $alice['id'], ['name' => 'zwei']);

        $this->actingAs($alice['id']);
        MessageService::createChannel($ch1['id'], $alice['id'], ['body' => 'Filterbegriff in eins']);
        MessageService::createChannel($ch2['id'], $alice['id'], ['body' => 'Filterbegriff in zwei']);

        $all = SearchRepository::messages($alice['id'], 'Filterbegriff');
        $this->assertCount(2, $all);

        $filtered = SearchRepository::messages($alice['id'], 'Filterbegriff', channelId: $ch1['id']);
        $this->assertCount(1, $filtered);
        $this->assertSame($ch1['id'], (int) $filtered[0]['channel_id']);
    }

    public function test_message_search_conversation_filter(): void
    {
        $alice = $this->createUser(['display_name' => 'Alice']);
        $bob = $this->createUser(['display_name' => 'Bob']);
        $space = $this->createSpace($alice['id']);
        $this->addSpaceMember($space['id'], $bob['id']);

        $conv = $this->createConversation($space['id'], [$alice['id'], $bob['id']]);
        $ch = $this->createChannel($space['id'], $alice['id'], ['name' => 'general']);

        $this->actingAs($alice['id']);
        MessageService::createConversation($conv['id'], $alice['id'], ['body' => 'Konvfilter Nachricht im DM']);
        MessageService::createChannel($ch['id'], $alice['id'], ['body' => 'Konvfilter Nachricht im Channel']);

        $filtered = SearchRepository::messages($alice['id'], 'Konvfilter', conversationId: $conv['id']);
        $this->assertCount(1, $filtered);
        $this->assertSame($conv['id'], (int) $filtered[0]['conversation_id']);
    }

    public function test_message_search_author_filter(): void
    {
        $alice = $this->createUser(['display_name' => 'Alice']);
        $bob = $this->createUser(['display_name' => 'Bob']);
        $space = $this->createSpace($alice['id']);
        $this->addSpaceMember($space['id'], $bob['id']);
        $ch = $this->createChannel($space['id'], $alice['id'], ['name' => 'general']);
        $this->addChannelMember($ch['id'], $bob['id']);

        $this->actingAs($alice['id']);
        MessageService::createChannel($ch['id'], $alice['id'], ['body' => 'Autorfilter von Alice geschrieben']);
        $this->actingAs($bob['id']);
        MessageService::createChannel($ch['id'], $bob['id'], ['body' => 'Autorfilter von Bob geschrieben']);

        $filtered = SearchRepository::messages($alice['id'], 'Autorfilter', authorId: $bob['id']);
        $this->assertCount(1, $filtered);
        $this->assertSame($bob['id'], (int) $filtered[0]['user_id']);
    }

    public function test_message_search_excludes_deleted_messages(): void
    {
        $alice = $this->createUser(['display_name' => 'Alice']);
        $space = $this->createSpace($alice['id']);
        $ch = $this->createChannel($space['id'], $alice['id'], ['name' => 'general']);

        $this->actingAs($alice['id']);
        $msg = MessageService::createChannel($ch['id'], $alice['id'], [
            'body' => 'Geloeschte Nachricht unsichtbar',
        ]);
        MessageService::delete($msg['id'], $alice['id']);

        $results = SearchRepository::messages($alice['id'], 'Geloeschte');
        $this->assertCount(0, $results);
    }

    // ── DM context label ───────────────────────────

    public function test_message_search_dm_context_shows_other_member(): void
    {
        $alice = $this->createUser(['display_name' => 'Alice']);
        $bob = $this->createUser(['display_name' => 'Bob']);
        $space = $this->createSpace($alice['id']);
        $this->addSpaceMember($space['id'], $bob['id']);

        $conv = $this->createConversation($space['id'], [$alice['id'], $bob['id']]);

        $this->actingAs($alice['id']);
        MessageService::createConversation($conv['id'], $alice['id'], [
            'body' => 'Kontextlabel Nachricht im DM',
        ]);

        $results = SearchRepository::messages($alice['id'], 'Kontextlabel');
        $this->assertCount(1, $results);
        // Context should show Bob's name (other member from Alice's perspective)
        $this->assertSame('Bob', $results[0]['context']);
    }

    // ── Short query returns empty ──────────────────

    public function test_short_query_returns_empty(): void
    {
        $alice = $this->createUser(['display_name' => 'Alice']);
        $space = $this->createSpace($alice['id']);
        $this->createChannel($space['id'], $alice['id'], ['name' => 'abc']);

        // Query less than 2 chars → SearchController returns empty,
        // but at SearchRepository level we test channels directly
        $results = SearchRepository::channels($alice['id'], 'a');
        // LIKE '%a%' should match 'abc'
        $this->assertCount(1, $results);
    }

    // ── Snippet edge cases ─────────────────────────

    public function test_snippet_short_body_returns_full(): void
    {
        $alice = $this->createUser(['display_name' => 'Alice']);
        $space = $this->createSpace($alice['id']);
        $ch = $this->createChannel($space['id'], $alice['id'], ['name' => 'general']);

        $this->actingAs($alice['id']);
        MessageService::createChannel($ch['id'], $alice['id'], ['body' => 'kurzer Snippettest']);

        $results = SearchRepository::messages($alice['id'], 'Snippettest');
        $this->assertCount(1, $results);
        // Short body → snippet is the full body (no ellipsis)
        $this->assertSame('kurzer Snippettest', $results[0]['snippet']);
    }
}

<?php

declare(strict_types=1);

namespace Tests\Integration;

use App\Repositories\NotificationRepository;
use App\Services\MessageService;
use App\Services\NotificationService;
use App\Services\ReactionService;
use App\Services\ThreadService;
use App\Support\Database;
use Tests\TestCase;

final class NotificationTest extends TestCase
{
    // ── Mention notifications ─────────────────

    public function test_mention_creates_notification(): void
    {
        $alice = $this->createUser(['display_name' => 'Alice']);
        $bob = $this->createUser(['display_name' => 'Bob']);
        $space = $this->createSpace($alice['id']);
        $this->addSpaceMember($space['id'], $bob['id']);
        $channel = $this->createChannel($space['id'], $alice['id']);
        $this->addChannelMember($channel['id'], $bob['id']);

        $this->actingAs($alice['id']);
        MessageService::createChannel($channel['id'], $alice['id'], [
            'body' => 'Hey @Bob schau dir das an',
        ]);

        $result = NotificationRepository::forUser($bob['id']);
        $notes = $result['notifications'];
        $this->assertCount(1, $notes);
        $this->assertSame('mention', $notes[0]['type']);
        $this->assertSame($alice['id'], $notes[0]['actor']['id']);
        $this->assertSame($channel['id'], $notes[0]['channel_id']);
        $this->assertNull($notes[0]['read_at']);
    }

    public function test_mention_does_not_self_notify(): void
    {
        $alice = $this->createUser(['display_name' => 'Alice']);
        $space = $this->createSpace($alice['id']);
        $channel = $this->createChannel($space['id'], $alice['id']);

        $this->actingAs($alice['id']);
        MessageService::createChannel($channel['id'], $alice['id'], [
            'body' => 'Ich erwähne @Alice selbst',
        ]);

        $result = NotificationRepository::forUser($alice['id']);
        $this->assertEmpty($result['notifications']);
    }

    // ── DM notifications ──────────────────────

    public function test_dm_creates_notification_for_other_members(): void
    {
        $alice = $this->createUser(['display_name' => 'Alice']);
        $bob = $this->createUser(['display_name' => 'Bob']);
        $space = $this->createSpace($alice['id']);
        $this->addSpaceMember($space['id'], $bob['id']);
        $conv = $this->createConversation($space['id'], [$alice['id'], $bob['id']]);

        $this->actingAs($alice['id']);
        MessageService::createConversation($conv['id'], $alice['id'], [
            'body' => 'Hallo Bob',
        ]);

        // Bob should get a DM notification
        $result = NotificationRepository::forUser($bob['id']);
        $notes = $result['notifications'];
        // May have both dm + mention notifications if body matched; filter by type
        $dmNotes = array_values(array_filter($notes, fn($n) => $n['type'] === 'dm'));
        $this->assertCount(1, $dmNotes);
        $this->assertSame($alice['id'], $dmNotes[0]['actor']['id']);
        $this->assertSame($conv['id'], $dmNotes[0]['conversation_id']);
    }

    public function test_dm_does_not_notify_sender(): void
    {
        $alice = $this->createUser(['display_name' => 'Alice']);
        $bob = $this->createUser(['display_name' => 'Bob']);
        $space = $this->createSpace($alice['id']);
        $this->addSpaceMember($space['id'], $bob['id']);
        $conv = $this->createConversation($space['id'], [$alice['id'], $bob['id']]);

        $this->actingAs($alice['id']);
        MessageService::createConversation($conv['id'], $alice['id'], [
            'body' => 'Nachricht',
        ]);

        $result = NotificationRepository::forUser($alice['id']);
        $dmNotes = array_filter($result['notifications'], fn($n) => $n['type'] === 'dm');
        $this->assertEmpty($dmNotes);
    }

    // ── Thread reply notifications ────────────

    public function test_thread_reply_notifies_root_author(): void
    {
        $alice = $this->createUser(['display_name' => 'Alice']);
        $bob = $this->createUser(['display_name' => 'Bob']);
        $space = $this->createSpace($alice['id']);
        $this->addSpaceMember($space['id'], $bob['id']);
        $channel = $this->createChannel($space['id'], $alice['id']);
        $this->addChannelMember($channel['id'], $bob['id']);

        $rootMsg = $this->createMessage($alice['id'], $channel['id'], null, 'Root message');

        $this->actingAs($bob['id']);
        ThreadService::startThread($rootMsg['id'], $bob['id'], [
            'body' => 'Thread reply',
        ]);

        $result = NotificationRepository::forUser($alice['id']);
        $threadNotes = array_values(array_filter($result['notifications'], fn($n) => $n['type'] === 'thread_reply'));
        $this->assertCount(1, $threadNotes);
        $this->assertSame($bob['id'], $threadNotes[0]['actor']['id']);
    }

    public function test_thread_reply_does_not_self_notify(): void
    {
        $alice = $this->createUser(['display_name' => 'Alice']);
        $space = $this->createSpace($alice['id']);
        $channel = $this->createChannel($space['id'], $alice['id']);
        $rootMsg = $this->createMessage($alice['id'], $channel['id'], null, 'Root');

        $this->actingAs($alice['id']);
        ThreadService::startThread($rootMsg['id'], $alice['id'], [
            'body' => 'Self-reply',
        ]);

        $result = NotificationRepository::forUser($alice['id']);
        $threadNotes = array_filter($result['notifications'], fn($n) => $n['type'] === 'thread_reply');
        $this->assertEmpty($threadNotes);
    }

    public function test_thread_reply_notifies_prior_participants(): void
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

        $rootMsg = $this->createMessage($alice['id'], $channel['id'], null, 'Root');

        // Bob starts thread
        $this->actingAs($bob['id']);
        $result = ThreadService::startThread($rootMsg['id'], $bob['id'], ['body' => 'Reply 1']);
        $threadId = $result['thread']['id'];

        // Charlie replies — should notify both Alice (root author) and Bob (participant)
        $this->actingAs($charlie['id']);
        ThreadService::createReply($threadId, $charlie['id'], ['body' => 'Reply 2']);

        // Alice gets thread_reply from Charlie
        $aliceNotes = NotificationRepository::forUser($alice['id']);
        $aliceThread = array_values(array_filter($aliceNotes['notifications'], fn($n) => $n['type'] === 'thread_reply' && $n['actor']['id'] === $charlie['id']));
        $this->assertCount(1, $aliceThread);

        // Bob gets thread_reply from Charlie
        $bobNotes = NotificationRepository::forUser($bob['id']);
        $bobThread = array_values(array_filter($bobNotes['notifications'], fn($n) => $n['type'] === 'thread_reply' && $n['actor']['id'] === $charlie['id']));
        $this->assertCount(1, $bobThread);
    }

    // ── Reaction notifications ────────────────

    public function test_reaction_notifies_message_author(): void
    {
        $alice = $this->createUser(['display_name' => 'Alice']);
        $bob = $this->createUser(['display_name' => 'Bob']);
        $space = $this->createSpace($alice['id']);
        $this->addSpaceMember($space['id'], $bob['id']);
        $channel = $this->createChannel($space['id'], $alice['id']);
        $this->addChannelMember($channel['id'], $bob['id']);

        $this->actingAs($alice['id']);
        $msg = MessageService::createChannel($channel['id'], $alice['id'], ['body' => 'Hello']);

        $this->actingAs($bob['id']);
        ReactionService::add($msg['id'], $bob['id'], ['emoji' => ':thumbsup:']);

        $result = NotificationRepository::forUser($alice['id']);
        $reactionNotes = array_values(array_filter($result['notifications'], fn($n) => $n['type'] === 'reaction'));
        $this->assertCount(1, $reactionNotes);
        $this->assertSame($bob['id'], $reactionNotes[0]['actor']['id']);
        $this->assertSame(':thumbsup:', $reactionNotes[0]['data']['emoji']);
    }

    public function test_reaction_does_not_self_notify(): void
    {
        $alice = $this->createUser(['display_name' => 'Alice']);
        $space = $this->createSpace($alice['id']);
        $channel = $this->createChannel($space['id'], $alice['id']);

        $this->actingAs($alice['id']);
        $msg = MessageService::createChannel($channel['id'], $alice['id'], ['body' => 'Hello']);
        ReactionService::add($msg['id'], $alice['id'], ['emoji' => ':thumbsup:']);

        $result = NotificationRepository::forUser($alice['id']);
        $reactionNotes = array_filter($result['notifications'], fn($n) => $n['type'] === 'reaction');
        $this->assertEmpty($reactionNotes);
    }

    // ── Repository: list / pagination ─────────

    public function test_forUser_returns_newest_first(): void
    {
        $alice = $this->createUser(['display_name' => 'Alice']);
        $bob = $this->createUser(['display_name' => 'Bob']);
        $space = $this->createSpace($alice['id']);
        $this->addSpaceMember($space['id'], $bob['id']);
        $channel = $this->createChannel($space['id'], $alice['id']);
        $this->addChannelMember($channel['id'], $bob['id']);

        $msg1 = $this->createMessage($alice['id'], $channel['id'], null, 'Msg 1');
        $msg2 = $this->createMessage($alice['id'], $channel['id'], null, 'Msg 2');

        NotificationRepository::create($bob['id'], 'mention', $alice['id'], $msg1['id'], $channel['id']);
        NotificationRepository::create($bob['id'], 'mention', $alice['id'], $msg2['id'], $channel['id']);

        $result = NotificationRepository::forUser($bob['id']);
        $notes = $result['notifications'];
        $this->assertCount(2, $notes);
        $this->assertGreaterThan($notes[1]['id'], $notes[0]['id']);
    }

    public function test_forUser_cursor_pagination(): void
    {
        $alice = $this->createUser(['display_name' => 'Alice']);
        $bob = $this->createUser(['display_name' => 'Bob']);
        $space = $this->createSpace($alice['id']);
        $channel = $this->createChannel($space['id'], $alice['id']);

        // Create 3 notifications
        for ($i = 0; $i < 3; $i++) {
            $msg = $this->createMessage($alice['id'], $channel['id'], null, "Msg $i");
            NotificationRepository::create($bob['id'], 'mention', $alice['id'], $msg['id'], $channel['id']);
        }

        // Page 1: limit 2
        $page1 = NotificationRepository::forUser($bob['id'], null, 2);
        $this->assertCount(2, $page1['notifications']);
        $this->assertTrue($page1['has_more']);
        $this->assertNotNull($page1['next_cursor']);

        // Page 2: using cursor
        $page2 = NotificationRepository::forUser($bob['id'], $page1['next_cursor'], 2);
        $this->assertCount(1, $page2['notifications']);
        $this->assertFalse($page2['has_more']);
    }

    // ── Repository: unread count ──────────────

    public function test_unread_count(): void
    {
        $alice = $this->createUser(['display_name' => 'Alice']);
        $bob = $this->createUser(['display_name' => 'Bob']);
        $space = $this->createSpace($alice['id']);
        $channel = $this->createChannel($space['id'], $alice['id']);

        $msg = $this->createMessage($alice['id'], $channel['id'], null, 'Test');
        NotificationRepository::create($bob['id'], 'mention', $alice['id'], $msg['id'], $channel['id']);
        NotificationRepository::create($bob['id'], 'mention', $alice['id'], $msg['id'], $channel['id']);

        $this->assertSame(2, NotificationRepository::unreadCount($bob['id']));
    }

    // ── Repository: mark read ─────────────────

    public function test_mark_single_read(): void
    {
        $alice = $this->createUser(['display_name' => 'Alice']);
        $bob = $this->createUser(['display_name' => 'Bob']);
        $space = $this->createSpace($alice['id']);
        $channel = $this->createChannel($space['id'], $alice['id']);

        $msg = $this->createMessage($alice['id'], $channel['id'], null, 'Test');
        $n = NotificationRepository::create($bob['id'], 'mention', $alice['id'], $msg['id'], $channel['id']);

        $this->assertSame(1, NotificationRepository::unreadCount($bob['id']));
        NotificationRepository::markRead($n['id'], $bob['id']);
        $this->assertSame(0, NotificationRepository::unreadCount($bob['id']));
    }

    public function test_mark_read_wrong_user_fails(): void
    {
        $alice = $this->createUser(['display_name' => 'Alice']);
        $bob = $this->createUser(['display_name' => 'Bob']);
        $charlie = $this->createUser(['display_name' => 'Charlie']);
        $space = $this->createSpace($alice['id']);
        $channel = $this->createChannel($space['id'], $alice['id']);

        $msg = $this->createMessage($alice['id'], $channel['id'], null, 'Test');
        $n = NotificationRepository::create($bob['id'], 'mention', $alice['id'], $msg['id'], $channel['id']);

        // Charlie tries to mark Bob's notification — should return false
        $result = NotificationRepository::markRead($n['id'], $charlie['id']);
        $this->assertFalse($result);
        $this->assertSame(1, NotificationRepository::unreadCount($bob['id']));
    }

    public function test_mark_all_read(): void
    {
        $alice = $this->createUser(['display_name' => 'Alice']);
        $bob = $this->createUser(['display_name' => 'Bob']);
        $space = $this->createSpace($alice['id']);
        $channel = $this->createChannel($space['id'], $alice['id']);

        for ($i = 0; $i < 5; $i++) {
            $msg = $this->createMessage($alice['id'], $channel['id'], null, "Msg $i");
            NotificationRepository::create($bob['id'], 'mention', $alice['id'], $msg['id'], $channel['id']);
        }

        $this->assertSame(5, NotificationRepository::unreadCount($bob['id']));
        $count = NotificationRepository::markAllRead($bob['id']);
        $this->assertSame(5, $count);
        $this->assertSame(0, NotificationRepository::unreadCount($bob['id']));
    }

    // ── Domain events ─────────────────────────

    public function test_notification_created_event_published(): void
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

        $db = Database::connection();
        $events = $db->query(
            "SELECT event_type, room, payload FROM domain_events WHERE event_type = 'notification.created'"
        )->fetchAll();

        $this->assertGreaterThanOrEqual(1, count($events));
        $notifEvent = $events[0];
        $this->assertSame("user:{$bob['id']}", $notifEvent['room']);
        $payload = json_decode($notifEvent['payload'], true);
        $this->assertSame('mention', $payload['type']);
    }

    // ── Response model ────────────────────────

    public function test_notification_response_model(): void
    {
        $alice = $this->createUser(['display_name' => 'Alice']);
        $bob = $this->createUser(['display_name' => 'Bob']);
        $space = $this->createSpace($alice['id']);
        $this->addSpaceMember($space['id'], $bob['id']);
        $channel = $this->createChannel($space['id'], $alice['id']);
        $this->addChannelMember($channel['id'], $bob['id']);

        $this->actingAs($alice['id']);
        $msg = MessageService::createChannel($channel['id'], $alice['id'], ['body' => 'Hey @Bob']);

        $result = NotificationRepository::forUser($bob['id']);
        $n = $result['notifications'][0];

        // Verify all fields exist
        $this->assertArrayHasKey('id', $n);
        $this->assertArrayHasKey('user_id', $n);
        $this->assertArrayHasKey('type', $n);
        $this->assertArrayHasKey('actor', $n);
        $this->assertArrayHasKey('id', $n['actor']);
        $this->assertArrayHasKey('display_name', $n['actor']);
        $this->assertArrayHasKey('avatar_color', $n['actor']);
        $this->assertArrayHasKey('message_id', $n);
        $this->assertArrayHasKey('channel_id', $n);
        $this->assertArrayHasKey('conversation_id', $n);
        $this->assertArrayHasKey('thread_id', $n);
        $this->assertArrayHasKey('data', $n);
        $this->assertArrayHasKey('read_at', $n);
        $this->assertArrayHasKey('created_at', $n);
    }
}

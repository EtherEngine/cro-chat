<?php

declare(strict_types=1);

namespace Tests\Integration;

use App\Exceptions\ApiException;
use App\Repositories\MessageRepository;
use App\Repositories\ReadReceiptRepository;
use App\Repositories\ThreadRepository;
use App\Services\ThreadService;
use App\Services\MessageService;
use Tests\TestCase;

final class ThreadTest extends TestCase
{
    private array $owner;
    private array $member;
    private array $outsider;
    private array $space;
    private array $channel;

    protected function setUp(): void
    {
        parent::setUp();

        $this->owner = $this->createUser(['display_name' => 'Owner']);
        $this->member = $this->createUser(['display_name' => 'Member']);
        $this->outsider = $this->createUser(['display_name' => 'Outsider']);

        $this->space = $this->createSpace($this->owner['id']);
        $this->addSpaceMember($this->space['id'], $this->member['id']);

        $this->channel = $this->createChannel($this->space['id'], $this->owner['id']);
        $this->addChannelMember($this->channel['id'], $this->member['id']);
    }

    // ── Start thread ──────────────────────────

    public function test_start_thread_creates_thread_and_reply(): void
    {
        $rootMsg = $this->createMessage($this->owner['id'], $this->channel['id'], null, 'Root message');

        $result = ThreadService::startThread(
            $rootMsg['id'],
            $this->member['id'],
            ['body' => 'First thread reply']
        );

        $this->assertArrayHasKey('thread', $result);
        $this->assertArrayHasKey('message', $result);
        $this->assertSame($rootMsg['id'], $result['thread']['root_message_id']);
        $this->assertSame(1, $result['thread']['reply_count']);
        $this->assertSame('First thread reply', $result['message']['body']);
        $this->assertSame($result['thread']['id'], $result['message']['thread_id']);
    }

    public function test_start_thread_on_same_message_adds_to_existing(): void
    {
        $rootMsg = $this->createMessage($this->owner['id'], $this->channel['id'], null, 'Root');

        $r1 = ThreadService::startThread($rootMsg['id'], $this->member['id'], ['body' => 'Reply 1']);
        $r2 = ThreadService::startThread($rootMsg['id'], $this->owner['id'], ['body' => 'Reply 2']);

        // Same thread
        $this->assertSame($r1['thread']['id'], $r2['thread']['id']);
        // Reply count should be 2
        $this->assertSame(2, $r2['thread']['reply_count']);
    }

    public function test_cannot_start_thread_on_thread_reply(): void
    {
        $rootMsg = $this->createMessage($this->owner['id'], $this->channel['id'], null, 'Root');
        $r1 = ThreadService::startThread($rootMsg['id'], $this->member['id'], ['body' => 'Reply']);

        $this->assertApiException(422, 'THREAD_NESTED_DENIED', function () use ($r1) {
            ThreadService::startThread(
                $r1['message']['id'],
                $this->owner['id'],
                ['body' => 'Nested thread']
            );
        });
    }

    public function test_outsider_cannot_start_thread(): void
    {
        $rootMsg = $this->createMessage($this->owner['id'], $this->channel['id'], null, 'Root');

        $this->assertApiException(403, 'SPACE_MEMBER_REQUIRED', function () use ($rootMsg) {
            ThreadService::startThread(
                $rootMsg['id'],
                $this->outsider['id'],
                ['body' => 'Should fail']
            );
        });
    }

    public function test_start_thread_on_nonexistent_message(): void
    {
        $this->assertApiException(404, 'MESSAGE_NOT_FOUND', function () {
            ThreadService::startThread(9999, $this->member['id'], ['body' => 'Nope']);
        });
    }

    public function test_start_thread_empty_body_fails(): void
    {
        $rootMsg = $this->createMessage($this->owner['id'], $this->channel['id'], null, 'Root');

        $this->assertApiException(422, 'MESSAGE_EMPTY', function () use ($rootMsg) {
            ThreadService::startThread($rootMsg['id'], $this->member['id'], ['body' => '   ']);
        });
    }

    // ── Reply to existing thread ──────────────

    public function test_create_reply_in_thread(): void
    {
        $rootMsg = $this->createMessage($this->owner['id'], $this->channel['id'], null, 'Root');
        $start = ThreadService::startThread($rootMsg['id'], $this->member['id'], ['body' => 'Reply 1']);
        $threadId = $start['thread']['id'];

        $reply = ThreadService::createReply($threadId, $this->owner['id'], ['body' => 'Reply 2']);

        $this->assertSame('Reply 2', $reply['body']);
        $this->assertSame($threadId, $reply['thread_id']);
        $this->assertSame($this->channel['id'], $reply['channel_id']);
    }

    public function test_outsider_cannot_reply_to_thread(): void
    {
        $rootMsg = $this->createMessage($this->owner['id'], $this->channel['id'], null, 'Root');
        $start = ThreadService::startThread($rootMsg['id'], $this->member['id'], ['body' => 'Reply 1']);

        $this->assertApiException(403, 'SPACE_MEMBER_REQUIRED', function () use ($start) {
            ThreadService::createReply($start['thread']['id'], $this->outsider['id'], ['body' => 'Fail']);
        });
    }

    public function test_reply_to_nonexistent_thread_fails(): void
    {
        $this->assertApiException(404, 'THREAD_NOT_FOUND', function () {
            ThreadService::createReply(9999, $this->member['id'], ['body' => 'Nope']);
        });
    }

    // ── Get thread ────────────────────────────

    public function test_get_thread_returns_root_and_replies(): void
    {
        $rootMsg = $this->createMessage($this->owner['id'], $this->channel['id'], null, 'Root');
        ThreadService::startThread($rootMsg['id'], $this->member['id'], ['body' => 'Reply 1']);
        $start = ThreadService::startThread($rootMsg['id'], $this->owner['id'], ['body' => 'Reply 2']);
        $threadId = $start['thread']['id'];

        $result = ThreadService::getThread($threadId, $this->member['id']);

        $this->assertSame($threadId, $result['thread']['id']);
        $this->assertSame($rootMsg['id'], $result['root_message']['id']);
        $this->assertCount(2, $result['messages']);
    }

    public function test_outsider_cannot_get_thread(): void
    {
        $rootMsg = $this->createMessage($this->owner['id'], $this->channel['id'], null, 'Root');
        $start = ThreadService::startThread($rootMsg['id'], $this->member['id'], ['body' => 'Reply']);

        $this->assertApiException(403, 'SPACE_MEMBER_REQUIRED', function () use ($start) {
            ThreadService::getThread($start['thread']['id'], $this->outsider['id']);
        });
    }

    // ── Thread replies hidden from main feed ──

    public function test_thread_replies_hidden_from_channel_feed(): void
    {
        $this->createMessage($this->owner['id'], $this->channel['id'], null, 'Normal msg');
        $rootMsg = $this->createMessage($this->owner['id'], $this->channel['id'], null, 'Thread root');
        ThreadService::startThread($rootMsg['id'], $this->member['id'], ['body' => 'Thread reply']);

        $feed = MessageService::listChannel($this->channel['id'], $this->member['id']);

        // Only 2 messages in feed (normal + root), thread reply is hidden
        $this->assertCount(2, $feed['messages']);
        $bodies = array_column($feed['messages'], 'body');
        $this->assertContains('Normal msg', $bodies);
        $this->assertContains('Thread root', $bodies);
        $this->assertNotContains('Thread reply', $bodies);
    }

    public function test_root_message_includes_thread_summary(): void
    {
        $rootMsg = $this->createMessage($this->owner['id'], $this->channel['id'], null, 'Thread root');
        $start = ThreadService::startThread($rootMsg['id'], $this->member['id'], ['body' => 'Reply 1']);
        ThreadService::startThread($rootMsg['id'], $this->owner['id'], ['body' => 'Reply 2']);

        $feed = MessageService::listChannel($this->channel['id'], $this->member['id']);
        $root = $feed['messages'][0];

        $this->assertArrayHasKey('thread', $root);
        $this->assertSame($start['thread']['id'], $root['thread']['id']);
        $this->assertSame(2, $root['thread']['reply_count']);
    }

    // ── Thread in conversation ────────────────

    public function test_thread_in_conversation(): void
    {
        $conv = $this->createConversation($this->space['id'], [$this->owner['id'], $this->member['id']]);
        $rootMsg = $this->createMessage($this->owner['id'], null, $conv['id'], 'DM root');

        $result = ThreadService::startThread($rootMsg['id'], $this->member['id'], ['body' => 'DM reply']);

        $this->assertSame($conv['id'], $result['thread']['conversation_id']);
        $this->assertNull($result['thread']['channel_id']);
        $this->assertSame($conv['id'], $result['message']['conversation_id']);
    }

    // ── Thread read state ─────────────────────

    public function test_mark_thread_read(): void
    {
        $rootMsg = $this->createMessage($this->owner['id'], $this->channel['id'], null, 'Root');
        $start = ThreadService::startThread($rootMsg['id'], $this->member['id'], ['body' => 'Reply']);
        $threadId = $start['thread']['id'];
        $replyId = $start['message']['id'];

        // Should not throw
        ThreadService::markRead($threadId, $this->member['id'], $replyId);

        $counts = ReadReceiptRepository::unreadCounts($this->member['id']);
        $this->assertArrayHasKey('threads', $counts);
    }

    public function test_outsider_cannot_mark_thread_read(): void
    {
        $rootMsg = $this->createMessage($this->owner['id'], $this->channel['id'], null, 'Root');
        $start = ThreadService::startThread($rootMsg['id'], $this->member['id'], ['body' => 'Reply']);

        $this->assertApiException(403, 'SPACE_MEMBER_REQUIRED', function () use ($start) {
            ThreadService::markRead($start['thread']['id'], $this->outsider['id'], $start['message']['id']);
        });
    }

    // ── Thread unread counts ──────────────────

    public function test_thread_unread_counts(): void
    {
        $rootMsg = $this->createMessage($this->owner['id'], $this->channel['id'], null, 'Root');
        // Owner starts thread
        $start = ThreadService::startThread($rootMsg['id'], $this->owner['id'], ['body' => 'Reply 1']);
        $threadId = $start['thread']['id'];

        // Member replied too → is now a participant
        ThreadService::createReply($threadId, $this->member['id'], ['body' => 'Reply 2']);

        // Owner adds another reply → member has 1 unread (owner's first reply)
        // Actually: member participated, so member has unread from owner's reply 1
        // But since member's own reply is not counted, unread for member = 1 (owner's reply 1)
        // After owner posts another one → member has 2 unread
        ThreadService::createReply($threadId, $this->owner['id'], ['body' => 'Reply 3']);

        $counts = ReadReceiptRepository::unreadCounts($this->member['id']);
        $this->assertArrayHasKey('threads', $counts);
        // Member has unread in this thread (2 messages from owner, own messages excluded)
        $this->assertArrayHasKey($threadId, $counts['threads']);
        $this->assertSame(2, $counts['threads'][$threadId]);
    }
}

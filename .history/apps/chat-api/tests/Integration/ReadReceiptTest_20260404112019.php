<?php

declare(strict_types=1);

namespace Tests\Integration;

use App\Repositories\ReadReceiptRepository;
use App\Services\ChannelService;
use App\Services\ConversationService;
use Tests\TestCase;

final class ReadReceiptTest extends TestCase
{
    private array $alice;
    private array $bob;
    private array $space;
    private array $channel;

    protected function setUp(): void
    {
        parent::setUp();

        $this->alice = $this->createUser(['display_name' => 'Alice']);
        $this->bob = $this->createUser(['display_name' => 'Bob']);

        $this->space = $this->createSpace($this->alice['id']);
        $this->addSpaceMember($this->space['id'], $this->bob['id']);

        $this->channel = $this->createChannel($this->space['id'], $this->alice['id']);
        $this->addChannelMember($this->channel['id'], $this->bob['id']);
    }

    // ── Channel read receipts ─────────────────

    public function test_mark_channel_as_read(): void
    {
        $msg = $this->createMessage($this->alice['id'], $this->channel['id'], null, 'Msg 1');

        ReadReceiptRepository::markChannelRead($this->bob['id'], $this->channel['id'], $msg['id']);

        $counts = ReadReceiptRepository::unreadCounts($this->bob['id']);
        $this->assertArrayNotHasKey($this->channel['id'], $counts['channels']);
    }

    public function test_unread_count_after_new_messages(): void
    {
        $msg1 = $this->createMessage($this->alice['id'], $this->channel['id'], null, 'Msg 1');

        // Bob reads up to msg1
        ReadReceiptRepository::markChannelRead($this->bob['id'], $this->channel['id'], $msg1['id']);

        // Alice sends 2 more messages
        $this->createMessage($this->alice['id'], $this->channel['id'], null, 'Msg 2');
        $this->createMessage($this->alice['id'], $this->channel['id'], null, 'Msg 3');

        $counts = ReadReceiptRepository::unreadCounts($this->bob['id']);
        $this->assertSame(2, $counts['channels'][$this->channel['id']]);
    }

    public function test_own_messages_dont_count_as_unread(): void
    {
        $msg = $this->createMessage($this->bob['id'], $this->channel['id'], null, 'My own msg');

        $counts = ReadReceiptRepository::unreadCounts($this->bob['id']);
        // Bob's own messages should not appear in unread
        $this->assertArrayNotHasKey($this->channel['id'], $counts['channels']);
    }

    public function test_mark_read_is_idempotent(): void
    {
        $msg = $this->createMessage($this->alice['id'], $this->channel['id'], null, 'Msg');

        ReadReceiptRepository::markChannelRead($this->bob['id'], $this->channel['id'], $msg['id']);
        ReadReceiptRepository::markChannelRead($this->bob['id'], $this->channel['id'], $msg['id']);

        $counts = ReadReceiptRepository::unreadCounts($this->bob['id']);
        $this->assertArrayNotHasKey($this->channel['id'], $counts['channels']);
    }

    public function test_mark_read_uses_greatest_message_id(): void
    {
        $msg1 = $this->createMessage($this->alice['id'], $this->channel['id'], null, 'Msg 1');
        $msg2 = $this->createMessage($this->alice['id'], $this->channel['id'], null, 'Msg 2');
        $msg3 = $this->createMessage($this->alice['id'], $this->channel['id'], null, 'Msg 3');

        // Mark up to msg3
        ReadReceiptRepository::markChannelRead($this->bob['id'], $this->channel['id'], $msg3['id']);

        // Try to mark back to msg1 (should not regress)
        ReadReceiptRepository::markChannelRead($this->bob['id'], $this->channel['id'], $msg1['id']);

        $counts = ReadReceiptRepository::unreadCounts($this->bob['id']);
        $this->assertArrayNotHasKey($this->channel['id'], $counts['channels']);
    }

    // ── Conversation read receipts ────────────

    public function test_conversation_unread_count(): void
    {
        $conv = $this->createConversation(
            $this->space['id'],
            [$this->alice['id'], $this->bob['id']]
        );

        $msg1 = $this->createMessage($this->alice['id'], null, $conv['id'], 'DM 1');
        $this->createMessage($this->alice['id'], null, $conv['id'], 'DM 2');

        // Bob reads msg1
        ReadReceiptRepository::markConversationRead($this->bob['id'], $conv['id'], $msg1['id']);

        $counts = ReadReceiptRepository::unreadCounts($this->bob['id']);
        $this->assertSame(1, $counts['conversations'][$conv['id']]);
    }

    public function test_mark_conversation_read_clears_count(): void
    {
        $conv = $this->createConversation(
            $this->space['id'],
            [$this->alice['id'], $this->bob['id']]
        );

        $this->createMessage($this->alice['id'], null, $conv['id'], 'DM 1');
        $msg2 = $this->createMessage($this->alice['id'], null, $conv['id'], 'DM 2');

        ReadReceiptRepository::markConversationRead($this->bob['id'], $conv['id'], $msg2['id']);

        $counts = ReadReceiptRepository::unreadCounts($this->bob['id']);
        $this->assertArrayNotHasKey($conv['id'], $counts['conversations']);
    }

    // ── Multiple channels/conversations ───────

    public function test_unread_counts_across_multiple_channels(): void
    {
        $ch2 = $this->createChannel($this->space['id'], $this->alice['id']);
        $this->addChannelMember($ch2['id'], $this->bob['id']);

        // 2 unread in channel 1
        $this->createMessage($this->alice['id'], $this->channel['id'], null, 'Ch1 Msg 1');
        $this->createMessage($this->alice['id'], $this->channel['id'], null, 'Ch1 Msg 2');

        // 1 unread in channel 2
        $this->createMessage($this->alice['id'], $ch2['id'], null, 'Ch2 Msg 1');

        $counts = ReadReceiptRepository::unreadCounts($this->bob['id']);
        $this->assertSame(2, $counts['channels'][$this->channel['id']]);
        $this->assertSame(1, $counts['channels'][$ch2['id']]);
    }
}

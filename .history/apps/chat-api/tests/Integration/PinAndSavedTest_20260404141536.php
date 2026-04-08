<?php

declare(strict_types=1);

namespace Tests\Integration;

use App\Services\ChannelService;
use App\Services\ConversationService;
use App\Services\MessageService;
use App\Services\PinService;
use App\Services\SavedMessageService;
use App\Support\Database;
use Tests\TestCase;

final class PinAndSavedTest extends TestCase
{
    private array $alice;
    private array $bob;
    private array $outsider;
    private array $space;
    private array $channel;

    protected function setUp(): void
    {
        parent::setUp();

        $this->alice   = $this->createUser(['display_name' => 'Alice']);
        $this->bob     = $this->createUser(['display_name' => 'Bob']);
        $this->outsider = $this->createUser(['display_name' => 'Outsider']);

        $this->space   = $this->createSpace($this->alice['id']);
        $this->addSpaceMember($this->space['id'], $this->bob['id']);

        $this->channel = $this->createChannel($this->space['id'], $this->alice['id']);
        $this->addChannelMember($this->channel['id'], $this->bob['id']);
    }

    // ── Pin: channel messages ─────────────────

    public function test_pin_channel_message(): void
    {
        $msg = $this->createMessage($this->alice['id'], $this->channel['id'], null, 'Important');

        $result = PinService::pin($msg['id'], $this->alice['id']);
        $this->assertTrue($result['pinned']);
        $this->assertSame($msg['id'], $result['message_id']);
    }

    public function test_pin_is_idempotent(): void
    {
        $msg = $this->createMessage($this->alice['id'], $this->channel['id'], null, 'Pin me');

        PinService::pin($msg['id'], $this->alice['id']);
        // Pinning again should not throw
        $result = PinService::pin($msg['id'], $this->alice['id']);
        $this->assertTrue($result['pinned']);
    }

    public function test_unpin_channel_message(): void
    {
        $msg = $this->createMessage($this->alice['id'], $this->channel['id'], null, 'Temp pin');
        PinService::pin($msg['id'], $this->alice['id']);

        $result = PinService::unpin($msg['id'], $this->alice['id']);
        $this->assertFalse($result['pinned']);
    }

    public function test_unpin_not_pinned_does_not_throw(): void
    {
        $msg = $this->createMessage($this->alice['id'], $this->channel['id'], null, 'Never pinned');

        $result = PinService::unpin($msg['id'], $this->alice['id']);
        $this->assertFalse($result['pinned']);
    }

    public function test_list_channel_pins(): void
    {
        $msg1 = $this->createMessage($this->alice['id'], $this->channel['id'], null, 'First');
        $msg2 = $this->createMessage($this->bob['id'], $this->channel['id'], null, 'Second');
        $msg3 = $this->createMessage($this->alice['id'], $this->channel['id'], null, 'Third');

        PinService::pin($msg1['id'], $this->alice['id']);
        PinService::pin($msg3['id'], $this->bob['id']);

        $pins = PinService::forChannel($this->channel['id'], $this->alice['id']);
        $this->assertCount(2, $pins);

        // Newest pin first
        $this->assertSame($msg3['id'], $pins[0]['message']['id']);
        $this->assertSame($msg1['id'], $pins[1]['message']['id']);

        // Pin metadata
        $this->assertSame($this->bob['id'], $pins[0]['pinned_by']);
        $this->assertSame('Bob', $pins[0]['pinner_name']);
        $this->assertArrayHasKey('pinned_at', $pins[0]);
    }

    public function test_pin_publishes_domain_event(): void
    {
        $msg = $this->createMessage($this->alice['id'], $this->channel['id'], null, 'Event test');
        PinService::pin($msg['id'], $this->alice['id']);

        $events = Database::connection()->query(
            "SELECT * FROM domain_events WHERE event_type = 'message.pinned'"
        )->fetchAll(\PDO::FETCH_ASSOC);
        $this->assertCount(1, $events);
        $this->assertSame("channel:{$this->channel['id']}", $events[0]['room']);
    }

    public function test_unpin_publishes_domain_event(): void
    {
        $msg = $this->createMessage($this->alice['id'], $this->channel['id'], null, 'Unpin event');
        PinService::pin($msg['id'], $this->alice['id']);
        PinService::unpin($msg['id'], $this->alice['id']);

        $events = Database::connection()->query(
            "SELECT * FROM domain_events WHERE event_type = 'message.unpinned'"
        )->fetchAll(\PDO::FETCH_ASSOC);
        $this->assertCount(1, $events);
    }

    // ── Pin: access control ───────────────────

    public function test_outsider_cannot_pin_channel_message(): void
    {
        $msg = $this->createMessage($this->alice['id'], $this->channel['id'], null, 'Secret');

        $this->assertApiException(403, 'SPACE_MEMBER_REQUIRED', function () use ($msg) {
            PinService::pin($msg['id'], $this->outsider['id']);
        });
    }

    public function test_outsider_cannot_list_channel_pins(): void
    {
        $this->assertApiException(403, 'SPACE_MEMBER_REQUIRED', function () {
            PinService::forChannel($this->channel['id'], $this->outsider['id']);
        });
    }

    public function test_cannot_pin_deleted_message(): void
    {
        $msg = $this->createMessage($this->alice['id'], $this->channel['id'], null, 'Delete me');
        // Soft-delete it
        Database::connection()->prepare(
            'UPDATE messages SET deleted_at = NOW() WHERE id = ?'
        )->execute([$msg['id']]);

        $this->assertApiException(422, 'MESSAGE_DELETED', function () use ($msg) {
            PinService::pin($msg['id'], $this->alice['id']);
        });
    }

    public function test_cannot_pin_nonexistent_message(): void
    {
        $this->assertApiException(404, 'MESSAGE_NOT_FOUND', function () {
            PinService::pin(99999, $this->alice['id']);
        });
    }

    // ── Pin: conversation messages ────────────

    public function test_pin_conversation_message(): void
    {
        $conv = $this->createConversation(
            $this->space['id'],
            [$this->alice['id'], $this->bob['id']]
        );
        $msg = $this->createMessage($this->alice['id'], null, $conv['id'], 'DM pin');

        $result = PinService::pin($msg['id'], $this->alice['id']);
        $this->assertTrue($result['pinned']);

        $pins = PinService::forConversation($conv['id'], $this->bob['id']);
        $this->assertCount(1, $pins);
        $this->assertSame($msg['id'], $pins[0]['message']['id']);
    }

    public function test_outsider_cannot_pin_conversation_message(): void
    {
        $conv = $this->createConversation(
            $this->space['id'],
            [$this->alice['id'], $this->bob['id']]
        );
        $msg = $this->createMessage($this->alice['id'], null, $conv['id'], 'Private');

        $this->assertApiException(403, 'CONVERSATION_ACCESS_DENIED', function () use ($msg) {
            PinService::pin($msg['id'], $this->outsider['id']);
        });
    }

    public function test_outsider_cannot_list_conversation_pins(): void
    {
        $conv = $this->createConversation(
            $this->space['id'],
            [$this->alice['id'], $this->bob['id']]
        );

        $this->assertApiException(403, 'CONVERSATION_ACCESS_DENIED', function () use ($conv) {
            PinService::forConversation($conv['id'], $this->outsider['id']);
        });
    }

    // ── Pin: unique constraint ────────────────

    public function test_one_message_one_pin(): void
    {
        $msg = $this->createMessage($this->alice['id'], $this->channel['id'], null, 'Unique');

        PinService::pin($msg['id'], $this->alice['id']);
        // Pinning same message by different user — idempotent due to UNIQUE(message_id)
        PinService::pin($msg['id'], $this->bob['id']);

        $pins = PinService::forChannel($this->channel['id'], $this->alice['id']);
        $this->assertCount(1, $pins);
    }

    // ── Saved Messages ────────────────────────

    public function test_save_message(): void
    {
        $msg = $this->createMessage($this->alice['id'], $this->channel['id'], null, 'Save me');

        $result = SavedMessageService::save($msg['id'], $this->alice['id']);
        $this->assertTrue($result['saved']);
    }

    public function test_save_is_idempotent(): void
    {
        $msg = $this->createMessage($this->alice['id'], $this->channel['id'], null, 'Double save');

        SavedMessageService::save($msg['id'], $this->alice['id']);
        $result = SavedMessageService::save($msg['id'], $this->alice['id']);
        $this->assertTrue($result['saved']);
    }

    public function test_unsave_message(): void
    {
        $msg = $this->createMessage($this->alice['id'], $this->channel['id'], null, 'Unsave me');
        SavedMessageService::save($msg['id'], $this->alice['id']);

        $result = SavedMessageService::unsave($msg['id'], $this->alice['id']);
        $this->assertFalse($result['saved']);
    }

    public function test_unsave_not_saved_does_not_throw(): void
    {
        $msg = $this->createMessage($this->alice['id'], $this->channel['id'], null, 'Never saved');

        $result = SavedMessageService::unsave($msg['id'], $this->alice['id']);
        $this->assertFalse($result['saved']);
    }

    public function test_list_saved_messages(): void
    {
        $msg1 = $this->createMessage($this->alice['id'], $this->channel['id'], null, 'Saved 1');
        $msg2 = $this->createMessage($this->bob['id'], $this->channel['id'], null, 'Saved 2');
        $msg3 = $this->createMessage($this->alice['id'], $this->channel['id'], null, 'Not saved');

        SavedMessageService::save($msg1['id'], $this->alice['id']);
        SavedMessageService::save($msg2['id'], $this->alice['id']);

        $saved = SavedMessageService::forUser($this->alice['id']);
        $this->assertCount(2, $saved);

        // Newest save first
        $this->assertSame($msg2['id'], $saved[0]['message']['id']);
        $this->assertSame($msg1['id'], $saved[1]['message']['id']);

        // Metadata
        $this->assertArrayHasKey('saved_at', $saved[0]);
        $this->assertArrayHasKey('context', $saved[0]['message']);
    }

    public function test_saved_messages_per_user(): void
    {
        $msg = $this->createMessage($this->alice['id'], $this->channel['id'], null, 'Shared');

        SavedMessageService::save($msg['id'], $this->alice['id']);
        SavedMessageService::save($msg['id'], $this->bob['id']);

        $aliceSaved = SavedMessageService::forUser($this->alice['id']);
        $bobSaved   = SavedMessageService::forUser($this->bob['id']);

        $this->assertCount(1, $aliceSaved);
        $this->assertCount(1, $bobSaved);
    }

    public function test_cannot_save_nonexistent_message(): void
    {
        $this->assertApiException(404, 'MESSAGE_NOT_FOUND', function () {
            SavedMessageService::save(99999, $this->alice['id']);
        });
    }

    public function test_saved_message_context_channel(): void
    {
        $msg = $this->createMessage($this->alice['id'], $this->channel['id'], null, 'In channel');
        SavedMessageService::save($msg['id'], $this->alice['id']);

        $saved = SavedMessageService::forUser($this->alice['id']);
        $this->assertNotNull($saved[0]['message']['context']);
    }

    public function test_saved_message_context_dm(): void
    {
        $conv = $this->createConversation(
            $this->space['id'],
            [$this->alice['id'], $this->bob['id']]
        );
        $msg = $this->createMessage($this->alice['id'], null, $conv['id'], 'In DM');
        SavedMessageService::save($msg['id'], $this->alice['id']);

        $saved = SavedMessageService::forUser($this->alice['id']);
        $this->assertSame('DM', $saved[0]['message']['context']);
    }

    public function test_deleted_message_body_hidden_in_saved(): void
    {
        $msg = $this->createMessage($this->alice['id'], $this->channel['id'], null, 'Secret body');
        SavedMessageService::save($msg['id'], $this->alice['id']);

        // Soft-delete
        Database::connection()->prepare(
            'UPDATE messages SET deleted_at = NOW() WHERE id = ?'
        )->execute([$msg['id']]);

        $saved = SavedMessageService::forUser($this->alice['id']);
        $this->assertNull($saved[0]['message']['body']);
    }

    public function test_deleted_message_body_hidden_in_pins(): void
    {
        $msg = $this->createMessage($this->alice['id'], $this->channel['id'], null, 'Pin secret');
        PinService::pin($msg['id'], $this->alice['id']);

        Database::connection()->prepare(
            'UPDATE messages SET deleted_at = NOW() WHERE id = ?'
        )->execute([$msg['id']]);

        $pins = PinService::forChannel($this->channel['id'], $this->alice['id']);
        $this->assertNull($pins[0]['message']['body']);
    }
}

<?php

declare(strict_types=1);

namespace Tests\Integration;

use App\Exceptions\ApiException;
use App\Repositories\MessageRepository;
use App\Services\MessageService;
use Tests\TestCase;

final class MessageTest extends TestCase
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

    // ── Create ────────────────────────────────

    public function test_member_can_create_channel_message(): void
    {
        $result = MessageService::createChannel(
            $this->channel['id'],
            $this->member['id'],
            ['body' => 'Hello World']
        );

        $this->assertSame('Hello World', $result['body']);
        $this->assertSame($this->member['id'], $result['user_id']);
        $this->assertSame($this->channel['id'], $result['channel_id']);
    }

    public function test_nonmember_cannot_create_channel_message(): void
    {
        $this->assertApiException(403, 'CHANNEL_MEMBER_REQUIRED', function () {
            MessageService::createChannel(
                $this->channel['id'],
                $this->outsider['id'],
                ['body' => 'Should fail']
            );
        });
    }

    public function test_empty_body_fails_validation(): void
    {
        $this->assertApiException(422, 'MESSAGE_EMPTY', function () {
            MessageService::createChannel(
                $this->channel['id'],
                $this->member['id'],
                ['body' => '   ']
            );
        });
    }

    public function test_body_too_long_fails_validation(): void
    {
        $this->assertApiException(422, 'MESSAGE_TOO_LONG', function () {
            MessageService::createChannel(
                $this->channel['id'],
                $this->member['id'],
                ['body' => str_repeat('a', 10_001)]
            );
        });
    }

    public function test_idempotent_message_creation(): void
    {
        $msg1 = MessageService::createChannel(
            $this->channel['id'],
            $this->member['id'],
            ['body' => 'Hello', 'idempotency_key' => 'key-123']
        );

        $msg2 = MessageService::createChannel(
            $this->channel['id'],
            $this->member['id'],
            ['body' => 'Hello', 'idempotency_key' => 'key-123']
        );

        $this->assertSame($msg1['id'], $msg2['id'], 'Same idempotency key should return the same message');
    }

    public function test_reply_to_valid_parent(): void
    {
        $parent = $this->createMessage($this->owner['id'], $this->channel['id'], null, 'Parent');

        $reply = MessageService::createChannel(
            $this->channel['id'],
            $this->member['id'],
            ['body' => 'Reply', 'reply_to_id' => $parent['id']]
        );

        $this->assertSame($parent['id'], $reply['reply_to_id']);
    }

    public function test_reply_to_nonexistent_parent_fails(): void
    {
        $this->assertApiException(404, 'REPLY_PARENT_NOT_FOUND', function () {
            MessageService::createChannel(
                $this->channel['id'],
                $this->member['id'],
                ['body' => 'Reply', 'reply_to_id' => 9999]
            );
        });
    }

    public function test_reply_to_different_channel_fails(): void
    {
        $otherChannel = $this->createChannel($this->space['id'], $this->owner['id']);
        $this->addChannelMember($otherChannel['id'], $this->member['id']);
        $parent = $this->createMessage($this->owner['id'], $otherChannel['id'], null, 'Parent');

        $this->assertApiException(422, 'REPLY_WRONG_CHANNEL', function () use ($parent) {
            MessageService::createChannel(
                $this->channel['id'],
                $this->member['id'],
                ['body' => 'Reply', 'reply_to_id' => $parent['id']]
            );
        });
    }

    // ── List ──────────────────────────────────

    public function test_member_can_list_channel_messages(): void
    {
        $this->createMessage($this->owner['id'], $this->channel['id'], null, 'Msg 1');
        $this->createMessage($this->member['id'], $this->channel['id'], null, 'Msg 2');

        $result = MessageService::listChannel($this->channel['id'], $this->member['id']);

        $this->assertCount(2, $result['messages']);
    }

    public function test_outsider_cannot_list_channel_messages(): void
    {
        $this->assertApiException(403, 'SPACE_MEMBER_REQUIRED', function () {
            MessageService::listChannel($this->channel['id'], $this->outsider['id']);
        });
    }

    // ── Edit ──────────────────────────────────

    public function test_author_can_edit_own_message(): void
    {
        $msg = $this->createMessage($this->member['id'], $this->channel['id'], null, 'Original');

        $result = MessageService::update($msg['id'], $this->member['id'], ['body' => 'Edited']);

        $this->assertSame('Edited', $result['body']);
        $this->assertNotNull($result['edited_at']);
    }

    public function test_edit_preserves_history(): void
    {
        $msg = $this->createMessage($this->member['id'], $this->channel['id'], null, 'Version 1');

        MessageService::update($msg['id'], $this->member['id'], ['body' => 'Version 2']);

        $history = MessageService::editHistory($msg['id'], $this->member['id']);
        $this->assertCount(1, $history);
        $this->assertSame('Version 1', $history[0]['body']);
    }

    public function test_other_user_cannot_edit_message(): void
    {
        $msg = $this->createMessage($this->owner['id'], $this->channel['id'], null, 'Original');

        $this->assertApiException(403, 'MESSAGE_EDIT_DENIED', function () use ($msg) {
            MessageService::update($msg['id'], $this->member['id'], ['body' => 'Hijacked']);
        });
    }

    public function test_space_admin_can_edit_any_message(): void
    {
        $admin = $this->createUser(['display_name' => 'Admin']);
        $this->addSpaceMember($this->space['id'], $admin['id'], 'admin');

        $msg = $this->createMessage($this->member['id'], $this->channel['id'], null, 'Original');

        $result = MessageService::update($msg['id'], $admin['id'], ['body' => 'Admin edit']);
        $this->assertSame('Admin edit', $result['body']);
    }

    // ── Delete ────────────────────────────────

    public function test_author_can_delete_own_message(): void
    {
        $msg = $this->createMessage($this->member['id'], $this->channel['id'], null, 'Delete me');

        MessageService::delete($msg['id'], $this->member['id']);

        $deleted = MessageRepository::find($msg['id']);
        $this->assertNotNull($deleted['deleted_at']);
        $this->assertNull($deleted['body']); // body is null for soft-deleted
    }

    public function test_space_owner_can_delete_any_message(): void
    {
        $msg = $this->createMessage($this->member['id'], $this->channel['id'], null, 'Delete me');

        MessageService::delete($msg['id'], $this->owner['id']);

        $deleted = MessageRepository::find($msg['id']);
        $this->assertNotNull($deleted['deleted_at']);
    }

    public function test_regular_member_cannot_delete_others_message(): void
    {
        $msg = $this->createMessage($this->owner['id'], $this->channel['id'], null, 'Protected');

        $this->assertApiException(403, 'MESSAGE_DELETE_DENIED', function () use ($msg) {
            MessageService::delete($msg['id'], $this->member['id']);
        });
    }

    public function test_edit_deleted_message_fails(): void
    {
        $msg = $this->createMessage($this->member['id'], $this->channel['id'], null, 'Msg');
        MessageService::delete($msg['id'], $this->member['id']);

        $this->assertApiException(404, 'MESSAGE_NOT_FOUND', function () use ($msg) {
            MessageService::update($msg['id'], $this->member['id'], ['body' => 'Too late']);
        });
    }

    public function test_nonexistent_message_fails(): void
    {
        $this->assertApiException(404, 'MESSAGE_NOT_FOUND', function () {
            MessageService::update(9999, $this->member['id'], ['body' => 'Nope']);
        });
    }
}

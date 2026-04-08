<?php

declare(strict_types=1);

namespace Tests\Integration;

use App\Repositories\ReactionRepository;
use App\Services\MessageService;
use App\Services\ReactionService;
use Tests\TestCase;

final class ReactionTest extends TestCase
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

    // ── Add reaction ──────────────────────────

    public function test_add_reaction_to_message(): void
    {
        $msg = $this->createMessage($this->owner['id'], $this->channel['id'], null, 'Hello');

        $reactions = ReactionService::add($msg['id'], $this->member['id'], ['emoji' => '👍']);

        $this->assertCount(1, $reactions);
        $this->assertSame('👍', $reactions[0]['emoji']);
        $this->assertSame(1, $reactions[0]['count']);
        $this->assertContains($this->member['id'], $reactions[0]['user_ids']);
    }

    public function test_multiple_users_same_emoji(): void
    {
        $msg = $this->createMessage($this->owner['id'], $this->channel['id'], null, 'Hello');

        ReactionService::add($msg['id'], $this->owner['id'], ['emoji' => '👍']);
        $reactions = ReactionService::add($msg['id'], $this->member['id'], ['emoji' => '👍']);

        $this->assertCount(1, $reactions);
        $this->assertSame(2, $reactions[0]['count']);
        $this->assertContains($this->owner['id'], $reactions[0]['user_ids']);
        $this->assertContains($this->member['id'], $reactions[0]['user_ids']);
    }

    public function test_multiple_emojis_on_message(): void
    {
        $msg = $this->createMessage($this->owner['id'], $this->channel['id'], null, 'Hello');

        ReactionService::add($msg['id'], $this->owner['id'], ['emoji' => '👍']);
        $reactions = ReactionService::add($msg['id'], $this->member['id'], ['emoji' => '❤']);

        $this->assertCount(2, $reactions);
        $emojis = array_column($reactions, 'emoji');
        $this->assertContains('👍', $emojis);
        $this->assertContains('❤', $emojis);
    }

    public function test_duplicate_reaction_fails(): void
    {
        $msg = $this->createMessage($this->owner['id'], $this->channel['id'], null, 'Hello');
        ReactionService::add($msg['id'], $this->member['id'], ['emoji' => '👍']);

        $this->assertApiException(422, 'REACTION_DUPLICATE', function () use ($msg) {
            ReactionService::add($msg['id'], $this->member['id'], ['emoji' => '👍']);
        });
    }

    public function test_outsider_cannot_react(): void
    {
        $msg = $this->createMessage($this->owner['id'], $this->channel['id'], null, 'Hello');

        $this->assertApiException(403, 'SPACE_MEMBER_REQUIRED', function () use ($msg) {
            ReactionService::add($msg['id'], $this->outsider['id'], ['emoji' => '👍']);
        });
    }

    public function test_react_to_nonexistent_message(): void
    {
        $this->assertApiException(404, 'MESSAGE_NOT_FOUND', function () {
            ReactionService::add(9999, $this->member['id'], ['emoji' => '👍']);
        });
    }

    public function test_react_to_deleted_message(): void
    {
        $msg = $this->createMessage($this->owner['id'], $this->channel['id'], null, 'Hello');
        MessageService::delete($msg['id'], $this->owner['id']);

        $this->assertApiException(404, 'MESSAGE_NOT_FOUND', function () use ($msg) {
            ReactionService::add($msg['id'], $this->member['id'], ['emoji' => '👍']);
        });
    }

    // ── Remove reaction ───────────────────────

    public function test_remove_own_reaction(): void
    {
        $msg = $this->createMessage($this->owner['id'], $this->channel['id'], null, 'Hello');
        ReactionService::add($msg['id'], $this->member['id'], ['emoji' => '👍']);

        $reactions = ReactionService::remove($msg['id'], $this->member['id'], ['emoji' => '👍']);

        $this->assertCount(0, $reactions);
    }

    public function test_remove_nonexistent_reaction(): void
    {
        $msg = $this->createMessage($this->owner['id'], $this->channel['id'], null, 'Hello');

        $this->assertApiException(404, 'REACTION_NOT_FOUND', function () use ($msg) {
            ReactionService::remove($msg['id'], $this->member['id'], ['emoji' => '👍']);
        });
    }

    public function test_remove_preserves_others_reactions(): void
    {
        $msg = $this->createMessage($this->owner['id'], $this->channel['id'], null, 'Hello');
        ReactionService::add($msg['id'], $this->owner['id'], ['emoji' => '👍']);
        ReactionService::add($msg['id'], $this->member['id'], ['emoji' => '👍']);

        $reactions = ReactionService::remove($msg['id'], $this->member['id'], ['emoji' => '👍']);

        $this->assertCount(1, $reactions);
        $this->assertSame(1, $reactions[0]['count']);
        $this->assertContains($this->owner['id'], $reactions[0]['user_ids']);
    }

    // ── List reactions ────────────────────────

    public function test_list_reactions(): void
    {
        $msg = $this->createMessage($this->owner['id'], $this->channel['id'], null, 'Hello');
        ReactionService::add($msg['id'], $this->owner['id'], ['emoji' => '👍']);
        ReactionService::add($msg['id'], $this->member['id'], ['emoji' => '❤']);

        $reactions = ReactionService::list($msg['id'], $this->member['id']);

        $this->assertCount(2, $reactions);
    }

    public function test_outsider_cannot_list_reactions(): void
    {
        $msg = $this->createMessage($this->owner['id'], $this->channel['id'], null, 'Hello');

        $this->assertApiException(403, 'SPACE_MEMBER_REQUIRED', function () use ($msg) {
            ReactionService::list($msg['id'], $this->outsider['id']);
        });
    }

    // ── Validation ────────────────────────────

    public function test_empty_emoji_fails(): void
    {
        $msg = $this->createMessage($this->owner['id'], $this->channel['id'], null, 'Hello');

        $this->assertApiException(422, 'EMOJI_REQUIRED', function () use ($msg) {
            ReactionService::add($msg['id'], $this->member['id'], ['emoji' => '']);
        });
    }

    public function test_invalid_emoji_fails(): void
    {
        $msg = $this->createMessage($this->owner['id'], $this->channel['id'], null, 'Hello');

        $this->assertApiException(422, 'EMOJI_INVALID', function () use ($msg) {
            ReactionService::add($msg['id'], $this->member['id'], ['emoji' => 'not-an-emoji']);
        });
    }

    public function test_shortcode_emoji_accepted(): void
    {
        $msg = $this->createMessage($this->owner['id'], $this->channel['id'], null, 'Hello');

        $reactions = ReactionService::add($msg['id'], $this->member['id'], ['emoji' => ':thumbsup:']);

        $this->assertCount(1, $reactions);
        $this->assertSame(':thumbsup:', $reactions[0]['emoji']);
    }

    // ── Reactions in message hydration ─────────

    public function test_reactions_included_in_message_feed(): void
    {
        $msg = $this->createMessage($this->owner['id'], $this->channel['id'], null, 'Hello');
        ReactionService::add($msg['id'], $this->owner['id'], ['emoji' => '👍']);
        ReactionService::add($msg['id'], $this->member['id'], ['emoji' => '👍']);

        $feed = MessageService::listChannel($this->channel['id'], $this->member['id']);

        $this->assertCount(1, $feed['messages']);
        $message = $feed['messages'][0];
        $this->assertArrayHasKey('reactions', $message);
        $this->assertCount(1, $message['reactions']);
        $this->assertSame('👍', $message['reactions'][0]['emoji']);
        $this->assertSame(2, $message['reactions'][0]['count']);
    }

    // ── Conversation reactions ─────────────────

    public function test_reaction_in_conversation(): void
    {
        $conv = $this->createConversation($this->space['id'], [$this->owner['id'], $this->member['id']]);
        $msg = $this->createMessage($this->owner['id'], null, $conv['id'], 'DM');

        $reactions = ReactionService::add($msg['id'], $this->member['id'], ['emoji' => '❤']);

        $this->assertCount(1, $reactions);
        $this->assertSame('❤', $reactions[0]['emoji']);
    }
}

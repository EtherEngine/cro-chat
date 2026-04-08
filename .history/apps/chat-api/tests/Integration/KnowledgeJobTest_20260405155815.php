<?php

declare(strict_types=1);

namespace Tests\Integration;

use App\Jobs\Handlers\KnowledgeExtractHandler;
use App\Jobs\Handlers\KnowledgeSummarizeChannelHandler;
use App\Jobs\Handlers\KnowledgeSummarizeThreadHandler;
use App\Repositories\KnowledgeRepository;
use App\Support\Database;
use Tests\TestCase;

/**
 * Tests for Knowledge job handlers: thread summary, channel summary, extraction.
 */
final class KnowledgeJobTest extends TestCase
{
    private array $user;
    private array $user2;
    private array $space;
    private array $channel;

    protected function setUp(): void
    {
        parent::setUp();
        $this->user    = $this->createUser(['display_name' => 'Alice']);
        $this->user2   = $this->createUser(['display_name' => 'Bob']);
        $this->space   = $this->createSpace($this->user['id']);
        $this->addSpaceMember($this->space['id'], $this->user2['id']);
        $this->channel = $this->createChannel($this->space['id'], $this->user['id']);
        $this->addChannelMember($this->channel['id'], $this->user2['id']);
    }

    // ── Thread Summary Handler ───────────────────────────

    public function test_thread_summary_handler_creates_summary(): void
    {
        // Create root message + thread + replies
        $root = $this->createMessage($this->user['id'], $this->channel['id'], null, 'How should we handle auth?');
        $thread = $this->createThread($root['id'], $this->channel['id'], null, $this->user['id']);

        // Add replies to thread
        $this->createMessage($this->user2['id'], $this->channel['id'], null, 'We could use JWT tokens for stateless auth', $thread['id']);
        $this->createMessage($this->user['id'], $this->channel['id'], null, 'Agreed. Let\'s go with session-based auth for simplicity', $thread['id']);
        $this->createMessage($this->user2['id'], $this->channel['id'], null, 'OK, decided: session-based auth with httponly cookies', $thread['id']);

        // Update reply count
        Database::connection()->prepare('UPDATE threads SET reply_count = 3 WHERE id = ?')->execute([$thread['id']]);

        $handler = new KnowledgeSummarizeThreadHandler();
        $handler->handle([
            'thread_id' => $thread['id'],
            'space_id'  => $this->space['id'],
            'user_id'   => $this->user['id'],
        ]);

        // Check summary was created
        $summaries = KnowledgeRepository::listSummaries($this->space['id'], 'thread', $thread['id']);
        $this->assertCount(1, $summaries);
        $this->assertSame('thread', $summaries[0]['scope_type']);
        $this->assertSame($thread['id'], $summaries[0]['scope_id']);
        $this->assertNotEmpty($summaries[0]['key_points']);
        $this->assertGreaterThan(0, $summaries[0]['message_count']);
    }

    public function test_thread_summary_skips_when_no_new_messages(): void
    {
        $root = $this->createMessage($this->user['id'], $this->channel['id'], null, 'Root');
        $thread = $this->createThread($root['id'], $this->channel['id'], null, $this->user['id']);
        $reply = $this->createMessage($this->user2['id'], $this->channel['id'], null, 'Reply', $thread['id']);

        Database::connection()->prepare('UPDATE threads SET reply_count = 1 WHERE id = ?')->execute([$thread['id']]);

        // First run
        $handler = new KnowledgeSummarizeThreadHandler();
        $handler->handle([
            'thread_id' => $thread['id'],
            'space_id'  => $this->space['id'],
            'user_id'   => $this->user['id'],
        ]);

        // Second run without new messages → no new summary
        $handler->handle([
            'thread_id' => $thread['id'],
            'space_id'  => $this->space['id'],
            'user_id'   => $this->user['id'],
        ]);

        $summaries = KnowledgeRepository::listSummaries($this->space['id'], 'thread', $thread['id']);
        $this->assertCount(1, $summaries);
    }

    public function test_thread_summary_invalid_thread(): void
    {
        $handler = new KnowledgeSummarizeThreadHandler();
        // Should not throw, just log and return
        $handler->handle([
            'thread_id' => 99999,
            'space_id'  => $this->space['id'],
            'user_id'   => $this->user['id'],
        ]);

        $summaries = KnowledgeRepository::listSummaries($this->space['id'], 'thread');
        $this->assertCount(0, $summaries);
    }

    // ── Channel Summary Handler ──────────────────────────

    public function test_channel_summary_handler_creates_daily_summary(): void
    {
        $now = date('Y-m-d H:i:s');

        // Create messages in the channel
        for ($i = 0; $i < 5; $i++) {
            $this->createMessage(
                $i % 2 === 0 ? $this->user['id'] : $this->user2['id'],
                $this->channel['id'],
                null,
                "Discussion message {$i} about architecture decisions"
            );
        }

        $handler = new KnowledgeSummarizeChannelHandler();
        $handler->handle([
            'channel_id'   => $this->channel['id'],
            'space_id'     => $this->space['id'],
            'period_start' => date('Y-m-d 00:00:00'),
            'period_end'   => date('Y-m-d 23:59:59'),
        ]);

        $summaries = KnowledgeRepository::listSummaries($this->space['id'], 'daily');
        $this->assertCount(1, $summaries);
        $this->assertSame((int) $this->channel['id'], $summaries[0]['scope_id']);
        $this->assertSame(5, $summaries[0]['message_count']);
    }

    public function test_channel_summary_no_messages_skips(): void
    {
        $handler = new KnowledgeSummarizeChannelHandler();
        $handler->handle([
            'channel_id'   => $this->channel['id'],
            'space_id'     => $this->space['id'],
            'period_start' => '2020-01-01 00:00:00',
            'period_end'   => '2020-01-01 23:59:59',
        ]);

        $summaries = KnowledgeRepository::listSummaries($this->space['id']);
        $this->assertCount(0, $summaries);
    }

    // ── Knowledge Extract Handler ────────────────────────

    public function test_extract_handler_finds_decisions(): void
    {
        $this->createMessage($this->user['id'], $this->channel['id'], null,
            'Wir haben entschieden, dass wir MariaDB verwenden statt PostgreSQL. Das ist final.');

        $handler = new KnowledgeExtractHandler();
        $handler->handle([
            'space_id'   => $this->space['id'],
            'channel_id' => $this->channel['id'],
        ]);

        $entries = KnowledgeRepository::listEntries($this->space['id']);
        $this->assertNotEmpty($entries);

        $types = array_column($entries, 'entry_type');
        $this->assertContains('fact', $types); // Decision pattern → fact type
    }

    public function test_extract_handler_finds_links(): void
    {
        $this->createMessage($this->user['id'], $this->channel['id'], null,
            'Schaut euch diese Doku an: https://mariadb.com/kb/en/documentation/ – sehr hilfreich');

        $handler = new KnowledgeExtractHandler();
        $handler->handle([
            'space_id'   => $this->space['id'],
            'channel_id' => $this->channel['id'],
        ]);

        $entries = KnowledgeRepository::listEntries($this->space['id'], null, 'link');
        $this->assertNotEmpty($entries);
        $this->assertStringContainsString('https://mariadb.com', $entries[0]['title']);
    }

    public function test_extract_handler_finds_action_items(): void
    {
        $this->createMessage($this->user['id'], $this->channel['id'], null,
            'TODO: Die Datenbankmigrationen müssen noch angepasst werden für das neue Schema');

        $handler = new KnowledgeExtractHandler();
        $handler->handle([
            'space_id'   => $this->space['id'],
            'channel_id' => $this->channel['id'],
        ]);

        $entries = KnowledgeRepository::listEntries($this->space['id'], null, 'action_item');
        $this->assertNotEmpty($entries);
    }

    public function test_extract_handler_skips_short_messages(): void
    {
        $this->createMessage($this->user['id'], $this->channel['id'], null, 'Ok');
        $this->createMessage($this->user['id'], $this->channel['id'], null, 'Ja');

        $handler = new KnowledgeExtractHandler();
        $handler->handle([
            'space_id'   => $this->space['id'],
            'channel_id' => $this->channel['id'],
        ]);

        $entries = KnowledgeRepository::listEntries($this->space['id']);
        $this->assertCount(0, $entries);
    }

    public function test_extract_uses_cursor_no_duplicates(): void
    {
        $this->createMessage($this->user['id'], $this->channel['id'], null,
            'TODO: Wir müssen die Tests für den Knowledge-Layer schreiben und deployen');

        $handler = new KnowledgeExtractHandler();
        $handler->handle([
            'space_id'   => $this->space['id'],
            'channel_id' => $this->channel['id'],
        ]);
        $countFirst = count(KnowledgeRepository::listEntries($this->space['id']));

        // Second run – same messages, no new ones
        $handler->handle([
            'space_id'   => $this->space['id'],
            'channel_id' => $this->channel['id'],
        ]);
        $countSecond = count(KnowledgeRepository::listEntries($this->space['id']));

        $this->assertSame($countFirst, $countSecond);
    }

    // ── Source links ─────────────────────────────────────

    public function test_extract_creates_source_links(): void
    {
        $msg = $this->createMessage($this->user['id'], $this->channel['id'], null,
            'Wir haben beschlossen, PHP 8.2 als Mindestversion zu verwenden für das Backend');

        $handler = new KnowledgeExtractHandler();
        $handler->handle([
            'space_id'   => $this->space['id'],
            'channel_id' => $this->channel['id'],
        ]);

        $knowledge = KnowledgeRepository::knowledgeForMessage($msg['id']);
        $this->assertNotEmpty($knowledge['entries']);
    }
}

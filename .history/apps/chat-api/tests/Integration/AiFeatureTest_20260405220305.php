<?php

declare(strict_types=1);

namespace Tests\Integration;

use App\Jobs\Handlers\AiEmbedHandler;
use App\Jobs\Handlers\AiExtractHandler;
use App\Jobs\Handlers\AiSummarizeChannelHandler;
use App\Jobs\Handlers\AiSummarizeThreadHandler;
use App\Repositories\AiRepository;
use App\Services\AiService;
use App\Support\Database;
use App\Support\HeuristicAiProvider;
use Tests\TestCase;

final class AiFeatureTest extends TestCase
{
    private array $admin;
    private array $user;
    private array $space;
    private array $channel;

    protected function setUp(): void
    {
        parent::setUp();
        $this->admin = $this->createUser(['display_name' => 'Admin']);
        $this->user = $this->createUser(['display_name' => 'Member']);
        $this->space = $this->createSpace($this->admin['id']);
        $this->addSpaceMember($this->space['id'], $this->user['id']);
        $this->channel = $this->createChannel($this->space['id'], $this->admin['id']);
        $this->addChannelMember($this->channel['id'], $this->user['id']);
        AiService::setProvider(new HeuristicAiProvider());
    }

    protected function tearDown(): void
    {
        AiService::setProvider(null);
        parent::tearDown();
    }

    // ── Config ───────────────────────────────────────────

    public function test_get_default_config(): void
    {
        $this->actingAs($this->user['id']);
        $config = AiService::getConfig($this->space['id'], $this->user['id']);
        $this->assertFalse($config['is_enabled']);
        $this->assertFalse($config['has_api_key']);
    }

    public function test_update_config_requires_admin(): void
    {
        $this->actingAs($this->user['id']);
        $this->assertApiException(403, 'FORBIDDEN', function () {
            AiService::updateConfig($this->space['id'], $this->user['id'], ['is_enabled' => true]);
        });
    }

    public function test_admin_can_update_config(): void
    {
        $this->actingAs($this->admin['id']);
        $config = AiService::updateConfig($this->space['id'], $this->admin['id'], [
            'provider' => 'openai',
            'model_summary' => 'gpt-4o',
            'is_enabled' => true,
            'temperature' => 0.5,
        ]);
        $this->assertTrue($config['is_enabled']);
        $this->assertSame('gpt-4o', $config['model_summary']);
        $this->assertArrayNotHasKey('api_key_enc', $config);
    }

    public function test_invalid_provider_rejected(): void
    {
        $this->actingAs($this->admin['id']);
        $this->assertApiException(400, 'INVALID_PROVIDER', function () {
            AiService::updateConfig($this->space['id'], $this->admin['id'], ['provider' => 'evil']);
        });
    }

    // ── Thread Summary ─────────────────────────────────

    public function test_summarize_thread(): void
    {
        $this->actingAs($this->user['id']);
        $root = $this->createMessage($this->admin['id'], $this->channel['id'], null, 'Wie sollen wir die Auth umsetzen?');
        $thread = $this->createThread($root['id'], $this->channel['id'], null, $this->admin['id']);
        $this->createMessage($this->user['id'], $this->channel['id'], null, 'JWT wäre eine Option für stateless Auth', $thread['id']);
        $this->createMessage($this->admin['id'], $this->channel['id'], null, 'Entschieden: session-based mit httponly cookies', $thread['id']);
        Database::connection()->prepare('UPDATE threads SET reply_count = 2 WHERE id = ?')->execute([$thread['id']]);

        $summary = AiService::summarizeThread($thread['id'], $this->space['id'], $this->user['id']);
        $this->assertSame('thread', $summary['scope_type']);
        $this->assertSame($thread['id'], $summary['scope_id']);
        $this->assertNotEmpty($summary['summary']);
        $this->assertNotEmpty($summary['key_points']);
        $this->assertGreaterThan(0, $summary['message_count']);
        $this->assertSame('heuristic', $summary['model']);
    }

    public function test_thread_summary_creates_action_items(): void
    {
        $this->actingAs($this->user['id']);
        $root = $this->createMessage($this->admin['id'], $this->channel['id'], null, 'Sprint Planning');
        $thread = $this->createThread($root['id'], $this->channel['id'], null, $this->admin['id']);
        $this->createMessage($this->user['id'], $this->channel['id'], null, 'TODO: API-Endpunkte für User-Profil erstellen', $thread['id']);
        $this->createMessage($this->admin['id'], $this->channel['id'], null, 'Aufgabe: Tests für Login-Flow schreiben', $thread['id']);
        Database::connection()->prepare('UPDATE threads SET reply_count = 2 WHERE id = ?')->execute([$thread['id']]);

        $summary = AiService::summarizeThread($thread['id'], $this->space['id'], $this->user['id']);
        $items = AiRepository::listActionItems($this->space['id'], null, $summary['id']);
        $this->assertGreaterThanOrEqual(1, count($items));
        $this->assertSame('open', $items[0]['status']);
        $this->assertNotEmpty($items[0]['title']);
    }

    public function test_thread_summary_links_sources(): void
    {
        $this->actingAs($this->user['id']);
        $root = $this->createMessage($this->admin['id'], $this->channel['id'], null, 'Diskussionspunkt');
        $thread = $this->createThread($root['id'], $this->channel['id'], null, $this->admin['id']);
        $this->createMessage($this->user['id'], $this->channel['id'], null, 'Antwort eins', $thread['id']);
        Database::connection()->prepare('UPDATE threads SET reply_count = 1 WHERE id = ?')->execute([$thread['id']]);

        $summary = AiService::summarizeThread($thread['id'], $this->space['id'], $this->user['id']);
        $sources = AiRepository::summarySourceMessages($summary['id']);
        $this->assertGreaterThan(0, count($sources));
        $this->assertSame($summary['id'], (int) $sources[0]['summary_id']);
    }

    public function test_thread_summary_empty_thread_fails(): void
    {
        $this->actingAs($this->user['id']);
        $root = $this->createMessage($this->admin['id'], $this->channel['id'], null, 'Leerer Thread');
        $thread = $this->createThread($root['id'], $this->channel['id'], null, $this->admin['id']);

        $this->assertApiException(400, 'NO_MESSAGES', function () use ($thread) {
            AiService::summarizeThread($thread['id'], $this->space['id'], $this->user['id']);
        });
    }

    public function test_thread_summary_nonexistent_thread_404(): void
    {
        $this->actingAs($this->user['id']);
        $this->assertApiException(404, 'NOT_FOUND', function () {
            AiService::summarizeThread(99999, $this->space['id'], $this->user['id']);
        });
    }

    // ── PLACEHOLDER_CHANNEL_TESTS ──
}

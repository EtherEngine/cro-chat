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

    // ── Channel Summary ─────────────────────────────────

    public function test_summarize_channel(): void
    {
        $this->actingAs($this->user['id']);
        $now = date('Y-m-d H:i:s');
        $this->createMessage($this->admin['id'], $this->channel['id'], null, 'Guten Morgen zusammen!');
        $this->createMessage($this->user['id'], $this->channel['id'], null, 'Morgen! Heute steht der Release an.');
        $this->createMessage($this->admin['id'], $this->channel['id'], null, 'Entschieden: wir deployen um 14 Uhr.');
        $this->createMessage($this->user['id'], $this->channel['id'], null, 'TODO: Release-Notes vorbereiten');
        $this->createMessage($this->admin['id'], $this->channel['id'], null, 'Alles klar, ich kümmere mich darum.');

        $start = date('Y-m-d 00:00:00');
        $end = date('Y-m-d 23:59:59');
        $summary = AiService::summarizeChannel($this->channel['id'], $this->space['id'], $this->user['id'], $start, $end);

        $this->assertSame('channel', $summary['scope_type']);
        $this->assertSame($this->channel['id'], $summary['scope_id']);
        $this->assertSame(5, $summary['message_count']);
        $this->assertNotEmpty($summary['summary']);
        $this->assertNotEmpty($summary['key_points']);
    }

    public function test_channel_summary_no_messages_fails(): void
    {
        $this->actingAs($this->user['id']);
        $this->assertApiException(400, 'NO_MESSAGES', function () {
            AiService::summarizeChannel($this->channel['id'], $this->space['id'], $this->user['id'], '2020-01-01 00:00:00', '2020-01-01 23:59:59');
        });
    }

    // ── Action Items ─────────────────────────────────────

    public function test_list_action_items(): void
    {
        $this->actingAs($this->user['id']);
        AiRepository::createActionItem([
            'space_id' => $this->space['id'],
            'title' => 'Tests schreiben',
            'confidence' => 0.85,
        ]);
        AiRepository::createActionItem([
            'space_id' => $this->space['id'],
            'title' => 'Docs aktualisieren',
            'confidence' => 0.70,
        ]);

        $items = AiService::listActionItems($this->space['id'], $this->user['id']);
        $this->assertCount(2, $items);
    }

    public function test_list_action_items_filter_by_status(): void
    {
        $this->actingAs($this->user['id']);
        $item = AiRepository::createActionItem([
            'space_id' => $this->space['id'],
            'title' => 'Erledigt',
            'confidence' => 0.85,
        ]);
        AiRepository::updateActionItemStatus($item['id'], 'done');
        AiRepository::createActionItem([
            'space_id' => $this->space['id'],
            'title' => 'Offen',
            'confidence' => 0.70,
        ]);

        $open = AiService::listActionItems($this->space['id'], $this->user['id'], 'open');
        $this->assertCount(1, $open);
        $this->assertSame('Offen', $open[0]['title']);

        $done = AiService::listActionItems($this->space['id'], $this->user['id'], 'done');
        $this->assertCount(1, $done);
    }

    public function test_update_action_item_status(): void
    {
        $this->actingAs($this->user['id']);
        $item = AiRepository::createActionItem([
            'space_id' => $this->space['id'],
            'title' => 'Fix Bug',
            'confidence' => 0.90,
        ]);

        $updated = AiService::updateActionItemStatus($item['id'], $this->user['id'], 'done');
        $this->assertSame('done', $updated['status']);
    }

    public function test_update_action_item_invalid_status(): void
    {
        $this->actingAs($this->user['id']);
        $item = AiRepository::createActionItem([
            'space_id' => $this->space['id'],
            'title' => 'Test',
            'confidence' => 0.80,
        ]);

        $this->assertApiException(400, 'INVALID_STATUS', function () use ($item) {
            AiService::updateActionItemStatus($item['id'], $this->user['id'], 'invalid');
        });
    }

    // ── Semantic Search ─────────────────────────────────

    public function test_semantic_search_with_embeddings(): void
    {
        $this->actingAs($this->user['id']);
        $m1 = $this->createMessage($this->admin['id'], $this->channel['id'], null, 'PHP ist eine großartige Programmiersprache für Webentwicklung');
        $m2 = $this->createMessage($this->user['id'], $this->channel['id'], null, 'TypeScript bietet starke Typisierung für Frontend-Projekte');
        $m3 = $this->createMessage($this->admin['id'], $this->channel['id'], null, 'MariaDB ist unsere bevorzugte Datenbank');

        AiService::embedMessage($this->space['id'], $m1['id'], $m1['body']);
        AiService::embedMessage($this->space['id'], $m2['id'], $m2['body']);
        AiService::embedMessage($this->space['id'], $m3['id'], $m3['body']);

        $results = AiService::semanticSearch($this->space['id'], $this->user['id'], 'Programmierung');
        $this->assertIsArray($results);
        $this->assertGreaterThan(0, count($results));
        $this->assertArrayHasKey('message_id', $results[0]);
        $this->assertArrayHasKey('similarity', $results[0]);
    }

    public function test_semantic_search_fallback_text_search(): void
    {
        $this->actingAs($this->user['id']);
        $this->createMessage($this->admin['id'], $this->channel['id'], null, 'Architektur Entscheidung zum API Design');
        $this->createMessage($this->user['id'], $this->channel['id'], null, 'Deployment Pipeline konfigurieren');

        // No embeddings → fallback to FULLTEXT
        $results = AiService::semanticSearch($this->space['id'], $this->user['id'], 'Architektur');
        $this->assertIsArray($results);
    }

    public function test_semantic_search_too_short_query(): void
    {
        $this->actingAs($this->user['id']);
        $this->assertApiException(400, 'QUERY_TOO_SHORT', function () {
            AiService::semanticSearch($this->space['id'], $this->user['id'], 'a');
        });
    }

    public function test_embedding_upsert(): void
    {
        $this->actingAs($this->user['id']);
        $msg = $this->createMessage($this->admin['id'], $this->channel['id'], null, 'Test Nachricht für Embedding');
        AiService::embedMessage($this->space['id'], $msg['id'], $msg['body']);

        $emb = AiRepository::getEmbedding($msg['id']);
        $this->assertNotNull($emb);
        $this->assertSame('heuristic', $emb['model']);
        $this->assertSame(64, (int) $emb['dimensions']);

        // Upsert: should overwrite without error
        AiService::embedMessage($this->space['id'], $msg['id'], $msg['body'] . ' aktualisiert');
        $count = AiRepository::countEmbeddings($this->space['id']);
        $this->assertSame(1, $count);
    }

    // ── PLACEHOLDER_SUGGEST_TESTS ──
}

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

    // ── Reply Suggestions ───────────────────────────────

    public function test_suggest_replies_for_thread(): void
    {
        $this->actingAs($this->user['id']);
        $root = $this->createMessage($this->admin['id'], $this->channel['id'], null, 'Was haltet ihr vom neuen Feature?');
        $thread = $this->createThread($root['id'], $this->channel['id'], null, $this->admin['id']);
        $this->createMessage($this->user['id'], $this->channel['id'], null, 'Klingt gut, aber wie ist die Performance?', $thread['id']);
        Database::connection()->prepare('UPDATE threads SET reply_count = 1 WHERE id = ?')->execute([$thread['id']]);

        $result = AiService::suggest($this->space['id'], $this->user['id'], 'thread', $thread['id']);
        $this->assertNotEmpty($result['suggestions']);
        $this->assertIsArray($result['suggestions']);
        $this->assertGreaterThanOrEqual(2, count($result['suggestions']));
        $this->assertSame('heuristic', $result['model']);
    }

    public function test_suggest_replies_for_channel(): void
    {
        $this->actingAs($this->user['id']);
        $this->createMessage($this->admin['id'], $this->channel['id'], null, 'Wir haben entschieden, REST statt GraphQL zu nutzen.');

        $result = AiService::suggest($this->space['id'], $this->user['id'], 'channel', $this->channel['id']);
        $this->assertNotEmpty($result['suggestions']);
    }

    public function test_accept_suggestion(): void
    {
        $this->actingAs($this->user['id']);
        $this->createMessage($this->admin['id'], $this->channel['id'], null, 'Frage: wann ist der nächste Release?');

        $suggestion = AiService::suggest($this->space['id'], $this->user['id'], 'channel', $this->channel['id']);
        $accepted = AiService::acceptSuggestion($suggestion['id'], $this->user['id'], 0);
        $this->assertSame(0, $accepted['accepted_index']);
    }

    public function test_accept_suggestion_wrong_user(): void
    {
        $this->actingAs($this->user['id']);
        $this->createMessage($this->admin['id'], $this->channel['id'], null, 'Hallo');

        $suggestion = AiService::suggest($this->space['id'], $this->user['id'], 'channel', $this->channel['id']);

        $this->assertApiException(403, 'FORBIDDEN', function () use ($suggestion) {
            AiService::acceptSuggestion($suggestion['id'], $this->admin['id'], 0);
        });
    }

    public function test_accept_suggestion_invalid_index(): void
    {
        $this->actingAs($this->user['id']);
        $this->createMessage($this->admin['id'], $this->channel['id'], null, 'Hallo');

        $suggestion = AiService::suggest($this->space['id'], $this->user['id'], 'channel', $this->channel['id']);

        $this->assertApiException(400, 'INVALID_INDEX', function () use ($suggestion) {
            AiService::acceptSuggestion($suggestion['id'], $this->user['id'], 99);
        });
    }

    public function test_suggest_invalid_scope(): void
    {
        $this->actingAs($this->user['id']);
        $this->assertApiException(400, 'INVALID_SCOPE', function () {
            AiService::suggest($this->space['id'], $this->user['id'], 'invalid', 1);
        });
    }

    // ── Summary CRUD ───────────────────────────────────

    public function test_list_summaries(): void
    {
        $this->actingAs($this->user['id']);
        AiRepository::createSummary([
            'space_id' => $this->space['id'],
            'scope_type' => 'thread',
            'scope_id' => 1,
            'summary' => 'Test Thread Summary',
            'model' => 'heuristic',
        ]);
        AiRepository::createSummary([
            'space_id' => $this->space['id'],
            'scope_type' => 'channel',
            'scope_id' => $this->channel['id'],
            'summary' => 'Test Channel Summary',
            'model' => 'heuristic',
        ]);

        $all = AiService::listSummaries($this->space['id'], $this->user['id']);
        $this->assertCount(2, $all);

        $threadsOnly = AiService::listSummaries($this->space['id'], $this->user['id'], 'thread');
        $this->assertCount(1, $threadsOnly);
    }

    public function test_get_summary_with_sources(): void
    {
        $this->actingAs($this->user['id']);
        $msg = $this->createMessage($this->admin['id'], $this->channel['id'], null, 'Quell-Nachricht');
        $summary = AiRepository::createSummary([
            'space_id' => $this->space['id'],
            'scope_type' => 'channel',
            'scope_id' => $this->channel['id'],
            'summary' => 'Zusammenfassung mit Quellen',
            'model' => 'heuristic',
        ]);
        AiRepository::addSummarySources($summary['id'], [$msg['id']]);

        $detail = AiService::getSummary($summary['id'], $this->user['id']);
        $this->assertSame($summary['id'], $detail['id']);
        $this->assertArrayHasKey('sources', $detail);
        $this->assertCount(1, $detail['sources']);
    }

    public function test_delete_summary_requires_admin(): void
    {
        $this->actingAs($this->user['id']);
        $summary = AiRepository::createSummary([
            'space_id' => $this->space['id'],
            'scope_type' => 'thread',
            'scope_id' => 1,
            'summary' => 'Zu löschende Zusammenfassung',
            'model' => 'heuristic',
        ]);

        $this->assertApiException(403, 'FORBIDDEN', function () use ($summary) {
            AiService::deleteSummary($summary['id'], $this->user['id']);
        });
    }

    public function test_admin_can_delete_summary(): void
    {
        $this->actingAs($this->admin['id']);
        $summary = AiRepository::createSummary([
            'space_id' => $this->space['id'],
            'scope_type' => 'thread',
            'scope_id' => 1,
            'summary' => 'Wird gelöscht',
            'model' => 'heuristic',
        ]);

        AiService::deleteSummary($summary['id'], $this->admin['id']);
        $this->assertNull(AiRepository::findSummary($summary['id']));
    }

    // ── Job Handlers ────────────────────────────────────

    public function test_ai_summarize_thread_handler(): void
    {
        $root = $this->createMessage($this->admin['id'], $this->channel['id'], null, 'Handler-Test Thread');
        $thread = $this->createThread($root['id'], $this->channel['id'], null, $this->admin['id']);
        $this->createMessage($this->user['id'], $this->channel['id'], null, 'Handler-Test Antwort mit TODO: Handler-Test prüfen', $thread['id']);
        Database::connection()->prepare('UPDATE threads SET reply_count = 1 WHERE id = ?')->execute([$thread['id']]);

        $handler = new AiSummarizeThreadHandler();
        $handler->handle([
            'thread_id' => $thread['id'],
            'space_id' => $this->space['id'],
            'user_id' => $this->admin['id'],
        ]);

        $summaries = AiRepository::listSummaries($this->space['id'], 'thread', $thread['id']);
        $this->assertCount(1, $summaries);
        $this->assertSame('heuristic', $summaries[0]['model']);
    }

    public function test_ai_summarize_channel_handler(): void
    {
        $this->createMessage($this->admin['id'], $this->channel['id'], null, 'Channel Handler Nachricht 1');
        $this->createMessage($this->user['id'], $this->channel['id'], null, 'Channel Handler Nachricht 2');
        $this->createMessage($this->admin['id'], $this->channel['id'], null, 'Entschieden: Handler funktioniert');

        $handler = new AiSummarizeChannelHandler();
        $handler->handle([
            'channel_id' => $this->channel['id'],
            'space_id' => $this->space['id'],
            'user_id' => $this->admin['id'],
        ]);

        $summaries = AiRepository::listSummaries($this->space['id'], 'channel', $this->channel['id']);
        $this->assertCount(1, $summaries);
    }

    public function test_ai_extract_handler(): void
    {
        $this->createMessage($this->admin['id'], $this->channel['id'], null, 'TODO: Dokumentation schreiben für neues Feature');
        $this->createMessage($this->user['id'], $this->channel['id'], null, 'Aufgabe: Performance-Tests durchführen');
        $this->createMessage($this->admin['id'], $this->channel['id'], null, 'Hallo Welt');

        $handler = new AiExtractHandler();
        $handler->handle([
            'space_id' => $this->space['id'],
            'channel_id' => $this->channel['id'],
            'user_id' => $this->admin['id'],
        ]);

        $items = AiRepository::listActionItems($this->space['id']);
        $this->assertGreaterThanOrEqual(1, count($items));
    }

    public function test_ai_embed_handler(): void
    {
        $this->createMessage($this->admin['id'], $this->channel['id'], null, 'Eine ausreichend lange Nachricht für Embedding-Generierung Test');
        $this->createMessage($this->user['id'], $this->channel['id'], null, 'Noch eine Nachricht mit genug Inhalt für den Test');

        $handler = new AiEmbedHandler();
        $handler->handle([
            'space_id' => $this->space['id'],
            'channel_id' => $this->channel['id'],
            'user_id' => $this->admin['id'],
        ]);

        $count = AiRepository::countEmbeddings($this->space['id']);
        $this->assertGreaterThanOrEqual(2, $count);
    }

    public function test_ai_extract_handler_skips_no_messages(): void
    {
        $handler = new AiExtractHandler();
        $handler->handle([
            'space_id' => $this->space['id'],
            'channel_id' => $this->channel['id'],
            'user_id' => $this->admin['id'],
        ]);
        $items = AiRepository::listActionItems($this->space['id']);
        $this->assertCount(0, $items);
    }

    public function test_ai_summarize_thread_handler_invalid_payload(): void
    {
        $handler = new AiSummarizeThreadHandler();
        // Should not throw, just log warning and return
        $handler->handle(['thread_id' => 0, 'space_id' => 0, 'user_id' => 0]);
        $this->assertTrue(true); // No exception = pass
    }

    // ── Stats ────────────────────────────────────────────

    public function test_stats(): void
    {
        $this->actingAs($this->user['id']);
        AiRepository::createSummary([
            'space_id' => $this->space['id'],
            'scope_type' => 'thread',
            'scope_id' => 1,
            'summary' => 'Stats Test',
            'model' => 'heuristic',
        ]);
        AiRepository::createActionItem([
            'space_id' => $this->space['id'],
            'title' => 'Stats Item',
            'confidence' => 0.80,
        ]);

        $stats = AiService::stats($this->space['id'], $this->user['id']);
        $this->assertSame(1, $stats['summaries']);
        $this->assertSame(1, $stats['action_items']);
        $this->assertSame(1, $stats['action_items_open']);
        $this->assertSame(0, $stats['embeddings']);
        $this->assertSame(0, $stats['suggestions']);
    }

    // ── Heuristic Provider ───────────────────────────────

    public function test_heuristic_provider_summarize(): void
    {
        $provider = new HeuristicAiProvider();
        $messages = [
            ['body' => 'Das ist die erste Nachricht zum Thema', 'user_id' => 1, 'display_name' => 'Alice', 'created_at' => '2025-01-01'],
            ['body' => 'Entschieden: wir nehmen Option A', 'user_id' => 2, 'display_name' => 'Bob', 'created_at' => '2025-01-01'],
        ];
        $result = $provider->summarize($messages, 'thread');
        $this->assertNotEmpty($result['summary']);
        $this->assertSame('heuristic', $result['model']);
        $this->assertSame(0, $result['tokens_used']);
    }

    public function test_heuristic_provider_embed(): void
    {
        $provider = new HeuristicAiProvider();
        $result = $provider->embed('PHP Programmierung Web Framework Architektur');
        $this->assertCount(64, $result['embedding']);
        $this->assertSame(64, $result['dimensions']);
        $this->assertSame('heuristic', $result['model']);
    }

    public function test_heuristic_provider_suggest_question(): void
    {
        $provider = new HeuristicAiProvider();
        $messages = [
            ['body' => 'Was ist der beste Ansatz für Caching?', 'user_id' => 1, 'display_name' => 'Alice', 'created_at' => '2025-01-01'],
        ];
        $result = $provider->suggest($messages, 'Bob');
        $this->assertNotEmpty($result['suggestions']);
        $this->assertGreaterThanOrEqual(2, count($result['suggestions']));
    }

    public function test_heuristic_provider_extract_actions(): void
    {
        $provider = new HeuristicAiProvider();
        $messages = [
            ['body' => 'TODO: Login-Seite erstellen und testen', 'user_id' => 1, 'display_name' => 'Alice', 'created_at' => '2025-01-01'],
            ['body' => 'Aufgabe: API-Dokumentation aktualisieren und prüfen', 'user_id' => 2, 'display_name' => 'Bob', 'created_at' => '2025-01-01'],
            ['body' => 'Hallo!', 'user_id' => 1, 'display_name' => 'Alice', 'created_at' => '2025-01-01'],
        ];
        $result = $provider->extractActions($messages);
        $this->assertGreaterThanOrEqual(2, count($result['items']));
        $this->assertArrayHasKey('title', $result['items'][0]);
        $this->assertArrayHasKey('confidence', $result['items'][0]);
    }

    // ── Permission ───────────────────────────────────────

    public function test_non_member_cannot_access_ai(): void
    {
        $outsider = $this->createUser(['display_name' => 'Outsider']);
        $this->actingAs($outsider['id']);
        $this->assertApiException(403, 'FORBIDDEN', function () use ($outsider) {
            AiService::getConfig($this->space['id'], $outsider['id']);
        });
    }
}

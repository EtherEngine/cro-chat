<?php

declare(strict_types=1);

namespace Tests\Integration;

use App\Repositories\KnowledgeRepository;
use App\Services\KnowledgeService;
use Tests\TestCase;

/**
 * Tests for Knowledge Layer: Topics, Decisions, Entries, Search.
 */
final class KnowledgeTest extends TestCase
{
    private array $user;
    private array $admin;
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
    }

    // ── Topics ───────────────────────────────────────────

    public function test_create_topic(): void
    {
        $this->actingAs($this->user['id']);
        $topic = KnowledgeService::createTopic($this->space['id'], $this->user['id'], [
            'name' => 'Backend-Architektur',
            'description' => 'Alles zur PHP-Backend-Architektur',
        ]);

        $this->assertSame('Backend-Architektur', $topic['name']);
        $this->assertSame('backend-architektur', $topic['slug']);
        $this->assertSame($this->space['id'], $topic['space_id']);
        $this->assertNotEmpty($topic['id']);
    }

    public function test_create_duplicate_topic_fails(): void
    {
        $this->actingAs($this->user['id']);
        KnowledgeService::createTopic($this->space['id'], $this->user['id'], [
            'name' => 'Deployment',
        ]);

        $this->assertApiException(409, 'TOPIC_EXISTS', function () {
            KnowledgeService::createTopic($this->space['id'], $this->user['id'], [
                'name' => 'Deployment',
            ]);
        });
    }

    public function test_list_topics(): void
    {
        $this->actingAs($this->user['id']);
        KnowledgeService::createTopic($this->space['id'], $this->user['id'], ['name' => 'Topic A']);
        KnowledgeService::createTopic($this->space['id'], $this->user['id'], ['name' => 'Topic B']);

        $topics = KnowledgeService::listTopics($this->space['id'], $this->user['id']);
        $this->assertCount(2, $topics);
    }

    public function test_update_topic_requires_admin(): void
    {
        $this->actingAs($this->user['id']);
        $topic = KnowledgeService::createTopic($this->space['id'], $this->user['id'], ['name' => 'Old']);

        $this->assertApiException(403, 'ADMIN_REQUIRED', function () use ($topic) {
            KnowledgeService::updateTopic($topic['id'], $this->user['id'], ['name' => 'New']);
        });
    }

    public function test_admin_can_update_topic(): void
    {
        $topic = KnowledgeService::createTopic($this->space['id'], $this->admin['id'], ['name' => 'Old']);
        $updated = KnowledgeService::updateTopic($topic['id'], $this->admin['id'], ['name' => 'New Name']);

        $this->assertSame('New Name', $updated['name']);
    }

    public function test_delete_topic_requires_admin(): void
    {
        $topic = KnowledgeService::createTopic($this->space['id'], $this->user['id'], ['name' => 'ToDelete']);

        $this->assertApiException(403, 'ADMIN_REQUIRED', function () use ($topic) {
            KnowledgeService::deleteTopic($topic['id'], $this->user['id']);
        });
    }

    public function test_admin_can_delete_topic(): void
    {
        $topic = KnowledgeService::createTopic($this->space['id'], $this->admin['id'], ['name' => 'ToDelete']);
        KnowledgeService::deleteTopic($topic['id'], $this->admin['id']);

        $this->assertApiException(404, 'TOPIC_NOT_FOUND', function () use ($topic) {
            KnowledgeService::getTopic($topic['id'], $this->admin['id']);
        });
    }

    public function test_non_member_cannot_list_topics(): void
    {
        $outsider = $this->createUser(['display_name' => 'Outsider']);

        $this->assertApiException(403, 'SPACE_MEMBER_REQUIRED', function () use ($outsider) {
            KnowledgeService::listTopics($this->space['id'], $outsider['id']);
        });
    }

    // ── Decisions ────────────────────────────────────────

    public function test_create_decision(): void
    {
        $decision = KnowledgeService::createDecision($this->space['id'], $this->user['id'], [
            'title' => 'React statt Vue verwenden',
            'description' => 'Aufgrund besserer Ökosystem-Unterstützung',
            'status' => 'accepted',
        ]);

        $this->assertSame('React statt Vue verwenden', $decision['title']);
        $this->assertSame('accepted', $decision['status']);
        $this->assertSame($this->user['id'], $decision['decided_by']);
    }

    public function test_create_decision_with_source_message(): void
    {
        $msg = $this->createMessage($this->user['id'], $this->channel['id'], null, 'Wir nehmen React.');

        $decision = KnowledgeService::createDecision($this->space['id'], $this->user['id'], [
            'title' => 'React verwenden',
            'source_message_id' => $msg['id'],
        ]);

        $this->assertSame($msg['id'], $decision['source_message_id']);
    }

    public function test_list_decisions_filter_by_status(): void
    {
        KnowledgeService::createDecision($this->space['id'], $this->user['id'], [
            'title' => 'A',
            'status' => 'accepted',
        ]);
        KnowledgeService::createDecision($this->space['id'], $this->user['id'], [
            'title' => 'B',
            'status' => 'proposed',
        ]);

        $accepted = KnowledgeService::listDecisions($this->space['id'], $this->user['id'], null, 'accepted');
        $this->assertCount(1, $accepted);
        $this->assertSame('A', $accepted[0]['title']);
    }

    public function test_update_decision_status(): void
    {
        $d = KnowledgeService::createDecision($this->space['id'], $this->user['id'], [
            'title' => 'Proposed',
            'status' => 'proposed',
        ]);

        $updated = KnowledgeService::updateDecision($d['id'], $this->user['id'], [
            'status' => 'rejected',
        ]);

        $this->assertSame('rejected', $updated['status']);
    }

    public function test_decision_invalid_status_fails(): void
    {
        $this->assertApiException(422, 'DECISION_STATUS_INVALID', function () {
            KnowledgeService::createDecision($this->space['id'], $this->user['id'], [
                'title' => 'Bad',
                'status' => 'invalid',
            ]);
        });
    }

    // ── Knowledge Entries ────────────────────────────────

    public function test_create_entry(): void
    {
        $entry = KnowledgeService::createEntry($this->space['id'], $this->user['id'], [
            'title' => 'PHP 8.2 Features',
            'content' => 'Readonly classes, Disjunctive Normal Form Types, ...',
            'entry_type' => 'fact',
            'tags' => ['php', 'backend'],
        ]);

        $this->assertSame('PHP 8.2 Features', $entry['title']);
        $this->assertSame('fact', $entry['entry_type']);
        $this->assertSame('manual', $entry['extracted_by']);
        $this->assertSame(['php', 'backend'], $entry['tags']);
    }

    public function test_create_entry_with_source_links(): void
    {
        $msg = $this->createMessage($this->user['id'], $this->channel['id'], null, 'PHP 8.2 hat readonly classes');

        $entry = KnowledgeService::createEntry($this->space['id'], $this->user['id'], [
            'title' => 'Readonly Classes',
            'content' => 'PHP 8.2 hat readonly classes',
            'source_message_id' => $msg['id'],
        ]);

        $full = KnowledgeService::getEntry($entry['id'], $this->user['id']);
        $this->assertNotEmpty($full['sources']);
        $this->assertSame($msg['id'], (int) $full['sources'][0]['message_id']);
    }

    public function test_list_entries_filter_by_type(): void
    {
        KnowledgeService::createEntry($this->space['id'], $this->user['id'], [
            'title' => 'Fact',
            'content' => 'A fact',
            'entry_type' => 'fact',
        ]);
        KnowledgeService::createEntry($this->space['id'], $this->user['id'], [
            'title' => 'HowTo',
            'content' => 'A howto',
            'entry_type' => 'howto',
        ]);

        $facts = KnowledgeService::listEntries($this->space['id'], $this->user['id'], null, 'fact');
        $this->assertCount(1, $facts);
        $this->assertSame('Fact', $facts[0]['title']);
    }

    public function test_update_entry(): void
    {
        $entry = KnowledgeService::createEntry($this->space['id'], $this->user['id'], [
            'title' => 'Old',
            'content' => 'Old content',
        ]);

        $updated = KnowledgeService::updateEntry($entry['id'], $this->user['id'], [
            'title' => 'Updated Title',
            'tags' => ['new-tag'],
        ]);

        $this->assertSame('Updated Title', $updated['title']);
        $this->assertSame(['new-tag'], $updated['tags']);
    }

    public function test_entry_validation(): void
    {
        $this->assertApiException(422, 'ENTRY_TITLE_INVALID', function () {
            KnowledgeService::createEntry($this->space['id'], $this->user['id'], [
                'title' => '',
                'content' => 'x',
            ]);
        });

        $this->assertApiException(422, 'ENTRY_CONTENT_EMPTY', function () {
            KnowledgeService::createEntry($this->space['id'], $this->user['id'], [
                'title' => 'Valid Title',
                'content' => '',
            ]);
        });

        $this->assertApiException(422, 'ENTRY_TYPE_INVALID', function () {
            KnowledgeService::createEntry($this->space['id'], $this->user['id'], [
                'title' => 'x',
                'content' => 'x',
                'entry_type' => 'invalid',
            ]);
        });
    }

    // ── Search ───────────────────────────────────────────

    public function test_search_entries(): void
    {
        KnowledgeService::createEntry($this->space['id'], $this->user['id'], [
            'title' => 'MariaDB Performance Tuning',
            'content' => 'Indizes und Query-Optimierung für MariaDB',
        ]);
        KnowledgeService::createEntry($this->space['id'], $this->user['id'], [
            'title' => 'React Hooks',
            'content' => 'useState, useEffect und Custom Hooks',
        ]);

        $results = KnowledgeService::search($this->space['id'], $this->user['id'], 'MariaDB');
        $this->assertNotEmpty($results);
        $this->assertStringContainsString('MariaDB', $results[0]['title']);
    }

    public function test_search_too_short_fails(): void
    {
        $this->assertApiException(422, 'QUERY_TOO_SHORT', function () {
            KnowledgeService::search($this->space['id'], $this->user['id'], 'x');
        });
    }

    // ── Message Knowledge Link ───────────────────────────

    public function test_knowledge_for_message(): void
    {
        $msg = $this->createMessage($this->user['id'], $this->channel['id'], null, 'Test msg');

        $entry = KnowledgeService::createEntry($this->space['id'], $this->user['id'], [
            'title' => 'Linked',
            'content' => 'Content',
            'source_message_id' => $msg['id'],
        ]);

        $knowledge = KnowledgeService::forMessage($msg['id'], $this->user['id']);
        $this->assertArrayHasKey('entries', $knowledge);
        $this->assertArrayHasKey('summaries', $knowledge);
        $this->assertArrayHasKey('decisions', $knowledge);
        $this->assertNotEmpty($knowledge['entries']);
    }

    // ── Summaries ────────────────────────────────────────

    public function test_create_and_list_summaries(): void
    {
        // Directly create a summary via repository (summaries are async-generated)
        $summary = KnowledgeRepository::createSummary([
            'space_id' => $this->space['id'],
            'scope_type' => 'thread',
            'scope_id' => 999,
            'title' => 'Thread Summary',
            'summary' => 'Discussion about X.',
            'key_points' => ['Point A', 'Point B'],
            'participants' => [$this->user['id']],
            'message_count' => 5,
        ]);

        $list = KnowledgeService::listSummaries($this->space['id'], $this->user['id'], 'thread');
        $this->assertCount(1, $list);
        $this->assertSame('Thread Summary', $list[0]['title']);
    }

    public function test_get_summary_with_sources(): void
    {
        $msg = $this->createMessage($this->user['id'], $this->channel['id'], null, 'source msg');
        $summary = KnowledgeRepository::createSummary([
            'space_id' => $this->space['id'],
            'scope_type' => 'daily',
            'scope_id' => $this->channel['id'],
            'title' => 'Daily Summary',
            'summary' => 'Today was busy.',
            'message_count' => 1,
        ]);
        KnowledgeRepository::addSource([
            'summary_id' => $summary['id'],
            'message_id' => $msg['id'],
        ]);

        $full = KnowledgeService::getSummary($summary['id'], $this->user['id']);
        $this->assertNotEmpty($full['sources']);
    }

    public function test_delete_summary_requires_admin(): void
    {
        $summary = KnowledgeRepository::createSummary([
            'space_id' => $this->space['id'],
            'scope_type' => 'thread',
            'scope_id' => 1,
            'title' => 'X',
            'summary' => 'Y',
            'message_count' => 0,
        ]);

        $this->assertApiException(403, 'ADMIN_REQUIRED', function () use ($summary) {
            KnowledgeService::deleteSummary($summary['id'], $this->user['id']);
        });
    }

    // ── Async generation triggers ────────────────────────

    public function test_request_channel_summary_dispatches_job(): void
    {
        $result = KnowledgeService::requestChannelSummary($this->channel['id'], $this->user['id']);
        $this->assertSame('queued', $result['status']);
        $this->assertSame($this->channel['id'], $result['channel_id']);
    }

    public function test_request_extraction_requires_admin(): void
    {
        $this->assertApiException(403, 'ADMIN_REQUIRED', function () {
            KnowledgeService::requestExtraction($this->space['id'], $this->user['id']);
        });
    }

    public function test_admin_can_request_extraction(): void
    {
        $result = KnowledgeService::requestExtraction($this->space['id'], $this->admin['id']);
        $this->assertSame('queued', $result['status']);
    }
}

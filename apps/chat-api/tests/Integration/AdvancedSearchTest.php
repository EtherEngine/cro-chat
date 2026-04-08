<?php

declare(strict_types=1);

namespace Tests\Integration;

use App\Repositories\SearchRepository;
use App\Services\MessageService;
use App\Services\SearchService;
use Tests\TestCase;

final class AdvancedSearchTest extends TestCase
{
    // -- Advanced Search: Ranking --

    public function test_advanced_search_returns_ranked_results(): void
    {
        $alice = $this->createUser(['display_name' => 'Alice']);
        $space = $this->createSpace($alice['id']);
        $ch = $this->createChannel($space['id'], $alice['id'], ['name' => 'general']);

        $this->actingAs($alice['id']);
        MessageService::createChannel($ch['id'], $alice['id'], ['body' => 'Ranking Testbegriff ganz wichtig hier']);
        MessageService::createChannel($ch['id'], $alice['id'], ['body' => 'Noch ein Ranking Testbegriff Satz']);
        MessageService::createChannel($ch['id'], $alice['id'], ['body' => 'Etwas komplett anderes']);

        $result = SearchService::advancedSearch($alice['id'], 'Testbegriff');
        $this->assertSame(2, $result['total']);
        $this->assertCount(2, $result['messages']);
        $this->assertArrayHasKey('relevance', $result['messages'][0]);
        $this->assertArrayHasKey('has_more', $result);
        $this->assertFalse($result['has_more']);
    }

    public function test_advanced_search_sort_newest(): void
    {
        $alice = $this->createUser(['display_name' => 'Alice']);
        $space = $this->createSpace($alice['id']);
        $ch = $this->createChannel($space['id'], $alice['id'], ['name' => 'general']);

        $this->actingAs($alice['id']);
        $msg1 = MessageService::createChannel($ch['id'], $alice['id'], ['body' => 'Sortierung erste Nachricht']);
        $msg2 = MessageService::createChannel($ch['id'], $alice['id'], ['body' => 'Sortierung zweite Nachricht']);

        $result = SearchService::advancedSearch($alice['id'], 'Sortierung', ['sort' => 'newest']);
        $this->assertCount(2, $result['messages']);
        $this->assertSame((int) $msg2['id'], (int) $result['messages'][0]['id']);
    }

    public function test_advanced_search_sort_oldest(): void
    {
        $alice = $this->createUser(['display_name' => 'Alice']);
        $space = $this->createSpace($alice['id']);
        $ch = $this->createChannel($space['id'], $alice['id'], ['name' => 'general']);

        $this->actingAs($alice['id']);
        $msg1 = MessageService::createChannel($ch['id'], $alice['id'], ['body' => 'Chronologisch erste Meldung']);
        $msg2 = MessageService::createChannel($ch['id'], $alice['id'], ['body' => 'Chronologisch zweite Meldung']);

        $result = SearchService::advancedSearch($alice['id'], 'Chronologisch', ['sort' => 'oldest']);
        $this->assertCount(2, $result['messages']);
        $this->assertSame((int) $msg1['id'], (int) $result['messages'][0]['id']);
    }

    // -- Facets --

    public function test_advanced_search_returns_channel_facets(): void
    {
        $alice = $this->createUser(['display_name' => 'Alice']);
        $space = $this->createSpace($alice['id']);
        $ch1 = $this->createChannel($space['id'], $alice['id'], ['name' => 'dev']);
        $ch2 = $this->createChannel($space['id'], $alice['id'], ['name' => 'design']);

        $this->actingAs($alice['id']);
        MessageService::createChannel($ch1['id'], $alice['id'], ['body' => 'Facettenbegriff im Dev']);
        MessageService::createChannel($ch1['id'], $alice['id'], ['body' => 'Facettenbegriff nochmal Dev']);
        MessageService::createChannel($ch2['id'], $alice['id'], ['body' => 'Facettenbegriff im Design']);

        $result = SearchService::advancedSearch($alice['id'], 'Facettenbegriff');
        $this->assertArrayHasKey('facets', $result);
        $this->assertArrayHasKey('channels', $result['facets']);
        $this->assertArrayHasKey('authors', $result['facets']);
        $this->assertArrayHasKey('dates', $result['facets']);
        $this->assertCount(2, $result['facets']['channels']);
    }

    public function test_advanced_search_returns_author_facets(): void
    {
        $alice = $this->createUser(['display_name' => 'Alice']);
        $bob = $this->createUser(['display_name' => 'Bob']);
        $space = $this->createSpace($alice['id']);
        $this->addSpaceMember($space['id'], $bob['id']);
        $ch = $this->createChannel($space['id'], $alice['id'], ['name' => 'general']);
        $this->addChannelMember($ch['id'], $bob['id']);

        $this->actingAs($alice['id']);
        MessageService::createChannel($ch['id'], $alice['id'], ['body' => 'Autorenfacette Alice schreibt']);
        MessageService::createChannel($ch['id'], $alice['id'], ['body' => 'Autorenfacette Alice nochmal']);
        $this->actingAs($bob['id']);
        MessageService::createChannel($ch['id'], $bob['id'], ['body' => 'Autorenfacette Bob schreibt']);

        $result = SearchService::advancedSearch($alice['id'], 'Autorenfacette');
        $this->assertCount(2, $result['facets']['authors']);
    }

    public function test_advanced_search_date_facets(): void
    {
        $alice = $this->createUser(['display_name' => 'Alice']);
        $space = $this->createSpace($alice['id']);
        $ch = $this->createChannel($space['id'], $alice['id'], ['name' => 'general']);

        $this->actingAs($alice['id']);
        MessageService::createChannel($ch['id'], $alice['id'], ['body' => 'Datumsfacette Nachricht heute']);

        $result = SearchService::advancedSearch($alice['id'], 'Datumsfacette');
        $this->assertSame(1, $result['facets']['dates']['today']);
        $this->assertSame(1, $result['facets']['dates']['week']);
        $this->assertSame(1, $result['facets']['dates']['month']);
    }
    // -- Highlighting --

    public function test_advanced_search_returns_highlights(): void
    {
        $alice = $this->createUser(['display_name' => 'Alice']);
        $space = $this->createSpace($alice['id']);
        $ch = $this->createChannel($space['id'], $alice['id'], ['name' => 'general']);

        $this->actingAs($alice['id']);
        MessageService::createChannel($ch['id'], $alice['id'], ['body' => 'Der Highlighttest funktioniert super']);

        $result = SearchService::advancedSearch($alice['id'], 'Highlighttest');
        $this->assertCount(1, $result['messages']);
        $this->assertArrayHasKey('highlights', $result['messages'][0]);
        $highlights = $result['messages'][0]['highlights'];
        $this->assertNotEmpty($highlights);
        $this->assertSame('Highlighttest', $highlights[0]['term']);
        $this->assertArrayHasKey('offset', $highlights[0]);
        $this->assertArrayHasKey('length', $highlights[0]);
    }

    public function test_highlights_multiple_occurrences(): void
    {
        $alice = $this->createUser(['display_name' => 'Alice']);
        $space = $this->createSpace($alice['id']);
        $ch = $this->createChannel($space['id'], $alice['id'], ['name' => 'general']);

        $this->actingAs($alice['id']);
        MessageService::createChannel($ch['id'], $alice['id'], ['body' => 'Mehrfach Mehrfach Mehrfach']);

        $result = SearchService::advancedSearch($alice['id'], 'Mehrfach');
        $this->assertCount(3, $result['messages'][0]['highlights']);
    }

    // -- Filters --

    public function test_advanced_search_filter_by_author(): void
    {
        $alice = $this->createUser(['display_name' => 'Alice']);
        $bob = $this->createUser(['display_name' => 'Bob']);
        $space = $this->createSpace($alice['id']);
        $this->addSpaceMember($space['id'], $bob['id']);
        $ch = $this->createChannel($space['id'], $alice['id'], ['name' => 'general']);
        $this->addChannelMember($ch['id'], $bob['id']);

        $this->actingAs($alice['id']);
        MessageService::createChannel($ch['id'], $alice['id'], ['body' => 'Autorenfilter Alice Nachricht']);
        $this->actingAs($bob['id']);
        MessageService::createChannel($ch['id'], $bob['id'], ['body' => 'Autorenfilter Bob Nachricht']);

        $result = SearchService::advancedSearch($alice['id'], 'Autorenfilter', ['author_id' => $bob['id']]);
        $this->assertSame(1, $result['total']);
        $this->assertSame($bob['id'], (int) $result['messages'][0]['user_id']);
    }

    public function test_advanced_search_filter_by_channel(): void
    {
        $alice = $this->createUser(['display_name' => 'Alice']);
        $space = $this->createSpace($alice['id']);
        $ch1 = $this->createChannel($space['id'], $alice['id'], ['name' => 'eins']);
        $ch2 = $this->createChannel($space['id'], $alice['id'], ['name' => 'zwei']);

        $this->actingAs($alice['id']);
        MessageService::createChannel($ch1['id'], $alice['id'], ['body' => 'Channelfilter in eins']);
        MessageService::createChannel($ch2['id'], $alice['id'], ['body' => 'Channelfilter in zwei']);

        $result = SearchService::advancedSearch($alice['id'], 'Channelfilter', ['channel_id' => $ch1['id']]);
        $this->assertSame(1, $result['total']);
    }

    public function test_advanced_search_filter_has_attachment(): void
    {
        $alice = $this->createUser(['display_name' => 'Alice']);
        $space = $this->createSpace($alice['id']);
        $ch = $this->createChannel($space['id'], $alice['id'], ['name' => 'general']);

        $this->actingAs($alice['id']);
        $msg = MessageService::createChannel($ch['id'], $alice['id'], ['body' => 'Anhangfilter Nachricht mit Datei']);
        MessageService::createChannel($ch['id'], $alice['id'], ['body' => 'Anhangfilter Nachricht ohne Datei']);

        $this->createAttachment($msg['id'], $alice['id']);

        $result = SearchService::advancedSearch($alice['id'], 'Anhangfilter', ['has_attachment' => true]);
        $this->assertSame(1, $result['total']);
        $this->assertSame((int) $msg['id'], (int) $result['messages'][0]['id']);
    }

    public function test_advanced_search_filter_in_thread(): void
    {
        $alice = $this->createUser(['display_name' => 'Alice']);
        $space = $this->createSpace($alice['id']);
        $ch = $this->createChannel($space['id'], $alice['id'], ['name' => 'general']);

        $this->actingAs($alice['id']);
        $root = MessageService::createChannel($ch['id'], $alice['id'], ['body' => 'Threadfilter Stammnachricht']);
        $thread = $this->createThread($root['id'], $ch['id'], null, $alice['id']);
        $this->createMessage($alice['id'], $ch['id'], null, 'Threadfilter Antwort im Thread', $thread['id']);

        $result = SearchService::advancedSearch($alice['id'], 'Threadfilter', ['in_thread' => true]);
        $this->assertSame(1, $result['total']);
        $this->assertNotNull($result['messages'][0]['thread_id']);
    }

    public function test_advanced_search_filter_date_range(): void
    {
        $alice = $this->createUser(['display_name' => 'Alice']);
        $space = $this->createSpace($alice['id']);
        $ch = $this->createChannel($space['id'], $alice['id'], ['name' => 'general']);

        $this->actingAs($alice['id']);
        MessageService::createChannel($ch['id'], $alice['id'], ['body' => 'Datumsbereich Nachricht heute']);

        $today = date('Y-m-d');
        $tomorrow = date('Y-m-d', strtotime('+1 day'));
        $yesterday = date('Y-m-d', strtotime('-1 day'));

        $result = SearchService::advancedSearch($alice['id'], 'Datumsbereich', ['after' => $yesterday, 'before' => $tomorrow]);
        $this->assertSame(1, $result['total']);

        $result2 = SearchService::advancedSearch($alice['id'], 'Datumsbereich', ['after' => $tomorrow]);
        $this->assertSame(0, $result2['total']);
    }
    // -- Pagination --

    public function test_advanced_search_pagination(): void
    {
        $alice = $this->createUser(['display_name' => 'Alice']);
        $space = $this->createSpace($alice['id']);
        $ch = $this->createChannel($space['id'], $alice['id'], ['name' => 'general']);

        $this->actingAs($alice['id']);
        for ($i = 0; $i < 5; $i++) {
            MessageService::createChannel($ch['id'], $alice['id'], ['body' => "Paginierung Nachricht Nummer $i"]);
        }

        $page1 = SearchService::advancedSearch($alice['id'], 'Paginierung', ['per_page' => 2, 'page' => 1]);
        $this->assertCount(2, $page1['messages']);
        $this->assertTrue($page1['has_more']);
        $this->assertSame(5, $page1['total']);
        $this->assertSame(1, $page1['page']);

        $page2 = SearchService::advancedSearch($alice['id'], 'Paginierung', ['per_page' => 2, 'page' => 2]);
        $this->assertCount(2, $page2['messages']);
        $this->assertTrue($page2['has_more']);

        $page3 = SearchService::advancedSearch($alice['id'], 'Paginierung', ['per_page' => 2, 'page' => 3]);
        $this->assertCount(1, $page3['messages']);
        $this->assertFalse($page3['has_more']);
    }

    // -- Permissions --

    public function test_advanced_search_respects_permissions(): void
    {
        $alice = $this->createUser(['display_name' => 'Alice']);
        $bob = $this->createUser(['display_name' => 'Bob']);
        $space = $this->createSpace($alice['id']);
        $this->addSpaceMember($space['id'], $bob['id']);

        $privateCh = $this->createChannel($space['id'], $alice['id'], ['name' => 'secret', 'is_private' => 1]);

        $this->actingAs($alice['id']);
        MessageService::createChannel($privateCh['id'], $alice['id'], ['body' => 'Berechtigungstest geheime Info']);

        $aliceResult = SearchService::advancedSearch($alice['id'], 'Berechtigungstest');
        $bobResult = SearchService::advancedSearch($bob['id'], 'Berechtigungstest');

        $this->assertSame(1, $aliceResult['total']);
        $this->assertSame(0, $bobResult['total']);
    }

    public function test_advanced_search_dm_permissions(): void
    {
        $alice = $this->createUser(['display_name' => 'Alice']);
        $bob = $this->createUser(['display_name' => 'Bob']);
        $carol = $this->createUser(['display_name' => 'Carol']);
        $space = $this->createSpace($alice['id']);
        $this->addSpaceMember($space['id'], $bob['id']);
        $this->addSpaceMember($space['id'], $carol['id']);

        $conv = $this->createConversation($space['id'], [$alice['id'], $bob['id']]);

        $this->actingAs($alice['id']);
        $this->createMessage($alice['id'], null, $conv['id'], 'DMBerechtigungstest private Nachricht');

        $aliceResult = SearchService::advancedSearch($alice['id'], 'DMBerechtigungstest');
        $bobResult = SearchService::advancedSearch($bob['id'], 'DMBerechtigungstest');
        $carolResult = SearchService::advancedSearch($carol['id'], 'DMBerechtigungstest');

        $this->assertSame(1, $aliceResult['total']);
        $this->assertSame(1, $bobResult['total']);
        $this->assertSame(0, $carolResult['total']);
    }

    // -- Validation --

    public function test_advanced_search_rejects_short_query(): void
    {
        $alice = $this->createUser(['display_name' => 'Alice']);
        $this->assertApiException(422, 'SEARCH_QUERY_TOO_SHORT', fn() =>
            SearchService::advancedSearch($alice['id'], 'a')
        );
    }

    public function test_advanced_search_rejects_invalid_date(): void
    {
        $alice = $this->createUser(['display_name' => 'Alice']);
        $this->assertApiException(422, 'SEARCH_INVALID_DATE', fn() =>
            SearchService::advancedSearch($alice['id'], 'Testsuche', ['after' => 'not-a-date'])
        );
    }

    public function test_advanced_search_records_history(): void
    {
        $alice = $this->createUser(['display_name' => 'Alice']);
        $space = $this->createSpace($alice['id']);
        $ch = $this->createChannel($space['id'], $alice['id'], ['name' => 'general']);

        $this->actingAs($alice['id']);
        MessageService::createChannel($ch['id'], $alice['id'], ['body' => 'Historieneintrag Nachricht hier']);

        SearchService::advancedSearch($alice['id'], 'Historieneintrag');
        $history = SearchService::history($alice['id']);
        $this->assertCount(1, $history);
        $this->assertSame('Historieneintrag', $history[0]['query']);
    }
    // -- Saved Searches --

    public function test_create_saved_search(): void
    {
        $alice = $this->createUser(['display_name' => 'Alice']);
        $space = $this->createSpace($alice['id']);

        $saved = SearchService::createSavedSearch($alice['id'], $space['id'], [
            'name' => 'Meine Suche',
            'query' => 'Testbegriff',
            'filters' => ['channel_id' => 1],
            'notify' => true,
        ]);

        $this->assertSame('Meine Suche', $saved['name']);
        $this->assertSame('Testbegriff', $saved['query']);
        $this->assertTrue($saved['notify']);
        $this->assertSame(['channel_id' => 1], $saved['filters']);
    }

    public function test_list_saved_searches(): void
    {
        $alice = $this->createUser(['display_name' => 'Alice']);
        $space = $this->createSpace($alice['id']);

        SearchService::createSavedSearch($alice['id'], $space['id'], ['name' => 'S1', 'query' => 'eins']);
        SearchService::createSavedSearch($alice['id'], $space['id'], ['name' => 'S2', 'query' => 'zwei']);

        $list = SearchService::listSavedSearches($alice['id']);
        $this->assertCount(2, $list);
    }

    public function test_get_saved_search(): void
    {
        $alice = $this->createUser(['display_name' => 'Alice']);
        $space = $this->createSpace($alice['id']);

        $saved = SearchService::createSavedSearch($alice['id'], $space['id'], ['name' => 'Test', 'query' => 'ab']);

        $fetched = SearchService::getSavedSearch($saved['id'], $alice['id']);
        $this->assertSame($saved['id'], $fetched['id']);
    }

    public function test_get_saved_search_forbidden_for_other_user(): void
    {
        $alice = $this->createUser(['display_name' => 'Alice']);
        $bob = $this->createUser(['display_name' => 'Bob']);
        $space = $this->createSpace($alice['id']);

        $saved = SearchService::createSavedSearch($alice['id'], $space['id'], ['name' => 'Test', 'query' => 'ab']);

        $this->assertApiException(404, 'SAVED_SEARCH_NOT_FOUND', fn() =>
            SearchService::getSavedSearch($saved['id'], $bob['id'])
        );
    }

    public function test_update_saved_search(): void
    {
        $alice = $this->createUser(['display_name' => 'Alice']);
        $space = $this->createSpace($alice['id']);

        $saved = SearchService::createSavedSearch($alice['id'], $space['id'], ['name' => 'Alt', 'query' => 'alt']);
        $updated = SearchService::updateSavedSearch($saved['id'], $alice['id'], ['name' => 'Neu', 'query' => 'neu']);

        $this->assertSame('Neu', $updated['name']);
        $this->assertSame('neu', $updated['query']);
    }

    public function test_delete_saved_search(): void
    {
        $alice = $this->createUser(['display_name' => 'Alice']);
        $space = $this->createSpace($alice['id']);

        $saved = SearchService::createSavedSearch($alice['id'], $space['id'], ['name' => 'Test', 'query' => 'ab']);
        SearchService::deleteSavedSearch($saved['id'], $alice['id']);

        $this->assertApiException(404, 'SAVED_SEARCH_NOT_FOUND', fn() =>
            SearchService::getSavedSearch($saved['id'], $alice['id'])
        );
    }

    public function test_saved_search_requires_space_membership(): void
    {
        $alice = $this->createUser(['display_name' => 'Alice']);
        $bob = $this->createUser(['display_name' => 'Bob']);
        $space = $this->createSpace($alice['id']);

        $this->assertApiException(403, 'SPACE_MEMBER_REQUIRED', fn() =>
            SearchService::createSavedSearch($bob['id'], $space['id'], ['name' => 'Test', 'query' => 'ab'])
        );
    }

    public function test_saved_search_limit_per_user(): void
    {
        $alice = $this->createUser(['display_name' => 'Alice']);
        $space = $this->createSpace($alice['id']);

        for ($i = 0; $i < 50; $i++) {
            SearchService::createSavedSearch($alice['id'], $space['id'], ['name' => "S$i", 'query' => "query$i"]);
        }

        $this->assertApiException(422, 'SAVED_SEARCH_LIMIT', fn() =>
            SearchService::createSavedSearch($alice['id'], $space['id'], ['name' => 'Overflow', 'query' => 'overflow'])
        );
    }

    public function test_saved_search_name_validation(): void
    {
        $alice = $this->createUser(['display_name' => 'Alice']);
        $space = $this->createSpace($alice['id']);

        $this->assertApiException(422, 'SAVED_SEARCH_NAME_INVALID', fn() =>
            SearchService::createSavedSearch($alice['id'], $space['id'], ['name' => '', 'query' => 'ab'])
        );
    }

    public function test_execute_saved_search(): void
    {
        $alice = $this->createUser(['display_name' => 'Alice']);
        $space = $this->createSpace($alice['id']);
        $ch = $this->createChannel($space['id'], $alice['id'], ['name' => 'general']);

        $this->actingAs($alice['id']);
        MessageService::createChannel($ch['id'], $alice['id'], ['body' => 'Gespeicherte Suche Treffer']);

        $saved = SearchService::createSavedSearch($alice['id'], $space['id'], [
            'name' => 'Meine Suche',
            'query' => 'Gespeicherte',
        ]);

        $result = SearchService::executeSavedSearch($saved['id'], $alice['id']);
        $this->assertSame(1, $result['total']);

        // Verify last_run_at was updated
        $refreshed = SearchService::getSavedSearch($saved['id'], $alice['id']);
        $this->assertNotNull($refreshed['last_run_at']);
    }

    public function test_execute_saved_search_with_overrides(): void
    {
        $alice = $this->createUser(['display_name' => 'Alice']);
        $space = $this->createSpace($alice['id']);
        $ch = $this->createChannel($space['id'], $alice['id'], ['name' => 'general']);

        $this->actingAs($alice['id']);
        for ($i = 0; $i < 3; $i++) {
            MessageService::createChannel($ch['id'], $alice['id'], ['body' => "Ueberschrieben Nachricht $i"]);
        }

        $saved = SearchService::createSavedSearch($alice['id'], $space['id'], [
            'name' => 'Override',
            'query' => 'Ueberschrieben',
        ]);

        $result = SearchService::executeSavedSearch($saved['id'], $alice['id'], ['page' => 1, 'sort' => 'oldest']);
        $this->assertSame(3, $result['total']);
        $this->assertSame(1, $result['page']);
    }
    // -- History & Suggest --

    public function test_history_list(): void
    {
        $alice = $this->createUser(['display_name' => 'Alice']);
        $space = $this->createSpace($alice['id']);
        $ch = $this->createChannel($space['id'], $alice['id'], ['name' => 'general']);

        $this->actingAs($alice['id']);
        MessageService::createChannel($ch['id'], $alice['id'], ['body' => 'Verlauf eins zwei drei']);

        SearchService::advancedSearch($alice['id'], 'Verlauf');
        SearchService::advancedSearch($alice['id'], 'eins');

        $history = SearchService::history($alice['id']);
        $this->assertCount(2, $history);
        $this->assertSame('eins', $history[0]['query']);
    }

    public function test_clear_history(): void
    {
        $alice = $this->createUser(['display_name' => 'Alice']);
        $space = $this->createSpace($alice['id']);
        $ch = $this->createChannel($space['id'], $alice['id'], ['name' => 'general']);

        $this->actingAs($alice['id']);
        MessageService::createChannel($ch['id'], $alice['id'], ['body' => 'Loeschbar eins zwei drei']);

        SearchService::advancedSearch($alice['id'], 'Loeschbar');
        $this->assertCount(1, SearchService::history($alice['id']));

        SearchService::clearHistory($alice['id']);
        $this->assertCount(0, SearchService::history($alice['id']));
    }

    public function test_suggest_autocomplete(): void
    {
        $alice = $this->createUser(['display_name' => 'Alice']);
        $space = $this->createSpace($alice['id']);
        $ch = $this->createChannel($space['id'], $alice['id'], ['name' => 'general']);

        $this->actingAs($alice['id']);
        MessageService::createChannel($ch['id'], $alice['id'], ['body' => 'Vorschlag Alpha Beta Gamma']);

        SearchService::advancedSearch($alice['id'], 'Vorschlag');
        SearchService::advancedSearch($alice['id'], 'Vorschlag Alpha');
        SearchService::advancedSearch($alice['id'], 'Beta');

        $suggestions = SearchService::suggest($alice['id'], 'Vor');
        $this->assertCount(2, $suggestions);
    }

    public function test_suggest_empty_prefix_returns_empty(): void
    {
        $alice = $this->createUser(['display_name' => 'Alice']);
        $suggestions = SearchService::suggest($alice['id'], '');
        $this->assertCount(0, $suggestions);
    }

    public function test_history_isolated_per_user(): void
    {
        $alice = $this->createUser(['display_name' => 'Alice']);
        $bob = $this->createUser(['display_name' => 'Bob']);
        $space = $this->createSpace($alice['id']);
        $this->addSpaceMember($space['id'], $bob['id']);
        $ch = $this->createChannel($space['id'], $alice['id'], ['name' => 'general']);
        $this->addChannelMember($ch['id'], $bob['id']);

        $this->actingAs($alice['id']);
        MessageService::createChannel($ch['id'], $alice['id'], ['body' => 'Isolation Nachricht fuer Test']);

        SearchService::advancedSearch($alice['id'], 'Isolation');
        SearchService::advancedSearch($bob['id'], 'Isolation');

        $aliceHist = SearchService::history($alice['id']);
        $bobHist = SearchService::history($bob['id']);
        $this->assertCount(1, $aliceHist);
        $this->assertCount(1, $bobHist);

        SearchService::clearHistory($alice['id']);
        $this->assertCount(0, SearchService::history($alice['id']));
        $this->assertCount(1, SearchService::history($bob['id']));
    }

    // -- Repository direct tests --

    public function test_build_highlights_returns_offset_length(): void
    {
        $highlights = SearchRepository::buildHighlights('Hello World Hello', ['Hello']);
        $this->assertCount(2, $highlights);
        $this->assertSame(0, $highlights[0]['offset']);
        $this->assertSame(5, $highlights[0]['length']);
        $this->assertSame(12, $highlights[1]['offset']);
    }

    public function test_to_fulltext_query_converts_words(): void
    {
        $ft = SearchRepository::toFulltextQuery('hello world');
        $this->assertSame('+hello* +world*', $ft);
    }

    public function test_extract_terms_splits_words(): void
    {
        $terms = SearchRepository::extractTerms('foo bar  baz');
        $this->assertSame(['foo', 'bar', 'baz'], $terms);
    }
}
<?php

declare(strict_types=1);

namespace Tests\Integration;

use App\Repositories\RichContentRepository;
use App\Services\RichContentService;
use Tests\TestCase;

/**
 * Integration tests for Rich Content: Markdown, Snippets, Link Previews, Shared Drafts.
 */
final class RichContentTest extends TestCase
{
    private array $user;
    private array $user2;
    private array $space;
    private array $channel;

    protected function setUp(): void
    {
        parent::setUp();
        $this->user = $this->createUser();
        $this->user2 = $this->createUser();
        $this->space = $this->createSpace($this->user['id']);
        $this->addSpaceMember($this->space['id'], $this->user2['id']);
        $this->channel = $this->createChannel($this->space['id'], $this->user['id']);
        $this->addChannelMember($this->channel['id'], $this->user2['id']);
        $this->actingAs($this->user['id']);
    }

    // ══════════════════════════════════════════════════════════
    // Markdown / Rendering
    // ══════════════════════════════════════════════════════════

    public function test_analyze_content_detects_markdown(): void
    {
        $analysis = RichContentService::analyzeContent('Hello **world** with `code`');
        $this->assertTrue($analysis['has_markdown']);
        $this->assertFalse($analysis['has_code_blocks']);
        $this->assertEmpty($analysis['urls']);
    }

    public function test_analyze_content_detects_code_blocks(): void
    {
        $body = "Look at this:\n```php\necho 'hi';\n```";
        $analysis = RichContentService::analyzeContent($body);
        $this->assertTrue($analysis['has_code_blocks']);
        $this->assertCount(1, $analysis['code_blocks']);
        $this->assertSame('php', $analysis['code_blocks'][0]['language']);
    }

    public function test_analyze_content_extracts_urls(): void
    {
        $body = 'Check https://example.com and https://other.org/page out';
        $analysis = RichContentService::analyzeContent($body);
        $this->assertCount(2, $analysis['urls']);
        $this->assertSame('https://example.com', $analysis['urls'][0]);
    }

    public function test_render_markdown_bold_and_italic(): void
    {
        $html = RichContentService::renderMarkdown('**bold** and *italic*');
        $this->assertStringContainsString('<strong>bold</strong>', $html);
        $this->assertStringContainsString('<em>italic</em>', $html);
    }

    public function test_render_markdown_code_blocks(): void
    {
        $body = "```javascript\nconsole.log('hi');\n```";
        $html = RichContentService::renderMarkdown($body);
        $this->assertStringContainsString('<pre><code class="language-javascript">', $html);
    }

    public function test_render_markdown_links(): void
    {
        $html = RichContentService::renderMarkdown('[click](https://example.com)');
        $this->assertStringContainsString('href="https://example.com"', $html);
        $this->assertStringContainsString('rel="noopener noreferrer"', $html);
    }

    public function test_sanitize_strips_script_tags(): void
    {
        $html = RichContentService::sanitizeHtml('<p>safe</p><script>alert("xss")</script>');
        $this->assertStringNotContainsString('script', $html);
        $this->assertStringContainsString('safe', $html);
    }

    public function test_sanitize_strips_event_handlers(): void
    {
        $html = RichContentService::sanitizeHtml('<img src="x" onerror="alert(1)">');
        $this->assertStringNotContainsString('onerror', $html);
    }

    public function test_sanitize_blocks_javascript_uri(): void
    {
        $html = RichContentService::sanitizeHtml('<a href="javascript:alert(1)">x</a>');
        $this->assertStringNotContainsString('javascript:', $html);
    }

    public function test_supported_languages(): void
    {
        $langs = RichContentService::supportedLanguages();
        $this->assertContains('php', $langs);
        $this->assertContains('javascript', $langs);
        $this->assertContains('python', $langs);
    }

    // ══════════════════════════════════════════════════════════
    // Snippets
    // ══════════════════════════════════════════════════════════

    public function test_create_snippet(): void
    {
        $snippet = RichContentService::createSnippet($this->space['id'], $this->user['id'], [
            'title' => 'My Snippet',
            'content' => 'console.log("hello");',
            'language' => 'javascript',
            'description' => 'A test snippet',
        ]);

        $this->assertSame('My Snippet', $snippet['title']);
        $this->assertSame('javascript', $snippet['language']);
        $this->assertSame($this->user['id'], $snippet['user_id']);
        $this->assertTrue($snippet['is_public']);
    }

    public function test_create_snippet_empty_title_fails(): void
    {
        $this->assertApiException(
            422,
            'SNIPPET_TITLE_INVALID',
            fn() =>
            RichContentService::createSnippet($this->space['id'], $this->user['id'], [
                'title' => '',
                'content' => 'some code',
            ])
        );
    }

    public function test_create_snippet_empty_content_fails(): void
    {
        $this->assertApiException(
            422,
            'SNIPPET_CONTENT_EMPTY',
            fn() =>
            RichContentService::createSnippet($this->space['id'], $this->user['id'], [
                'title' => 'Test',
                'content' => '',
            ])
        );
    }

    public function test_create_snippet_invalid_language_defaults_to_text(): void
    {
        $snippet = RichContentService::createSnippet($this->space['id'], $this->user['id'], [
            'title' => 'Test',
            'content' => 'code',
            'language' => 'invalid_lang_xyz',
        ]);
        $this->assertSame('text', $snippet['language']);
    }

    public function test_get_snippet(): void
    {
        $snippet = RichContentService::createSnippet($this->space['id'], $this->user['id'], [
            'title' => 'Get Me',
            'content' => 'data',
        ]);

        $fetched = RichContentService::getSnippet($snippet['id'], $this->user['id']);
        $this->assertSame('Get Me', $fetched['title']);
    }

    public function test_list_snippets(): void
    {
        RichContentService::createSnippet($this->space['id'], $this->user['id'], [
            'title' => 'S1',
            'content' => 'a',
            'language' => 'php'
        ]);
        RichContentService::createSnippet($this->space['id'], $this->user['id'], [
            'title' => 'S2',
            'content' => 'b',
            'language' => 'javascript'
        ]);

        $all = RichContentService::listSnippets($this->space['id'], $this->user['id']);
        $this->assertCount(2, $all['data']);

        $phpOnly = RichContentService::listSnippets($this->space['id'], $this->user['id'], 'php');
        $this->assertCount(1, $phpOnly['data']);
        $this->assertSame('S1', $phpOnly['data'][0]['title']);
    }

    public function test_update_snippet(): void
    {
        $snippet = RichContentService::createSnippet($this->space['id'], $this->user['id'], [
            'title' => 'Old',
            'content' => 'old code'
        ]);

        $updated = RichContentService::updateSnippet($snippet['id'], $this->user['id'], [
            'title' => 'New Title',
            'language' => 'python',
        ]);

        $this->assertSame('New Title', $updated['title']);
        $this->assertSame('python', $updated['language']);
    }

    public function test_delete_snippet(): void
    {
        $snippet = RichContentService::createSnippet($this->space['id'], $this->user['id'], [
            'title' => 'Del',
            'content' => 'x'
        ]);

        RichContentService::deleteSnippet($snippet['id'], $this->user['id']);

        $this->assertApiException(
            404,
            'SNIPPET_NOT_FOUND',
            fn() =>
            RichContentService::getSnippet($snippet['id'], $this->user['id'])
        );
    }

    public function test_delete_snippet_by_other_user_fails(): void
    {
        $snippet = RichContentService::createSnippet($this->space['id'], $this->user['id'], [
            'title' => 'Mine',
            'content' => 'x'
        ]);

        $this->actingAs($this->user2['id']);
        $this->assertApiException(
            403,
            'SNIPPET_DELETE_DENIED',
            fn() =>
            RichContentService::deleteSnippet($snippet['id'], $this->user2['id'])
        );
    }

    public function test_private_snippet_not_visible_to_others(): void
    {
        $snippet = RichContentService::createSnippet($this->space['id'], $this->user['id'], [
            'title' => 'Secret',
            'content' => 'hidden',
            'is_public' => false,
        ]);

        $this->actingAs($this->user2['id']);
        $this->assertApiException(
            403,
            'SNIPPET_ACCESS_DENIED',
            fn() =>
            RichContentService::getSnippet($snippet['id'], $this->user2['id'])
        );
    }

    public function test_non_member_cannot_create_snippet(): void
    {
        $outsider = $this->createUser();
        $this->actingAs($outsider['id']);
        $this->assertApiException(
            403,
            'SPACE_MEMBER_REQUIRED',
            fn() =>
            RichContentService::createSnippet($this->space['id'], $outsider['id'], [
                'title' => 'Nope',
                'content' => 'x'
            ])
        );
    }
    // ══════════════════════════════════════════════════════════
    // Link Previews
    // ══════════════════════════════════════════════════════════

    public function test_extract_urls_from_body(): void
    {
        $urls = RichContentRepository::extractUrls('Visit https://example.com and http://test.org/path');
        $this->assertCount(2, $urls);
        $this->assertSame('https://example.com', $urls[0]);
    }

    public function test_extract_urls_ignores_invalid(): void
    {
        $urls = RichContentRepository::extractUrls('No urls here, just text');
        $this->assertEmpty($urls);
    }

    public function test_create_link_preview_pending(): void
    {
        $msg = $this->createMessage($this->user['id'], $this->channel['id'], null, 'Check https://example.com');
        $preview = RichContentRepository::createLinkPreview($msg['id'], 'https://example.com');

        $this->assertSame('pending', $preview['status']);
        $this->assertSame($msg['id'], $preview['message_id']);
        $this->assertSame('https://example.com', $preview['url']);
    }

    public function test_resolve_link_preview(): void
    {
        $msg = $this->createMessage($this->user['id'], $this->channel['id'], null, 'Check https://example.com');
        $preview = RichContentRepository::createLinkPreview($msg['id'], 'https://example.com');

        RichContentRepository::resolveLinkPreview($preview['id'], [
            'title' => 'Example Domain',
            'description' => 'This domain is for examples.',
            'site_name' => 'Example',
            'image_url' => null,
            'content_type' => 'text/html',
        ]);

        $resolved = RichContentRepository::findLinkPreview($preview['id']);
        $this->assertSame('resolved', $resolved['status']);
        $this->assertSame('Example Domain', $resolved['title']);
        $this->assertSame('Example', $resolved['site_name']);
    }

    public function test_fail_link_preview(): void
    {
        $msg = $this->createMessage($this->user['id'], $this->channel['id'], null, 'Check https://bad.example');
        $preview = RichContentRepository::createLinkPreview($msg['id'], 'https://bad.example');

        RichContentRepository::failLinkPreview($preview['id'], 'Connection timeout');

        $failed = RichContentRepository::findLinkPreview($preview['id']);
        $this->assertSame('failed', $failed['status']);
        $this->assertSame('Connection timeout', $failed['error_message']);
    }

    public function test_for_message_returns_previews(): void
    {
        $msg = $this->createMessage($this->user['id'], $this->channel['id'], null, 'links');
        RichContentRepository::createLinkPreview($msg['id'], 'https://a.com');
        RichContentRepository::createLinkPreview($msg['id'], 'https://b.com');

        $previews = RichContentRepository::forMessage($msg['id']);
        $this->assertCount(2, $previews);
    }

    public function test_batch_for_messages(): void
    {
        $msg1 = $this->createMessage($this->user['id'], $this->channel['id'], null, 'link1');
        $msg2 = $this->createMessage($this->user['id'], $this->channel['id'], null, 'link2');

        $p1 = RichContentRepository::createLinkPreview($msg1['id'], 'https://a.com');
        RichContentRepository::resolveLinkPreview($p1['id'], ['title' => 'A', 'description' => null, 'image_url' => null, 'site_name' => null, 'content_type' => null]);

        $p2 = RichContentRepository::createLinkPreview($msg2['id'], 'https://b.com');
        // p2 stays pending — should NOT appear in batch

        $map = RichContentRepository::forMessages([$msg1['id'], $msg2['id']]);
        $this->assertArrayHasKey($msg1['id'], $map);
        $this->assertCount(1, $map[$msg1['id']]);
        $this->assertArrayNotHasKey($msg2['id'], $map); // pending excluded
    }

    // ══════════════════════════════════════════════════════════
    // Shared Drafts
    // ══════════════════════════════════════════════════════════

    public function test_create_draft(): void
    {
        $draft = RichContentService::createDraft($this->space['id'], $this->user['id'], [
            'title' => 'My Draft',
            'body' => '# Hello World',
            'format' => 'markdown',
            'channel_id' => $this->channel['id'],
        ]);

        $this->assertSame('My Draft', $draft['title']);
        $this->assertSame('# Hello World', $draft['body']);
        $this->assertSame('markdown', $draft['format']);
        $this->assertFalse($draft['is_shared']);
        $this->assertSame(1, $draft['version']);
    }

    public function test_create_draft_empty_body_fails(): void
    {
        $this->assertApiException(
            422,
            'DRAFT_BODY_EMPTY',
            fn() =>
            RichContentService::createDraft($this->space['id'], $this->user['id'], [
                'body' => '',
            ])
        );
    }

    public function test_get_draft(): void
    {
        $draft = RichContentService::createDraft($this->space['id'], $this->user['id'], [
            'body' => 'Draft text',
        ]);
        $fetched = RichContentService::getDraft($draft['id'], $this->user['id']);
        $this->assertSame('Draft text', $fetched['body']);
    }

    public function test_list_own_drafts(): void
    {
        RichContentService::createDraft($this->space['id'], $this->user['id'], ['body' => 'A']);
        RichContentService::createDraft($this->space['id'], $this->user['id'], ['body' => 'B']);
        RichContentService::createDraft($this->space['id'], $this->user2['id'], ['body' => 'C']);

        $own = RichContentService::listDrafts($this->space['id'], $this->user['id']);
        $this->assertCount(2, $own);
    }

    public function test_update_draft_increments_version(): void
    {
        $draft = RichContentService::createDraft($this->space['id'], $this->user['id'], [
            'body' => 'v1',
        ]);
        $this->assertSame(1, $draft['version']);

        $updated = RichContentService::updateDraft($draft['id'], $this->user['id'], [
            'body' => 'v2 content',
        ]);
        $this->assertSame(2, $updated['version']);
        $this->assertSame('v2 content', $updated['body']);
    }

    public function test_delete_draft(): void
    {
        $draft = RichContentService::createDraft($this->space['id'], $this->user['id'], [
            'body' => 'delete me',
        ]);
        RichContentService::deleteDraft($draft['id'], $this->user['id']);

        $this->assertApiException(
            404,
            'DRAFT_NOT_FOUND',
            fn() =>
            RichContentService::getDraft($draft['id'], $this->user['id'])
        );
    }

    public function test_other_user_cannot_delete_draft(): void
    {
        $draft = RichContentService::createDraft($this->space['id'], $this->user['id'], [
            'body' => 'mine',
        ]);

        $this->assertApiException(
            403,
            'DRAFT_DELETE_DENIED',
            fn() =>
            RichContentService::deleteDraft($draft['id'], $this->user2['id'])
        );
    }

    public function test_share_draft_and_add_collaborator(): void
    {
        $draft = RichContentService::createDraft($this->space['id'], $this->user['id'], [
            'body' => 'shared content',
        ]);

        // Share it
        $shared = RichContentService::updateDraft($draft['id'], $this->user['id'], [
            'is_shared' => true,
        ]);
        $this->assertTrue($shared['is_shared']);

        // Add collaborator
        RichContentService::addCollaborator($draft['id'], $this->user['id'], $this->user2['id'], 'edit');

        // Collaborator can read
        $fetched = RichContentService::getDraft($draft['id'], $this->user2['id']);
        $this->assertSame('shared content', $fetched['body']);
        $this->assertCount(1, $fetched['collaborators']);
    }

    public function test_collaborator_with_edit_can_update(): void
    {
        $draft = RichContentService::createDraft($this->space['id'], $this->user['id'], [
            'body' => 'original',
        ]);
        RichContentService::updateDraft($draft['id'], $this->user['id'], ['is_shared' => true]);
        RichContentService::addCollaborator($draft['id'], $this->user['id'], $this->user2['id'], 'edit');

        $updated = RichContentService::updateDraft($draft['id'], $this->user2['id'], [
            'body' => 'edited by collaborator',
        ]);
        $this->assertSame('edited by collaborator', $updated['body']);
    }

    public function test_collaborator_with_view_cannot_edit(): void
    {
        $draft = RichContentService::createDraft($this->space['id'], $this->user['id'], [
            'body' => 'original',
        ]);
        RichContentService::updateDraft($draft['id'], $this->user['id'], ['is_shared' => true]);
        RichContentService::addCollaborator($draft['id'], $this->user['id'], $this->user2['id'], 'view');

        $this->assertApiException(
            403,
            'DRAFT_EDIT_DENIED',
            fn() =>
            RichContentService::updateDraft($draft['id'], $this->user2['id'], ['body' => 'nope'])
        );
    }

    public function test_add_collaborator_to_non_shared_draft_fails(): void
    {
        $draft = RichContentService::createDraft($this->space['id'], $this->user['id'], [
            'body' => 'private',
        ]);

        $this->assertApiException(
            422,
            'DRAFT_NOT_SHARED',
            fn() =>
            RichContentService::addCollaborator($draft['id'], $this->user['id'], $this->user2['id'])
        );
    }

    public function test_remove_collaborator(): void
    {
        $draft = RichContentService::createDraft($this->space['id'], $this->user['id'], [
            'body' => 'shared',
        ]);
        RichContentService::updateDraft($draft['id'], $this->user['id'], ['is_shared' => true]);
        RichContentService::addCollaborator($draft['id'], $this->user['id'], $this->user2['id'], 'edit');

        RichContentService::removeCollaborator($draft['id'], $this->user['id'], $this->user2['id']);

        $fetched = RichContentService::getDraft($draft['id'], $this->user['id']);
        $this->assertCount(0, $fetched['collaborators']);
    }

    public function test_publish_draft_creates_message(): void
    {
        $draft = RichContentService::createDraft($this->space['id'], $this->user['id'], [
            'body' => 'Published message body',
            'channel_id' => $this->channel['id'],
        ]);

        $message = RichContentService::publishDraft($draft['id'], $this->user['id']);
        $this->assertSame('Published message body', $message['body']);
        $this->assertSame($this->channel['id'], $message['channel_id']);

        // Draft should now have published_message_id set
        $updated = RichContentService::getDraft($draft['id'], $this->user['id']);
        $this->assertSame($message['id'], $updated['published_message_id']);
    }

    public function test_publish_draft_twice_fails(): void
    {
        $draft = RichContentService::createDraft($this->space['id'], $this->user['id'], [
            'body' => 'Once only',
            'channel_id' => $this->channel['id'],
        ]);
        RichContentService::publishDraft($draft['id'], $this->user['id']);

        $this->assertApiException(
            409,
            'DRAFT_ALREADY_PUBLISHED',
            fn() =>
            RichContentService::publishDraft($draft['id'], $this->user['id'])
        );
    }

    public function test_publish_draft_without_target_fails(): void
    {
        $draft = RichContentService::createDraft($this->space['id'], $this->user['id'], [
            'body' => 'No target',
        ]);

        $this->assertApiException(
            422,
            'DRAFT_NO_TARGET',
            fn() =>
            RichContentService::publishDraft($draft['id'], $this->user['id'])
        );
    }

    public function test_list_shared_drafts(): void
    {
        // User 1 creates shared and non-shared drafts
        $d1 = RichContentService::createDraft($this->space['id'], $this->user['id'], ['body' => 'private']);
        $d2 = RichContentService::createDraft($this->space['id'], $this->user['id'], ['body' => 'shared']);
        RichContentService::updateDraft($d2['id'], $this->user['id'], ['is_shared' => true]);

        // User 2 should see shared ones
        $shared = RichContentService::listDrafts($this->space['id'], $this->user2['id'], true);
        $this->assertCount(1, $shared);
        $this->assertSame('shared', $shared[0]['body']);
    }

    // ══════════════════════════════════════════════════════════
    // URL Safety
    // ══════════════════════════════════════════════════════════

    public function test_extract_urls_skips_invalid_urls(): void
    {
        $urls = RichContentRepository::extractUrls('not-a-url and ftp://wrong.scheme');
        $this->assertEmpty($urls);
    }

    public function test_url_extraction_deduplicates(): void
    {
        $urls = RichContentRepository::extractUrls('https://same.com and https://same.com again');
        $this->assertCount(1, $urls);
    }

    public function test_code_block_extraction(): void
    {
        $body = "Text\n```sql\nSELECT 1;\n```\nMore text\n```\nplain\n```";
        $blocks = RichContentRepository::extractCodeBlocks($body);
        $this->assertCount(2, $blocks);
        $this->assertSame('sql', $blocks[0]['language']);
        $this->assertSame('text', $blocks[1]['language']); // default
    }
}

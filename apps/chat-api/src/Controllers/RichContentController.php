<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Services\RichContentService;
use App\Support\Request;
use App\Support\Response;

/**
 * REST endpoints for rich content: Markdown, Snippets, Link Previews, Shared Drafts.
 */
final class RichContentController
{
    // ── Markdown / Rendering ─────────────────────────────

    /** POST /api/content/render */
    public function render(): void
    {
        Request::requireUserId();
        $input = Request::json();
        $body = $input['body'] ?? '';
        Response::json([
            'html' => RichContentService::renderMarkdown($body),
            'analysis' => RichContentService::analyzeContent($body),
        ]);
    }

    /** GET /api/content/languages */
    public function languages(): void
    {
        Request::requireUserId();
        Response::json(['languages' => RichContentService::supportedLanguages()]);
    }

    // ── Snippets ─────────────────────────────────────────

    /** GET /api/spaces/{spaceId}/snippets */
    public function listSnippets(int $spaceId): void
    {
        $userId = Request::requireUserId();
        $language = Request::query('language');
        $channelId = Request::queryInt('channel_id') ?: null;
        $search = Request::query('search');
        Response::json(RichContentService::listSnippets($spaceId, $userId, $language, $channelId, $search));
    }

    /** POST /api/spaces/{spaceId}/snippets */
    public function createSnippet(int $spaceId): void
    {
        $userId = Request::requireUserId();
        $snippet = RichContentService::createSnippet($spaceId, $userId, Request::json());
        Response::json($snippet, 201);
    }

    /** GET /api/snippets/{snippetId} */
    public function getSnippet(int $snippetId): void
    {
        $userId = Request::requireUserId();
        Response::json(RichContentService::getSnippet($snippetId, $userId));
    }

    /** PUT /api/snippets/{snippetId} */
    public function updateSnippet(int $snippetId): void
    {
        $userId = Request::requireUserId();
        Response::json(RichContentService::updateSnippet($snippetId, $userId, Request::json()));
    }

    /** DELETE /api/snippets/{snippetId} */
    public function deleteSnippet(int $snippetId): void
    {
        $userId = Request::requireUserId();
        RichContentService::deleteSnippet($snippetId, $userId);
        Response::json(['deleted' => true]);
    }

    // ── Link Previews ────────────────────────────────────

    /** GET /api/messages/{messageId}/previews */
    public function messagePreviews(int $messageId): void
    {
        Request::requireUserId();
        Response::json(RichContentService::getMessagePreviews($messageId));
    }

    // ── Drafts ───────────────────────────────────────────

    /** GET /api/spaces/{spaceId}/drafts */
    public function listDrafts(int $spaceId): void
    {
        $userId = Request::requireUserId();
        $shared = Request::query('shared') === '1';
        Response::json(RichContentService::listDrafts($spaceId, $userId, $shared));
    }

    /** POST /api/spaces/{spaceId}/drafts */
    public function createDraft(int $spaceId): void
    {
        $userId = Request::requireUserId();
        $draft = RichContentService::createDraft($spaceId, $userId, Request::json());
        Response::json($draft, 201);
    }

    /** GET /api/drafts/{draftId} */
    public function getDraft(int $draftId): void
    {
        $userId = Request::requireUserId();
        Response::json(RichContentService::getDraft($draftId, $userId));
    }

    /** PUT /api/drafts/{draftId} */
    public function updateDraft(int $draftId): void
    {
        $userId = Request::requireUserId();
        Response::json(RichContentService::updateDraft($draftId, $userId, Request::json()));
    }

    /** DELETE /api/drafts/{draftId} */
    public function deleteDraft(int $draftId): void
    {
        $userId = Request::requireUserId();
        RichContentService::deleteDraft($draftId, $userId);
        Response::json(['deleted' => true]);
    }

    /** POST /api/drafts/{draftId}/publish */
    public function publishDraft(int $draftId): void
    {
        $userId = Request::requireUserId();
        Response::json(RichContentService::publishDraft($draftId, $userId), 201);
    }

    /** POST /api/drafts/{draftId}/collaborators */
    public function addCollaborator(int $draftId): void
    {
        $userId = Request::requireUserId();
        $input = Request::json();
        $collaboratorId = (int) ($input['user_id'] ?? 0);
        $permission = $input['permission'] ?? 'view';
        RichContentService::addCollaborator($draftId, $userId, $collaboratorId, $permission);
        Response::json(['added' => true], 201);
    }

    /** DELETE /api/drafts/{draftId}/collaborators/{userId} */
    public function removeCollaborator(int $draftId, int $collaboratorUserId): void
    {
        $userId = Request::requireUserId();
        RichContentService::removeCollaborator($draftId, $userId, $collaboratorUserId);
        Response::json(['removed' => true]);
    }
}

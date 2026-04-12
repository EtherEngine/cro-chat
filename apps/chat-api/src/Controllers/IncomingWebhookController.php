<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Exceptions\ApiException;
use App\Repositories\IntegrationRepository;
use App\Repositories\MessageRepository;
use App\Repositories\EventRepository;
use App\Support\Database;
use App\Support\Request;
use App\Support\Response;

/**
 * Receives webhooks from external services (GitHub, Jira, GitLab, generic).
 * Public endpoint — no session auth, uses slug + signature verification.
 *
 * POST /api/v1/hooks/incoming/{slug}
 */
final class IncomingWebhookController
{
    /**
     * POST /api/v1/hooks/incoming/{slug}
     * Receives a payload from an external service and posts a message to the target channel.
     */
    public function receive(array $params): void
    {
        $slug = $params['slug'] ?? '';
        $incoming = IntegrationRepository::findIncomingBySlug($slug);

        if (!$incoming || !$incoming['is_active']) {
            throw ApiException::notFound('Webhook nicht gefunden');
        }

        $rawBody = file_get_contents('php://input');

        // Signature verification (if secret is set)
        if ($incoming['secret']) {
            $this->verifyIncomingSignature($incoming, $rawBody);
        }

        $payload = json_decode($rawBody ?: '{}', true) ?? [];

        // Route to provider-specific parser
        $message = match ($incoming['provider']) {
            'github' => $this->parseGitHub($payload),
            'jira' => $this->parseJira($payload),
            'gitlab' => $this->parseGitLab($payload),
            default => $this->parseGeneric($payload),
        };

        if ($message === null) {
            // Event not mapped — acknowledge but don't post
            Response::json(['ok' => true, 'action' => 'ignored']);
            return;
        }

        // Create message in target channel as system/bot message
        $this->postToChannel(
            $incoming['channel_id'],
            $incoming['space_id'],
            $incoming['name'],
            $message
        );

        Response::json(['ok' => true, 'action' => 'posted']);
    }

    // ── Signature Verification ───────────────────────────────

    private function verifyIncomingSignature(array $incoming, string $rawBody): void
    {
        $provider = $incoming['provider'];
        $secret = $incoming['secret'];

        if ($provider === 'github') {
            $sig = $_SERVER['HTTP_X_HUB_SIGNATURE_256'] ?? '';
            $expected = 'sha256=' . hash_hmac('sha256', $rawBody, $secret);
            if (!hash_equals($expected, $sig)) {
                throw ApiException::forbidden('Ungültige GitHub-Signatur');
            }
            return;
        }

        if ($provider === 'gitlab') {
            $token = $_SERVER['HTTP_X_GITLAB_TOKEN'] ?? '';
            if (!hash_equals($secret, $token)) {
                throw ApiException::forbidden('Ungültiges GitLab-Token');
            }
            return;
        }

        // Generic / Jira: X-Webhook-Signature or X-Signature-256
        $sig = $_SERVER['HTTP_X_SIGNATURE_256'] ?? $_SERVER['HTTP_X_WEBHOOK_SIGNATURE'] ?? '';
        if ($sig) {
            $timestamp = $_SERVER['HTTP_X_WEBHOOK_TIMESTAMP'] ?? (string) time();
            $expected = 'sha256=' . hash_hmac('sha256', "{$timestamp}.{$rawBody}", $secret);
            if (!hash_equals($expected, $sig)) {
                throw ApiException::forbidden('Ungültige Webhook-Signatur');
            }
        }
    }

    // ── Provider Parsers ─────────────────────────────────────

    private function parseGitHub(array $payload): ?string
    {
        $event = $_SERVER['HTTP_X_GITHUB_EVENT'] ?? 'unknown';

        return match ($event) {
            'push' => $this->formatGitHubPush($payload),
            'pull_request' => $this->formatGitHubPR($payload),
            'issues' => $this->formatGitHubIssue($payload),
            'issue_comment' => $this->formatGitHubComment($payload),
            'create' => $this->formatGitHubCreate($payload),
            'release' => $this->formatGitHubRelease($payload),
            'ping' => '🏓 GitHub Webhook verbunden: **' . ($payload['repository']['full_name'] ?? 'unknown') . '**',
            default => null,
        };
    }

    private function formatGitHubPush(array $p): string
    {
        $repo = $p['repository']['full_name'] ?? 'unknown';
        $branch = str_replace('refs/heads/', '', $p['ref'] ?? '');
        $pusher = $p['pusher']['name'] ?? 'unknown';
        $count = count($p['commits'] ?? []);
        $commitList = '';
        foreach (array_slice($p['commits'] ?? [], 0, 5) as $c) {
            $short = substr($c['id'], 0, 7);
            $msg = mb_substr($c['message'], 0, 72);
            $commitList .= "\n• `{$short}` {$msg}";
        }
        if ($count > 5) {
            $commitList .= "\n• … und " . ($count - 5) . " weitere";
        }
        return "⬆️ **{$pusher}** pushed {$count} commit(s) to `{$branch}` in **{$repo}**{$commitList}";
    }

    private function formatGitHubPR(array $p): ?string
    {
        $action = $p['action'] ?? '';
        if (!in_array($action, ['opened', 'closed', 'merged', 'reopened'], true)) {
            return null;
        }
        $pr = $p['pull_request'] ?? [];
        $title = $pr['title'] ?? 'Untitled';
        $user = $pr['user']['login'] ?? 'unknown';
        $repo = $p['repository']['full_name'] ?? 'unknown';
        $num = $pr['number'] ?? '?';
        $url = $pr['html_url'] ?? '';
        $emoji = match ($action) {
            'opened' => '🟢',
            'closed' => ($pr['merged'] ?? false) ? '🟣' : '🔴',
            'reopened' => '🔄',
            default => '📋',
        };
        $verb = ($pr['merged'] ?? false) ? 'merged' : $action;
        return "{$emoji} **{$user}** {$verb} PR #{$num} in **{$repo}**: {$title}\n{$url}";
    }

    private function formatGitHubIssue(array $p): ?string
    {
        $action = $p['action'] ?? '';
        if (!in_array($action, ['opened', 'closed', 'reopened'], true)) {
            return null;
        }
        $issue = $p['issue'] ?? [];
        $title = $issue['title'] ?? 'Untitled';
        $user = $issue['user']['login'] ?? 'unknown';
        $num = $issue['number'] ?? '?';
        $url = $issue['html_url'] ?? '';
        $emoji = match ($action) {
            'opened' => '🟢',
            'closed' => '🔴',
            'reopened' => '🔄',
            default => '📋',
        };
        return "{$emoji} Issue #{$num} {$action} von **{$user}**: {$title}\n{$url}";
    }

    private function formatGitHubComment(array $p): ?string
    {
        if (($p['action'] ?? '') !== 'created')
            return null;
        $comment = $p['comment'] ?? [];
        $issue = $p['issue'] ?? [];
        $user = $comment['user']['login'] ?? 'unknown';
        $num = $issue['number'] ?? '?';
        $body = mb_substr($comment['body'] ?? '', 0, 200);
        $url = $comment['html_url'] ?? '';
        return "💬 **{$user}** kommentierte Issue #{$num}:\n> {$body}\n{$url}";
    }

    private function formatGitHubCreate(array $p): string
    {
        $refType = $p['ref_type'] ?? 'unknown';
        $ref = $p['ref'] ?? 'unknown';
        $repo = $p['repository']['full_name'] ?? 'unknown';
        return "🌿 Neuer {$refType} `{$ref}` erstellt in **{$repo}**";
    }

    private function formatGitHubRelease(array $p): ?string
    {
        if (($p['action'] ?? '') !== 'published')
            return null;
        $release = $p['release'] ?? [];
        $tag = $release['tag_name'] ?? 'unknown';
        $name = $release['name'] ?? $tag;
        $repo = $p['repository']['full_name'] ?? 'unknown';
        $url = $release['html_url'] ?? '';
        return "🚀 Release **{$name}** ({$tag}) veröffentlicht in **{$repo}**\n{$url}";
    }

    // ── Jira ─────────────────────────────────────────────────

    private function parseJira(array $payload): ?string
    {
        $event = $payload['webhookEvent'] ?? $payload['issue_event_type_name'] ?? '';
        $issue = $payload['issue'] ?? [];
        $key = $issue['key'] ?? 'UNKNOWN';
        $summary = $issue['fields']['summary'] ?? 'Untitled';
        $user = $payload['user']['displayName'] ?? 'unknown';
        $url = ($issue['self'] ?? '') ? rtrim(parse_url($issue['self'], PHP_URL_SCHEME) . '://' . parse_url($issue['self'], PHP_URL_HOST), '/') . "/browse/{$key}" : '';

        return match (true) {
            str_contains($event, 'issue_created') =>
            "🟢 **{$user}** erstellt: [{$key}] {$summary}\n{$url}",
            str_contains($event, 'issue_updated') =>
            "📝 **{$user}** aktualisiert: [{$key}] {$summary}\n{$url}",
            str_contains($event, 'issue_generic'), str_contains($event, 'comment') =>
            "💬 **{$user}** kommentierte [{$key}] {$summary}",
            str_contains($event, 'issue_assigned') =>
            "👤 [{$key}] {$summary} zugewiesen an **{$user}**",
            default => null,
        };
    }

    // ── GitLab ───────────────────────────────────────────────

    private function parseGitLab(array $payload): ?string
    {
        $kind = $payload['object_kind'] ?? '';

        return match ($kind) {
            'push' => $this->formatGitLabPush($payload),
            'merge_request' => $this->formatGitLabMR($payload),
            'issue' => $this->formatGitLabIssue($payload),
            'pipeline' => $this->formatGitLabPipeline($payload),
            default => null,
        };
    }

    private function formatGitLabPush(array $p): string
    {
        $user = $p['user_name'] ?? 'unknown';
        $project = $p['project']['path_with_namespace'] ?? 'unknown';
        $branch = str_replace('refs/heads/', '', $p['ref'] ?? '');
        $count = (int) ($p['total_commits_count'] ?? 0);
        return "⬆️ **{$user}** pushed {$count} commit(s) to `{$branch}` in **{$project}**";
    }

    private function formatGitLabMR(array $p): ?string
    {
        $action = $p['object_attributes']['action'] ?? '';
        if (!in_array($action, ['open', 'close', 'merge', 'reopen'], true))
            return null;
        $mr = $p['object_attributes'] ?? [];
        $title = $mr['title'] ?? 'Untitled';
        $user = $p['user']['name'] ?? 'unknown';
        $iid = $mr['iid'] ?? '?';
        $url = $mr['url'] ?? '';
        return "🔀 MR !{$iid} {$action} von **{$user}**: {$title}\n{$url}";
    }

    private function formatGitLabIssue(array $p): ?string
    {
        $action = $p['object_attributes']['action'] ?? '';
        if (!in_array($action, ['open', 'close', 'reopen'], true))
            return null;
        $issue = $p['object_attributes'] ?? [];
        $title = $issue['title'] ?? 'Untitled';
        $user = $p['user']['name'] ?? 'unknown';
        $iid = $issue['iid'] ?? '?';
        $url = $issue['url'] ?? '';
        return "📋 Issue #{$iid} {$action} von **{$user}**: {$title}\n{$url}";
    }

    private function formatGitLabPipeline(array $p): ?string
    {
        $attrs = $p['object_attributes'] ?? [];
        $status = $attrs['status'] ?? '';
        if (!in_array($status, ['success', 'failed'], true))
            return null;
        $project = $p['project']['path_with_namespace'] ?? 'unknown';
        $ref = $attrs['ref'] ?? 'unknown';
        $emoji = $status === 'success' ? '✅' : '❌';
        return "{$emoji} Pipeline **{$status}** für `{$ref}` in **{$project}**";
    }

    // ── Generic ──────────────────────────────────────────────

    private function parseGeneric(array $payload): ?string
    {
        // Accept { "text": "..." } or { "body": "..." } or { "message": "..." }
        $text = $payload['text'] ?? $payload['body'] ?? $payload['message'] ?? null;
        if (!$text || !is_string($text)) {
            return null;
        }
        return mb_substr($text, 0, 4000);
    }

    // ── Message Posting ──────────────────────────────────────

    private function postToChannel(int $channelId, int $spaceId, string $botName, string $body): void
    {
        $db = Database::connection();

        // We need a user_id for the message. Use a system approach:
        // Find or create a "bot" user entry. For simplicity, use the incoming webhook creator's space.
        // Instead, post as system message with a bot label in the body prefix.
        $formattedBody = "**[{$botName}]** {$body}";

        // Use the space owner as the sender (service account acting through owner)
        $stmt = $db->prepare("SELECT user_id FROM space_members WHERE space_id = ? AND role = 'owner' LIMIT 1");
        $stmt->execute([$spaceId]);
        $ownerId = (int) $stmt->fetchColumn();
        if (!$ownerId) {
            return;
        }

        // Insert message directly (bypass service layer to avoid auth checks)
        $stmt = $db->prepare(
            'INSERT INTO messages (body, user_id, channel_id, created_at) VALUES (?, ?, ?, NOW())'
        );
        $stmt->execute([$formattedBody, $ownerId, $channelId]);
        $messageId = (int) $db->lastInsertId();

        // Publish realtime event
        $message = MessageRepository::find($messageId);
        if ($message) {
            EventRepository::publish('message.created', "channel:{$channelId}", $message);
        }
    }
}

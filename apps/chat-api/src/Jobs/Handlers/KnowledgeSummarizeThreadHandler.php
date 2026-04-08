<?php

declare(strict_types=1);

namespace App\Jobs\Handlers;

use App\Jobs\JobHandler;
use App\Repositories\KnowledgeRepository;
use App\Repositories\MessageRepository;
use App\Repositories\ThreadRepository;
use App\Support\Database;
use App\Support\Logger;

/**
 * Generates a summary for a thread by analysing its messages.
 *
 * Payload:
 *   thread_id  – Thread to summarise
 *   space_id   – Space context
 *   user_id    – Requester (for audit)
 *
 * Idempotent: Checks last processed message cursor. Skips if nothing new.
 */
final class KnowledgeSummarizeThreadHandler implements JobHandler
{
    private const MAX_MESSAGES = 200;

    public function handle(array $payload): void
    {
        $threadId = (int) ($payload['thread_id'] ?? 0);
        $spaceId = (int) ($payload['space_id'] ?? 0);

        if ($threadId <= 0 || $spaceId <= 0) {
            Logger::warning('knowledge.summarize_thread.invalid_payload', $payload);
            return;
        }

        $thread = ThreadRepository::find($threadId);
        if (!$thread) {
            Logger::warning('knowledge.summarize_thread.thread_not_found', ['thread_id' => $threadId]);
            return;
        }

        // Track cursor via knowledge_jobs
        $kjob = KnowledgeRepository::getOrCreateJob($spaceId, 'thread', $threadId);

        // Load thread messages
        $messages = $this->loadThreadMessages($threadId, $kjob['last_message_id']);
        if (empty($messages)) {
            Logger::info('knowledge.summarize_thread.no_new_messages', ['thread_id' => $threadId]);
            return;
        }

        KnowledgeRepository::updateJob($kjob['id'], ['status' => 'running']);

        try {
            $summary = $this->generateSummary($thread, $messages, $spaceId);

            // Store in DB
            $stored = KnowledgeRepository::createSummary($summary);

            // Link source messages
            $sources = [];
            foreach ($messages as $msg) {
                $sources[] = [
                    'summary_id' => $stored['id'],
                    'message_id' => (int) $msg['id'],
                    'relevance' => 1.00,
                ];
            }
            KnowledgeRepository::addSourcesBatch($sources);

            // Update cursor
            $lastMsgId = (int) end($messages)['id'];
            KnowledgeRepository::updateJob($kjob['id'], [
                'last_message_id' => $lastMsgId,
                'last_run_at' => date('Y-m-d H:i:s'),
                'status' => 'idle',
                'error_message' => null,
            ]);

            Logger::info('knowledge.summarize_thread.done', [
                'thread_id' => $threadId,
                'summary_id' => $stored['id'],
                'messages' => count($messages),
            ]);
        } catch (\Throwable $e) {
            KnowledgeRepository::updateJob($kjob['id'], [
                'status' => 'error',
                'error_message' => $e->getMessage(),
            ]);
            throw $e;
        }
    }

    private function loadThreadMessages(int $threadId, ?int $afterId): array
    {
        $db = Database::connection();

        $sql = 'SELECT m.id, m.body, m.user_id, m.created_at, u.display_name
                FROM messages m
                JOIN users u ON u.id = m.user_id
                WHERE m.thread_id = ? AND m.deleted_at IS NULL';
        $params = [$threadId];

        if ($afterId) {
            $sql .= ' AND m.id > ?';
            $params[] = $afterId;
        }
        $sql .= ' ORDER BY m.id ASC LIMIT ?';
        $params[] = self::MAX_MESSAGES;

        $stmt = $db->prepare($sql);
        $stmt->execute($params);
        return $stmt->fetchAll();
    }

    /**
     * Extract summary from thread messages.
     * Uses heuristic extraction (no LLM dependency).
     */
    private function generateSummary(array $thread, array $messages, int $spaceId): array
    {
        $participantIds = array_values(array_unique(array_map(fn($m) => (int) $m['user_id'], $messages)));
        $participantNames = array_values(array_unique(array_map(fn($m) => $m['display_name'], $messages)));

        // Extract key points: longest messages and messages with question/decision markers
        $keyPoints = $this->extractKeyPoints($messages);

        // Build title from root message
        $rootBody = $messages[0]['body'] ?? 'Thread';
        $title = mb_substr(strip_tags($rootBody), 0, 120);
        if (mb_strlen($rootBody) > 120) {
            $title .= '…';
        }

        // Build summary text
        $summaryLines = [];
        $summaryLines[] = "Thread mit {$thread['reply_count']} Antworten von " . implode(', ', array_slice($participantNames, 0, 5));
        if (count($participantNames) > 5) {
            $summaryLines[] = '(und ' . (count($participantNames) - 5) . ' weitere)';
        }
        $summaryLines[] = '';
        foreach ($keyPoints as $point) {
            $summaryLines[] = '• ' . $point;
        }

        $firstMsg = $messages[0];
        $lastMsg = end($messages);

        return [
            'space_id' => $spaceId,
            'scope_type' => 'thread',
            'scope_id' => (int) $thread['id'],
            'title' => $title,
            'summary' => implode("\n", $summaryLines),
            'key_points' => $keyPoints,
            'participants' => $participantIds,
            'message_count' => count($messages),
            'first_message_id' => (int) $firstMsg['id'],
            'last_message_id' => (int) $lastMsg['id'],
            'period_start' => $firstMsg['created_at'],
            'period_end' => $lastMsg['created_at'],
        ];
    }

    private function extractKeyPoints(array $messages): array
    {
        $points = [];

        // Score messages by length, keywords, and position
        $scored = [];
        foreach ($messages as $i => $msg) {
            $body = strip_tags($msg['body']);
            $score = 0;

            // Longer messages tend to be more substantial
            $len = mb_strlen($body);
            $score += min($len / 50, 5);

            // Decision/conclusion keywords
            if (preg_match('/\b(entschieden|beschlossen|agreed|decided|fazit|conclusion|lösung|solution|ergebnis|result)\b/iu', $body)) {
                $score += 10;
            }
            // Question markers
            if (preg_match('/\?/', $body)) {
                $score += 2;
            }
            // Action items
            if (preg_match('/\b(TODO|FIXME|action|aufgabe|muss|must|should|soll)\b/iu', $body)) {
                $score += 5;
            }
            // Code blocks
            if (str_contains($body, '```')) {
                $score += 3;
            }
            // First and last messages are important
            if ($i === 0 || $i === count($messages) - 1) {
                $score += 3;
            }

            $scored[] = ['body' => $body, 'name' => $msg['display_name'], 'score' => $score];
        }

        // Sort by score descending, take top 5
        usort($scored, fn($a, $b) => $b['score'] <=> $a['score']);

        foreach (array_slice($scored, 0, 5) as $item) {
            $excerpt = mb_substr($item['body'], 0, 150);
            if (mb_strlen($item['body']) > 150) {
                $excerpt .= '…';
            }
            $points[] = $item['name'] . ': ' . $excerpt;
        }

        return $points;
    }
}

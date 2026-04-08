<?php

declare(strict_types=1);

namespace App\Jobs\Handlers;

use App\Jobs\JobHandler;
use App\Repositories\KnowledgeRepository;
use App\Repositories\ChannelRepository;
use App\Support\Database;
use App\Support\Logger;

/**
 * Generates a daily/weekly summary for a channel.
 *
 * Payload:
 *   channel_id    – Channel to summarise
 *   space_id      – Space context
 *   period_start  – (optional) Start of time range
 *   period_end    – (optional) End of time range
 *
 * Idempotent: Uses knowledge_jobs cursor + idempotency key per day.
 */
final class KnowledgeSummarizeChannelHandler implements JobHandler
{
    private const MAX_MESSAGES = 500;

    public function handle(array $payload): void
    {
        $channelId = (int) ($payload['channel_id'] ?? 0);
        $spaceId   = (int) ($payload['space_id'] ?? 0);

        if ($channelId <= 0 || $spaceId <= 0) {
            Logger::warning('knowledge.summarize_channel.invalid_payload', $payload);
            return;
        }

        $channel = ChannelRepository::find($channelId);
        if (!$channel) {
            Logger::warning('knowledge.summarize_channel.channel_not_found', ['channel_id' => $channelId]);
            return;
        }

        // Determine period
        $periodStart = $payload['period_start'] ?? date('Y-m-d 00:00:00', strtotime('-1 day'));
        $periodEnd   = $payload['period_end']   ?? date('Y-m-d 23:59:59', strtotime('-1 day'));
        $scopeType   = 'daily';

        // Check if more than 7 days → weekly scope
        $diff = (strtotime($periodEnd) - strtotime($periodStart)) / 86400;
        if ($diff > 3) {
            $scopeType = 'weekly';
        }

        $kjob = KnowledgeRepository::getOrCreateJob($spaceId, $scopeType === 'daily' ? 'channel' : 'channel', $channelId);
        KnowledgeRepository::updateJob($kjob['id'], ['status' => 'running']);

        try {
            $messages = $this->loadChannelMessages($channelId, $periodStart, $periodEnd);

            if (empty($messages)) {
                KnowledgeRepository::updateJob($kjob['id'], [
                    'status'     => 'idle',
                    'last_run_at' => date('Y-m-d H:i:s'),
                ]);
                Logger::info('knowledge.summarize_channel.no_messages', [
                    'channel_id' => $channelId,
                    'period'     => "$periodStart – $periodEnd",
                ]);
                return;
            }

            $summary = $this->generateChannelSummary($channel, $messages, $spaceId, $scopeType, $periodStart, $periodEnd);
            $stored = KnowledgeRepository::createSummary($summary);

            // Link source messages (sample for large channels)
            $sampleMessages = $this->sampleKeyMessages($messages, 50);
            $sources = [];
            foreach ($sampleMessages as $msg) {
                $sources[] = [
                    'summary_id' => $stored['id'],
                    'message_id' => (int) $msg['id'],
                    'relevance'  => 1.00,
                ];
            }
            KnowledgeRepository::addSourcesBatch($sources);

            $lastMsgId = (int) end($messages)['id'];
            KnowledgeRepository::updateJob($kjob['id'], [
                'last_message_id' => $lastMsgId,
                'last_run_at'     => date('Y-m-d H:i:s'),
                'status'          => 'idle',
                'error_message'   => null,
            ]);

            Logger::info('knowledge.summarize_channel.done', [
                'channel_id' => $channelId,
                'summary_id' => $stored['id'],
                'messages'   => count($messages),
                'scope'      => $scopeType,
            ]);
        } catch (\Throwable $e) {
            KnowledgeRepository::updateJob($kjob['id'], [
                'status'        => 'error',
                'error_message' => $e->getMessage(),
            ]);
            throw $e;
        }
    }

    private function loadChannelMessages(int $channelId, string $start, string $end): array
    {
        $db = Database::connection();
        $stmt = $db->prepare(
            'SELECT m.id, m.body, m.user_id, m.thread_id, m.created_at, u.display_name
             FROM messages m
             JOIN users u ON u.id = m.user_id
             WHERE m.channel_id = ? AND m.deleted_at IS NULL
               AND m.created_at >= ? AND m.created_at <= ?
             ORDER BY m.id ASC
             LIMIT ?'
        );
        $stmt->execute([$channelId, $start, $end, self::MAX_MESSAGES]);
        return $stmt->fetchAll();
    }

    private function generateChannelSummary(
        array $channel,
        array $messages,
        int $spaceId,
        string $scopeType,
        string $periodStart,
        string $periodEnd
    ): array {
        $participantIds = array_values(array_unique(array_map(fn($m) => (int) $m['user_id'], $messages)));
        $participantNames = array_values(array_unique(array_map(fn($m) => $m['display_name'], $messages)));
        $threadCount = count(array_unique(array_filter(array_map(fn($m) => $m['thread_id'], $messages))));

        // Group messages by "topic clusters" (time gaps > 30min = new cluster)
        $clusters = $this->clusterMessages($messages);
        $keyPoints = [];

        foreach ($clusters as $cluster) {
            $point = $this->summarizeCluster($cluster);
            if ($point) {
                $keyPoints[] = $point;
            }
        }

        $label = $scopeType === 'daily' ? 'Tagesübersicht' : 'Wochenübersicht';
        $dateLabel = date('d.m.Y', strtotime($periodStart));
        if ($scopeType === 'weekly') {
            $dateLabel .= ' – ' . date('d.m.Y', strtotime($periodEnd));
        }
        $title = "{$label} #{$channel['name']} – {$dateLabel}";

        $summaryLines = [];
        $summaryLines[] = count($messages) . " Nachrichten von " . count($participantNames) . " Teilnehmern";
        if ($threadCount > 0) {
            $summaryLines[] = "{$threadCount} aktive Threads";
        }
        $summaryLines[] = '';
        foreach ($keyPoints as $point) {
            $summaryLines[] = '• ' . $point;
        }

        $firstMsg = $messages[0];
        $lastMsg  = end($messages);

        return [
            'space_id'         => $spaceId,
            'scope_type'       => $scopeType,
            'scope_id'         => (int) $channel['id'],
            'title'            => $title,
            'summary'          => implode("\n", $summaryLines),
            'key_points'       => $keyPoints,
            'participants'     => $participantIds,
            'message_count'    => count($messages),
            'first_message_id' => (int) $firstMsg['id'],
            'last_message_id'  => (int) $lastMsg['id'],
            'period_start'     => $periodStart,
            'period_end'       => $periodEnd,
        ];
    }

    /**
     * Cluster messages by time gaps (>30 min = new cluster).
     */
    private function clusterMessages(array $messages): array
    {
        $clusters = [];
        $current = [];

        foreach ($messages as $i => $msg) {
            if ($i === 0) {
                $current[] = $msg;
                continue;
            }
            $prev = strtotime($messages[$i - 1]['created_at']);
            $curr = strtotime($msg['created_at']);
            if (($curr - $prev) > 1800) {
                $clusters[] = $current;
                $current = [];
            }
            $current[] = $msg;
        }
        if (!empty($current)) {
            $clusters[] = $current;
        }
        return $clusters;
    }

    /**
     * One-liner summary for a cluster of messages.
     */
    private function summarizeCluster(array $cluster): ?string
    {
        if (empty($cluster)) return null;

        $names = array_unique(array_map(fn($m) => $m['display_name'], $cluster));
        $count = count($cluster);

        // Find the most "important" message in the cluster
        $best = $cluster[0];
        $bestScore = 0;
        foreach ($cluster as $msg) {
            $len = mb_strlen($msg['body']);
            $score = min($len / 50, 5);
            if (preg_match('/\?/', $msg['body'])) $score += 2;
            if (preg_match('/\b(entschieden|beschlossen|fazit|solution|ergebnis)\b/iu', $msg['body'])) $score += 8;
            if ($score > $bestScore) {
                $bestScore = $score;
                $best = $msg;
            }
        }

        $excerpt = mb_substr(strip_tags($best['body']), 0, 120);
        if (mb_strlen($best['body']) > 120) $excerpt .= '…';

        $nameList = implode(', ', array_slice($names, 0, 3));
        return "{$nameList} ({$count} Nachrichten): {$excerpt}";
    }

    /**
     * Sample the most important messages from a large set.
     */
    private function sampleKeyMessages(array $messages, int $maxSamples): array
    {
        if (count($messages) <= $maxSamples) return $messages;

        // Take first, last, and evenly spaced messages
        $result = [$messages[0], end($messages)];
        $step = max(1, (int) floor(count($messages) / $maxSamples));
        for ($i = $step; $i < count($messages) - 1; $i += $step) {
            $result[] = $messages[$i];
            if (count($result) >= $maxSamples) break;
        }
        return $result;
    }
}

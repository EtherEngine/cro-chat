<?php

declare(strict_types=1);

namespace App\Jobs\Handlers;

use App\Jobs\JobHandler;
use App\Repositories\KnowledgeRepository;
use App\Support\Database;
use App\Support\Logger;

/**
 * Extracts structured knowledge (facts, decisions, action items) from recent messages.
 *
 * Payload:
 *   space_id    – Space to scan
 *   channel_id  – (optional) Limit to a single channel
 *
 * Heuristic extraction: Identifies decisions, links, code references, action items
 * by pattern matching. No LLM dependency.
 */
final class KnowledgeExtractHandler implements JobHandler
{
    private const BATCH_SIZE = 200;

    /** Patterns that indicate a decision was made. */
    private const DECISION_PATTERNS = [
        '/\b(entschieden|beschlossen|agreed|decided|we\'ll go with|wir nehmen|wir machen)\b/iu',
        '/\b(fazit|conclusion|ergebnis|result)\s*:/iu',
    ];

    /** Patterns that indicate an action item. */
    private const ACTION_PATTERNS = [
        '/\b(TODO|FIXME|HACK)\b/u',
        '/\b(aufgabe|task|action item)\s*:/iu',
        '/\b(muss noch|must|needs to|soll|should)\b.*\b(machen|tun|do|implement|fix|update)\b/iu',
    ];

    /** Patterns for how-to / instructional content. */
    private const HOWTO_PATTERNS = [
        '/\b(so geht|how to|anleitung|tutorial|step \d|schritt \d)\b/iu',
        '/```[\s\S]{30,}```/u',  // code blocks > 30 chars
    ];

    /** URL pattern for detecting link references. */
    private const URL_PATTERN = '/(https?:\/\/[^\s<>"\']+)/i';

    public function handle(array $payload): void
    {
        $spaceId = (int) ($payload['space_id'] ?? 0);
        $channelId = isset($payload['channel_id']) ? (int) $payload['channel_id'] : null;

        if ($spaceId <= 0) {
            Logger::warning('knowledge.extract.invalid_payload', $payload);
            return;
        }

        $scopeId = $channelId ?? 0;
        $kjob = KnowledgeRepository::getOrCreateJob($spaceId, 'channel', $scopeId > 0 ? $scopeId : null);
        KnowledgeRepository::updateJob($kjob['id'], ['status' => 'running']);

        try {
            $messages = $this->loadMessages($spaceId, $channelId, $kjob['last_message_id']);

            if (empty($messages)) {
                KnowledgeRepository::updateJob($kjob['id'], [
                    'status' => 'idle',
                    'last_run_at' => date('Y-m-d H:i:s'),
                ]);
                return;
            }

            $extracted = 0;

            foreach ($messages as $msg) {
                $items = $this->analyseMessage($msg, $spaceId);
                foreach ($items as $item) {
                    $entry = KnowledgeRepository::createEntry($item);
                    KnowledgeRepository::addSource([
                        'entry_id' => $entry['id'],
                        'message_id' => (int) $msg['id'],
                    ]);
                    $extracted++;
                }
            }

            $lastMsgId = (int) end($messages)['id'];
            KnowledgeRepository::updateJob($kjob['id'], [
                'last_message_id' => $lastMsgId,
                'last_run_at' => date('Y-m-d H:i:s'),
                'status' => 'idle',
                'error_message' => null,
            ]);

            Logger::info('knowledge.extract.done', [
                'space_id' => $spaceId,
                'channel_id' => $channelId,
                'scanned' => count($messages),
                'extracted' => $extracted,
            ]);
        } catch (\Throwable $e) {
            KnowledgeRepository::updateJob($kjob['id'], [
                'status' => 'error',
                'error_message' => $e->getMessage(),
            ]);
            throw $e;
        }
    }

    private function loadMessages(int $spaceId, ?int $channelId, ?int $afterId): array
    {
        $db = Database::connection();

        $sql = 'SELECT m.id, m.body, m.user_id, m.channel_id, m.conversation_id,
                       m.thread_id, m.created_at, u.display_name
                FROM messages m
                JOIN users u ON u.id = m.user_id
                JOIN channels c ON c.id = m.channel_id
                WHERE c.space_id = ? AND m.deleted_at IS NULL';
        $params = [$spaceId];

        if ($channelId) {
            $sql .= ' AND m.channel_id = ?';
            $params[] = $channelId;
        }
        if ($afterId) {
            $sql .= ' AND m.id > ?';
            $params[] = $afterId;
        }

        $sql .= ' ORDER BY m.id ASC LIMIT ?';
        $params[] = self::BATCH_SIZE;

        $stmt = $db->prepare($sql);
        $stmt->execute($params);
        return $stmt->fetchAll();
    }

    /**
     * Analyse a single message and return knowledge entries to create.
     * @return array[] List of knowledge entry data arrays
     */
    private function analyseMessage(array $msg, int $spaceId): array
    {
        $body = $msg['body'];
        $items = [];

        // Skip very short messages
        if (mb_strlen($body) < 20) {
            return [];
        }

        // Check for decisions
        foreach (self::DECISION_PATTERNS as $pattern) {
            if (preg_match($pattern, $body)) {
                $items[] = $this->buildEntry($msg, $spaceId, 'fact', 'Entscheidung', 0.75);
                break;
            }
        }

        // Check for action items
        foreach (self::ACTION_PATTERNS as $pattern) {
            if (preg_match($pattern, $body)) {
                $items[] = $this->buildEntry($msg, $spaceId, 'action_item', 'Action Item', 0.70);
                break;
            }
        }

        // Check for how-to content
        foreach (self::HOWTO_PATTERNS as $pattern) {
            if (preg_match($pattern, $body)) {
                $items[] = $this->buildEntry($msg, $spaceId, 'howto', 'Anleitung', 0.65);
                break;
            }
        }

        // Check for links / references
        if (preg_match(self::URL_PATTERN, $body, $urlMatch)) {
            $items[] = [
                'space_id' => $spaceId,
                'entry_type' => 'link',
                'title' => 'Link: ' . mb_substr($urlMatch[1], 0, 200),
                'content' => $body,
                'tags' => ['link'],
                'confidence' => 0.80,
                'extracted_by' => 'auto',
                'created_by' => (int) $msg['user_id'],
                'source_message_id' => (int) $msg['id'],
            ];
        }

        return $items;
    }

    private function buildEntry(array $msg, int $spaceId, string $type, string $prefix, float $confidence): array
    {
        $title = $prefix . ': ' . mb_substr(strip_tags($msg['body']), 0, 150);
        if (mb_strlen($msg['body']) > 150) {
            $title .= '…';
        }

        return [
            'space_id' => $spaceId,
            'entry_type' => $type,
            'title' => $title,
            'content' => $msg['body'],
            'tags' => [$type],
            'confidence' => $confidence,
            'extracted_by' => 'auto',
            'created_by' => (int) $msg['user_id'],
            'source_message_id' => (int) $msg['id'],
        ];
    }
}

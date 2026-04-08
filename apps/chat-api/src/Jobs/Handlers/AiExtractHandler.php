<?php

declare(strict_types=1);

namespace App\Jobs\Handlers;

use App\Jobs\JobHandler;
use App\Repositories\AiRepository;
use App\Services\AiService;
use App\Support\Database;
use App\Support\Logger;

/**
 * Async AI action-item extraction from messages.
 *
 * Payload:
 *   space_id    – Space context
 *   channel_id  – Optional: limit to channel
 *   user_id     – Requester
 */
final class AiExtractHandler implements JobHandler
{
    private const BATCH_SIZE = 200;

    public function handle(array $payload): void
    {
        $spaceId = (int) ($payload['space_id'] ?? 0);
        $channelId = isset($payload['channel_id']) ? (int) $payload['channel_id'] : null;
        $userId = (int) ($payload['user_id'] ?? 0);

        if ($spaceId <= 0) {
            Logger::warning('ai.extract.invalid_payload', $payload);
            return;
        }

        $scopeType = $channelId ? 'channel' : 'space';
        $scopeId = $channelId;
        $ajob = AiRepository::getOrCreateJob($spaceId, 'extract', $scopeType, $scopeId);

        AiRepository::updateJob($ajob['id'], ['status' => 'running']);

        try {
            $messages = $this->loadMessages($spaceId, $channelId, $ajob['last_message_id']);
            if (empty($messages)) {
                Logger::info('ai.extract.no_new_messages', ['space_id' => $spaceId]);
                AiRepository::updateJob($ajob['id'], ['status' => 'idle']);
                return;
            }

            $provider = AiService::getProvider($spaceId);
            $result = $provider->extractActions($messages);

            $created = 0;
            foreach ($result['items'] ?? [] as $item) {
                $sourceIdx = $item['source_index'] ?? null;
                $sourceMsgId = ($sourceIdx !== null && isset($messages[$sourceIdx])) ? (int) $messages[$sourceIdx]['id'] : null;

                AiRepository::createActionItem([
                    'space_id' => $spaceId,
                    'source_message_id' => $sourceMsgId,
                    'title' => $item['title'],
                    'description' => $item['description'] ?? null,
                    'assignee_hint' => $item['assignee_hint'] ?? null,
                    'due_hint' => $item['due_hint'] ?? null,
                    'confidence' => $item['confidence'] ?? 0.70,
                ]);
                $created++;
            }

            $lastMsgId = (int) end($messages)['id'];
            AiRepository::updateJob($ajob['id'], [
                'last_message_id' => $lastMsgId,
                'last_run_at' => date('Y-m-d H:i:s'),
                'status' => 'idle',
                'error_message' => null,
            ]);

            Logger::info('ai.extract.done', [
                'space_id' => $spaceId,
                'messages_processed' => count($messages),
                'items_created' => $created,
            ]);
        } catch (\Throwable $e) {
            AiRepository::updateJob($ajob['id'], [
                'status' => 'error',
                'error_message' => $e->getMessage(),
            ]);
            throw $e;
        }
    }

    private function loadMessages(int $spaceId, ?int $channelId, ?int $afterId): array
    {
        $db = Database::connection();

        $sql = 'SELECT m.id, m.body, m.user_id, m.created_at, u.display_name
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
}

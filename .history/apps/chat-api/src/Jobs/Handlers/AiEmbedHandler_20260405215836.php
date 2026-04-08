<?php

declare(strict_types=1);

namespace App\Jobs\Handlers;

use App\Jobs\JobHandler;
use App\Repositories\AiRepository;
use App\Services\AiService;
use App\Support\Database;
use App\Support\Logger;

/**
 * Async embedding generation for semantic search.
 *
 * Payload:
 *   space_id    – Space context
 *   channel_id  – Optional: limit to channel
 *   user_id     – Requester
 */
final class AiEmbedHandler implements JobHandler
{
    private const BATCH_SIZE = 100;

    public function handle(array $payload): void
    {
        $spaceId = (int) ($payload['space_id'] ?? 0);
        $channelId = isset($payload['channel_id']) ? (int) $payload['channel_id'] : null;

        if ($spaceId <= 0) {
            Logger::warning('ai.embed.invalid_payload', $payload);
            return;
        }

        $scopeType = $channelId ? 'channel' : 'space';
        $scopeId = $channelId;
        $ajob = AiRepository::getOrCreateJob($spaceId, 'embed', $scopeType, $scopeId);

        AiRepository::updateJob($ajob['id'], ['status' => 'running']);

        try {
            $messages = $this->loadMessages($spaceId, $channelId, $ajob['last_message_id']);
            if (empty($messages)) {
                Logger::info('ai.embed.no_new_messages', ['space_id' => $spaceId]);
                AiRepository::updateJob($ajob['id'], ['status' => 'idle']);
                return;
            }

            $embedded = 0;
            foreach ($messages as $msg) {
                $body = strip_tags($msg['body'] ?? '');
                if (mb_strlen($body) < 10)
                    continue;

                AiService::embedMessage($spaceId, (int) $msg['id'], $body);
                $embedded++;
            }

            $lastMsgId = (int) end($messages)['id'];
            AiRepository::updateJob($ajob['id'], [
                'last_message_id' => $lastMsgId,
                'last_run_at' => date('Y-m-d H:i:s'),
                'status' => 'idle',
                'error_message' => null,
            ]);

            Logger::info('ai.embed.done', [
                'space_id' => $spaceId,
                'messages_processed' => count($messages),
                'embedded' => $embedded,
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

        $sql = 'SELECT m.id, m.body, m.user_id, m.created_at
                FROM messages m
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

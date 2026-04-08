<?php

declare(strict_types=1);

namespace App\Repositories;

use App\Support\Database;

/**
 * CRUD for ai_summaries, ai_summary_sources, ai_action_items,
 * ai_embeddings, ai_suggestions, ai_jobs, ai_provider_config.
 */
final class AiRepository
{
    // ── Provider Config ──────────────────────────────────────

    public static function getConfig(int $spaceId): ?array
    {
        $stmt = Database::connection()->prepare(
            'SELECT * FROM ai_provider_config WHERE space_id = ?'
        );
        $stmt->execute([$spaceId]);
        $row = $stmt->fetch();
        return $row ? self::hydrateConfig($row) : null;
    }

    public static function upsertConfig(int $spaceId, array $data): array
    {
        $db = Database::connection();
        $existing = self::getConfig($spaceId);

        if ($existing) {
            $fields = [];
            $params = [];
            foreach (['provider', 'api_key_enc', 'model_summary', 'model_embedding', 'model_suggest', 'max_tokens', 'temperature', 'is_enabled'] as $f) {
                if (array_key_exists($f, $data)) {
                    $fields[] = "$f = ?";
                    $params[] = $data[$f];
                }
            }
            if (!empty($fields)) {
                $params[] = $spaceId;
                $db->prepare(
                    'UPDATE ai_provider_config SET ' . implode(', ', $fields) . ' WHERE space_id = ?'
                )->execute($params);
            }
        } else {
            $db->prepare(
                'INSERT INTO ai_provider_config (space_id, provider, api_key_enc, model_summary, model_embedding, model_suggest, max_tokens, temperature, is_enabled)
                 VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)'
            )->execute([
                $spaceId,
                $data['provider'] ?? 'openai',
                $data['api_key_enc'] ?? null,
                $data['model_summary'] ?? 'gpt-4o-mini',
                $data['model_embedding'] ?? 'text-embedding-3-small',
                $data['model_suggest'] ?? 'gpt-4o-mini',
                $data['max_tokens'] ?? 2000,
                $data['temperature'] ?? 0.30,
                $data['is_enabled'] ?? 0,
            ]);
        }

        return self::getConfig($spaceId);
    }

    private static function hydrateConfig(array $row): array
    {
        $row['id'] = (int) $row['id'];
        $row['space_id'] = (int) $row['space_id'];
        $row['max_tokens'] = (int) $row['max_tokens'];
        $row['temperature'] = (float) $row['temperature'];
        $row['is_enabled'] = (bool) $row['is_enabled'];
        return $row;
    }

    // ── AI Summaries ─────────────────────────────────────────

    public static function createSummary(array $data): array
    {
        $db = Database::connection();
        $db->prepare(
            'INSERT INTO ai_summaries
             (space_id, scope_type, scope_id, title, summary, key_points, action_items, participants,
              message_count, first_message_id, last_message_id, period_start, period_end, model, tokens_used, processing_ms)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)'
        )->execute([
            $data['space_id'],
            $data['scope_type'],
            $data['scope_id'],
            $data['title'] ?? '',
            $data['summary'],
            json_encode($data['key_points'] ?? [], JSON_UNESCAPED_UNICODE),
            json_encode($data['action_items'] ?? [], JSON_UNESCAPED_UNICODE),
            json_encode($data['participants'] ?? [], JSON_UNESCAPED_UNICODE),
            $data['message_count'] ?? 0,
            $data['first_message_id'] ?? null,
            $data['last_message_id'] ?? null,
            $data['period_start'] ?? null,
            $data['period_end'] ?? null,
            $data['model'] ?? '',
            $data['tokens_used'] ?? 0,
            $data['processing_ms'] ?? 0,
        ]);
        return self::findSummary((int) $db->lastInsertId());
    }

    public static function findSummary(int $id): ?array
    {
        $stmt = Database::connection()->prepare('SELECT * FROM ai_summaries WHERE id = ?');
        $stmt->execute([$id]);
        $row = $stmt->fetch();
        return $row ? self::hydrateSummary($row) : null;
    }

    public static function listSummaries(int $spaceId, ?string $scopeType = null, ?int $scopeId = null, int $limit = 50): array
    {
        $sql = 'SELECT * FROM ai_summaries WHERE space_id = ?';
        $params = [$spaceId];
        if ($scopeType !== null) {
            $sql .= ' AND scope_type = ?';
            $params[] = $scopeType;
        }
        if ($scopeId !== null) {
            $sql .= ' AND scope_id = ?';
            $params[] = $scopeId;
        }
        $sql .= ' ORDER BY created_at DESC LIMIT ?';
        $params[] = $limit;
        $stmt = Database::connection()->prepare($sql);
        $stmt->execute($params);
        return array_map([self::class, 'hydrateSummary'], $stmt->fetchAll());
    }

    public static function latestSummary(int $spaceId, string $scopeType, int $scopeId): ?array
    {
        $stmt = Database::connection()->prepare(
            'SELECT * FROM ai_summaries WHERE space_id = ? AND scope_type = ? AND scope_id = ? ORDER BY created_at DESC LIMIT 1'
        );
        $stmt->execute([$spaceId, $scopeType, $scopeId]);
        $row = $stmt->fetch();
        return $row ? self::hydrateSummary($row) : null;
    }

    public static function deleteSummary(int $id): void
    {
        $db = Database::connection();
        $db->prepare('DELETE FROM ai_summary_sources WHERE summary_id = ?')->execute([$id]);
        $db->prepare('DELETE FROM ai_action_items WHERE summary_id = ?')->execute([$id]);
        $db->prepare('DELETE FROM ai_summaries WHERE id = ?')->execute([$id]);
    }

    private static function hydrateSummary(array $row): array
    {
        $row['id'] = (int) $row['id'];
        $row['space_id'] = (int) $row['space_id'];
        $row['scope_id'] = (int) $row['scope_id'];
        $row['message_count'] = (int) $row['message_count'];
        $row['first_message_id'] = $row['first_message_id'] !== null ? (int) $row['first_message_id'] : null;
        $row['last_message_id'] = $row['last_message_id'] !== null ? (int) $row['last_message_id'] : null;
        $row['tokens_used'] = (int) $row['tokens_used'];
        $row['processing_ms'] = (int) $row['processing_ms'];
        $row['key_points'] = json_decode($row['key_points'] ?: '[]', true);
        $row['action_items'] = json_decode($row['action_items'] ?: '[]', true);
        $row['participants'] = json_decode($row['participants'] ?: '[]', true);
        return $row;
    }

    // ── Summary Sources ──────────────────────────────────────

    public static function addSummarySources(int $summaryId, array $messageIds): void
    {
        if (empty($messageIds)) return;
        $db = Database::connection();
        $stmt = $db->prepare(
            'INSERT INTO ai_summary_sources (summary_id, message_id, relevance) VALUES (?, ?, ?)'
        );
        foreach ($messageIds as $msgId) {
            $stmt->execute([$summaryId, $msgId, 1.00]);
        }
    }

    public static function summarySourceMessages(int $summaryId): array
    {
        $stmt = Database::connection()->prepare(
            'SELECT ss.*, m.body, m.user_id, m.created_at AS message_created_at
             FROM ai_summary_sources ss
             JOIN messages m ON m.id = ss.message_id
             WHERE ss.summary_id = ?
             ORDER BY m.id ASC'
        );
        $stmt->execute([$summaryId]);
        return $stmt->fetchAll();
    }

    // ── Action Items ─────────────────────────────────────────

    public static function createActionItem(array $data): array
    {
        $db = Database::connection();
        $db->prepare(
            'INSERT INTO ai_action_items (space_id, summary_id, source_message_id, title, description, assignee_hint, due_hint, confidence)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?)'
        )->execute([
            $data['space_id'],
            $data['summary_id'] ?? null,
            $data['source_message_id'] ?? null,
            $data['title'],
            $data['description'] ?? null,
            $data['assignee_hint'] ?? null,
            $data['due_hint'] ?? null,
            $data['confidence'] ?? 0.80,
        ]);
        return self::findActionItem((int) $db->lastInsertId());
    }

    public static function findActionItem(int $id): ?array
    {
        $stmt = Database::connection()->prepare('SELECT * FROM ai_action_items WHERE id = ?');
        $stmt->execute([$id]);
        $row = $stmt->fetch();
        return $row ? self::hydrateActionItem($row) : null;
    }

    public static function listActionItems(int $spaceId, ?string $status = null, ?int $summaryId = null, int $limit = 50): array
    {
        $sql = 'SELECT * FROM ai_action_items WHERE space_id = ?';
        $params = [$spaceId];
        if ($status !== null) {
            $sql .= ' AND status = ?';
            $params[] = $status;
        }
        if ($summaryId !== null) {
            $sql .= ' AND summary_id = ?';
            $params[] = $summaryId;
        }
        $sql .= ' ORDER BY created_at DESC LIMIT ?';
        $params[] = $limit;
        $stmt = Database::connection()->prepare($sql);
        $stmt->execute($params);
        return array_map([self::class, 'hydrateActionItem'], $stmt->fetchAll());
    }

    public static function updateActionItemStatus(int $id, string $status): void
    {
        Database::connection()->prepare(
            'UPDATE ai_action_items SET status = ? WHERE id = ?'
        )->execute([$status, $id]);
    }

    private static function hydrateActionItem(array $row): array
    {
        $row['id'] = (int) $row['id'];
        $row['space_id'] = (int) $row['space_id'];
        $row['summary_id'] = $row['summary_id'] !== null ? (int) $row['summary_id'] : null;
        $row['source_message_id'] = $row['source_message_id'] !== null ? (int) $row['source_message_id'] : null;
        $row['confidence'] = (float) $row['confidence'];
        return $row;
    }

    // ── Embeddings ───────────────────────────────────────────

    public static function upsertEmbedding(int $spaceId, int $messageId, string $embeddingBlob, string $model, int $dimensions): void
    {
        $db = Database::connection();
        $db->prepare(
            'INSERT INTO ai_embeddings (space_id, message_id, embedding, model, dimensions)
             VALUES (?, ?, ?, ?, ?)
             ON DUPLICATE KEY UPDATE embedding = VALUES(embedding), model = VALUES(model), dimensions = VALUES(dimensions)'
        )->execute([$spaceId, $messageId, $embeddingBlob, $model, $dimensions]);
    }

    public static function getEmbedding(int $messageId): ?array
    {
        $stmt = Database::connection()->prepare(
            'SELECT * FROM ai_embeddings WHERE message_id = ?'
        );
        $stmt->execute([$messageId]);
        $row = $stmt->fetch();
        return $row ?: null;
    }

    public static function getEmbeddingsForSpace(int $spaceId, int $limit = 1000): array
    {
        $stmt = Database::connection()->prepare(
            'SELECT e.*, m.body, m.user_id, m.channel_id, m.created_at AS message_created_at
             FROM ai_embeddings e
             JOIN messages m ON m.id = e.message_id
             WHERE e.space_id = ?
             ORDER BY e.id DESC LIMIT ?'
        );
        $stmt->execute([$spaceId, $limit]);
        return $stmt->fetchAll();
    }

    public static function countEmbeddings(int $spaceId): int
    {
        $stmt = Database::connection()->prepare(
            'SELECT COUNT(*) FROM ai_embeddings WHERE space_id = ?'
        );
        $stmt->execute([$spaceId]);
        return (int) $stmt->fetchColumn();
    }

    // ── Reply Suggestions ────────────────────────────────────

    public static function createSuggestion(array $data): array
    {
        $db = Database::connection();
        $db->prepare(
            'INSERT INTO ai_suggestions (space_id, user_id, scope_type, scope_id, context_message_id, suggestions, model, tokens_used)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?)'
        )->execute([
            $data['space_id'],
            $data['user_id'],
            $data['scope_type'],
            $data['scope_id'],
            $data['context_message_id'] ?? null,
            json_encode($data['suggestions'] ?? [], JSON_UNESCAPED_UNICODE),
            $data['model'] ?? '',
            $data['tokens_used'] ?? 0,
        ]);
        return self::findSuggestion((int) $db->lastInsertId());
    }

    public static function findSuggestion(int $id): ?array
    {
        $stmt = Database::connection()->prepare('SELECT * FROM ai_suggestions WHERE id = ?');
        $stmt->execute([$id]);
        $row = $stmt->fetch();
        return $row ? self::hydrateSuggestion($row) : null;
    }

    public static function acceptSuggestion(int $id, int $index): void
    {
        Database::connection()->prepare(
            'UPDATE ai_suggestions SET accepted_index = ? WHERE id = ?'
        )->execute([$index, $id]);
    }

    private static function hydrateSuggestion(array $row): array
    {
        $row['id'] = (int) $row['id'];
        $row['space_id'] = (int) $row['space_id'];
        $row['user_id'] = (int) $row['user_id'];
        $row['scope_id'] = (int) $row['scope_id'];
        $row['context_message_id'] = $row['context_message_id'] !== null ? (int) $row['context_message_id'] : null;
        $row['suggestions'] = json_decode($row['suggestions'] ?: '[]', true);
        $row['tokens_used'] = (int) $row['tokens_used'];
        $row['accepted_index'] = $row['accepted_index'] !== null ? (int) $row['accepted_index'] : null;
        return $row;
    }

    // ── AI Job Tracking ──────────────────────────────────────

    public static function getOrCreateJob(int $spaceId, string $jobType, string $scopeType, ?int $scopeId): array
    {
        $db = Database::connection();
        $stmt = $db->prepare(
            'SELECT * FROM ai_jobs WHERE space_id = ? AND job_type = ? AND scope_type = ? AND scope_id <=> ?'
        );
        $stmt->execute([$spaceId, $jobType, $scopeType, $scopeId]);
        $row = $stmt->fetch();

        if ($row) {
            return self::hydrateJob($row);
        }

        $db->prepare(
            'INSERT INTO ai_jobs (space_id, job_type, scope_type, scope_id) VALUES (?, ?, ?, ?)'
        )->execute([$spaceId, $jobType, $scopeType, $scopeId]);

        return [
            'id' => (int) $db->lastInsertId(),
            'space_id' => $spaceId,
            'job_type' => $jobType,
            'scope_type' => $scopeType,
            'scope_id' => $scopeId,
            'last_message_id' => null,
            'last_run_at' => null,
            'status' => 'idle',
            'error_message' => null,
        ];
    }

    public static function updateJob(int $id, array $data): void
    {
        $fields = [];
        $params = [];
        foreach (['last_message_id', 'last_run_at', 'status', 'error_message'] as $f) {
            if (array_key_exists($f, $data)) {
                $fields[] = "$f = ?";
                $params[] = $data[$f];
            }
        }
        if (empty($fields)) return;
        $params[] = $id;
        Database::connection()->prepare(
            'UPDATE ai_jobs SET ' . implode(', ', $fields) . ' WHERE id = ?'
        )->execute($params);
    }

    private static function hydrateJob(array $row): array
    {
        $row['id'] = (int) $row['id'];
        $row['space_id'] = (int) $row['space_id'];
        $row['scope_id'] = $row['scope_id'] !== null ? (int) $row['scope_id'] : null;
        $row['last_message_id'] = $row['last_message_id'] !== null ? (int) $row['last_message_id'] : null;
        return $row;
    }
}

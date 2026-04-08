<?php

declare(strict_types=1);

namespace App\Repositories;

use App\Support\Database;

/**
 * CRUD for knowledge_topics, knowledge_decisions, knowledge_summaries,
 * knowledge_entries, knowledge_sources, knowledge_jobs.
 */
final class KnowledgeRepository
{
    // ── Topics ───────────────────────────────────────────────

    public static function createTopic(array $data): array
    {
        $db = Database::connection();
        $stmt = $db->prepare(
            'INSERT INTO knowledge_topics (space_id, channel_id, name, slug, description)
             VALUES (?, ?, ?, ?, ?)'
        );
        $stmt->execute([
            $data['space_id'],
            $data['channel_id'] ?? null,
            $data['name'],
            $data['slug'],
            $data['description'] ?? null,
        ]);
        return self::findTopic((int) $db->lastInsertId());
    }

    public static function findTopic(int $id): ?array
    {
        $stmt = Database::connection()->prepare('SELECT * FROM knowledge_topics WHERE id = ?');
        $stmt->execute([$id]);
        $row = $stmt->fetch();
        return $row ? self::hydrateTopic($row) : null;
    }

    public static function findTopicBySlug(int $spaceId, string $slug): ?array
    {
        $stmt = Database::connection()->prepare(
            'SELECT * FROM knowledge_topics WHERE space_id = ? AND slug = ?'
        );
        $stmt->execute([$spaceId, $slug]);
        $row = $stmt->fetch();
        return $row ? self::hydrateTopic($row) : null;
    }

    public static function listTopics(int $spaceId, ?int $channelId = null, int $limit = 50): array
    {
        $sql = 'SELECT * FROM knowledge_topics WHERE space_id = ?';
        $params = [$spaceId];
        if ($channelId !== null) {
            $sql .= ' AND channel_id = ?';
            $params[] = $channelId;
        }
        $sql .= ' ORDER BY last_activity DESC NULLS LAST, name ASC LIMIT ?';
        $params[] = $limit;
        $stmt = Database::connection()->prepare($sql);
        $stmt->execute($params);
        return array_map([self::class, 'hydrateTopic'], $stmt->fetchAll());
    }

    public static function updateTopic(int $id, array $data): void
    {
        $fields = [];
        $params = [];
        foreach (['name', 'slug', 'description', 'message_count', 'last_activity'] as $f) {
            if (array_key_exists($f, $data)) {
                $fields[] = "$f = ?";
                $params[] = $data[$f];
            }
        }
        if (empty($fields)) return;
        $params[] = $id;
        Database::connection()->prepare(
            'UPDATE knowledge_topics SET ' . implode(', ', $fields) . ' WHERE id = ?'
        )->execute($params);
    }

    public static function incrementTopicMessages(int $id): void
    {
        Database::connection()->prepare(
            'UPDATE knowledge_topics SET message_count = message_count + 1, last_activity = NOW() WHERE id = ?'
        )->execute([$id]);
    }

    public static function deleteTopic(int $id): void
    {
        Database::connection()->prepare('DELETE FROM knowledge_topics WHERE id = ?')->execute([$id]);
    }

    private static function hydrateTopic(array $row): array
    {
        $row['id'] = (int) $row['id'];
        $row['space_id'] = (int) $row['space_id'];
        $row['channel_id'] = $row['channel_id'] !== null ? (int) $row['channel_id'] : null;
        $row['message_count'] = (int) $row['message_count'];
        return $row;
    }

    // ── Decisions ────────────────────────────────────────────

    public static function createDecision(array $data): array
    {
        $db = Database::connection();
        $stmt = $db->prepare(
            'INSERT INTO knowledge_decisions (space_id, channel_id, topic_id, title, description, status, decided_at, decided_by, source_message_id)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)'
        );
        $stmt->execute([
            $data['space_id'],
            $data['channel_id'] ?? null,
            $data['topic_id'] ?? null,
            $data['title'],
            $data['description'] ?? null,
            $data['status'] ?? 'accepted',
            $data['decided_at'] ?? date('Y-m-d H:i:s'),
            $data['decided_by'] ?? null,
            $data['source_message_id'] ?? null,
        ]);
        return self::findDecision((int) $db->lastInsertId());
    }

    public static function findDecision(int $id): ?array
    {
        $stmt = Database::connection()->prepare('SELECT * FROM knowledge_decisions WHERE id = ?');
        $stmt->execute([$id]);
        $row = $stmt->fetch();
        return $row ? self::hydrateDecision($row) : null;
    }

    public static function listDecisions(int $spaceId, ?int $topicId = null, ?string $status = null, int $limit = 50): array
    {
        $sql = 'SELECT * FROM knowledge_decisions WHERE space_id = ?';
        $params = [$spaceId];
        if ($topicId !== null) {
            $sql .= ' AND topic_id = ?';
            $params[] = $topicId;
        }
        if ($status !== null) {
            $sql .= ' AND status = ?';
            $params[] = $status;
        }
        $sql .= ' ORDER BY decided_at DESC, id DESC LIMIT ?';
        $params[] = $limit;
        $stmt = Database::connection()->prepare($sql);
        $stmt->execute($params);
        return array_map([self::class, 'hydrateDecision'], $stmt->fetchAll());
    }

    public static function updateDecision(int $id, array $data): void
    {
        $fields = [];
        $params = [];
        foreach (['title', 'description', 'status', 'topic_id'] as $f) {
            if (array_key_exists($f, $data)) {
                $fields[] = "$f = ?";
                $params[] = $data[$f];
            }
        }
        if (empty($fields)) return;
        $params[] = $id;
        Database::connection()->prepare(
            'UPDATE knowledge_decisions SET ' . implode(', ', $fields) . ' WHERE id = ?'
        )->execute($params);
    }

    public static function deleteDecision(int $id): void
    {
        Database::connection()->prepare('DELETE FROM knowledge_decisions WHERE id = ?')->execute([$id]);
    }

    private static function hydrateDecision(array $row): array
    {
        $row['id'] = (int) $row['id'];
        $row['space_id'] = (int) $row['space_id'];
        $row['channel_id'] = $row['channel_id'] !== null ? (int) $row['channel_id'] : null;
        $row['topic_id'] = $row['topic_id'] !== null ? (int) $row['topic_id'] : null;
        $row['decided_by'] = $row['decided_by'] !== null ? (int) $row['decided_by'] : null;
        $row['source_message_id'] = $row['source_message_id'] !== null ? (int) $row['source_message_id'] : null;
        return $row;
    }

    // ── Summaries ────────────────────────────────────────────

    public static function createSummary(array $data): array
    {
        $db = Database::connection();
        $stmt = $db->prepare(
            'INSERT INTO knowledge_summaries
             (space_id, scope_type, scope_id, title, summary, key_points, participants,
              message_count, first_message_id, last_message_id, period_start, period_end)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)'
        );
        $stmt->execute([
            $data['space_id'],
            $data['scope_type'],
            $data['scope_id'] ?? null,
            $data['title'],
            $data['summary'],
            json_encode($data['key_points'] ?? [], JSON_UNESCAPED_UNICODE),
            json_encode($data['participants'] ?? [], JSON_UNESCAPED_UNICODE),
            $data['message_count'] ?? 0,
            $data['first_message_id'] ?? null,
            $data['last_message_id'] ?? null,
            $data['period_start'] ?? null,
            $data['period_end'] ?? null,
        ]);
        return self::findSummary((int) $db->lastInsertId());
    }

    public static function findSummary(int $id): ?array
    {
        $stmt = Database::connection()->prepare('SELECT * FROM knowledge_summaries WHERE id = ?');
        $stmt->execute([$id]);
        $row = $stmt->fetch();
        return $row ? self::hydrateSummary($row) : null;
    }

    /** Find latest summary for a scope (e.g. thread:123, channel:5). */
    public static function latestForScope(int $spaceId, string $scopeType, ?int $scopeId): ?array
    {
        $sql = 'SELECT * FROM knowledge_summaries WHERE space_id = ? AND scope_type = ?';
        $params = [$spaceId, $scopeType];
        if ($scopeId !== null) {
            $sql .= ' AND scope_id = ?';
            $params[] = $scopeId;
        }
        $sql .= ' ORDER BY generated_at DESC LIMIT 1';
        $stmt = Database::connection()->prepare($sql);
        $stmt->execute($params);
        $row = $stmt->fetch();
        return $row ? self::hydrateSummary($row) : null;
    }

    public static function listSummaries(int $spaceId, ?string $scopeType = null, ?int $scopeId = null, int $limit = 50): array
    {
        $sql = 'SELECT * FROM knowledge_summaries WHERE space_id = ?';
        $params = [$spaceId];
        if ($scopeType !== null) {
            $sql .= ' AND scope_type = ?';
            $params[] = $scopeType;
        }
        if ($scopeId !== null) {
            $sql .= ' AND scope_id = ?';
            $params[] = $scopeId;
        }
        $sql .= ' ORDER BY generated_at DESC LIMIT ?';
        $params[] = $limit;
        $stmt = Database::connection()->prepare($sql);
        $stmt->execute($params);
        return array_map([self::class, 'hydrateSummary'], $stmt->fetchAll());
    }

    public static function deleteSummary(int $id): void
    {
        Database::connection()->prepare('DELETE FROM knowledge_summaries WHERE id = ?')->execute([$id]);
    }

    private static function hydrateSummary(array $row): array
    {
        $row['id'] = (int) $row['id'];
        $row['space_id'] = (int) $row['space_id'];
        $row['scope_id'] = $row['scope_id'] !== null ? (int) $row['scope_id'] : null;
        $row['message_count'] = (int) $row['message_count'];
        $row['first_message_id'] = $row['first_message_id'] !== null ? (int) $row['first_message_id'] : null;
        $row['last_message_id'] = $row['last_message_id'] !== null ? (int) $row['last_message_id'] : null;
        $row['key_points'] = json_decode($row['key_points'] ?: '[]', true);
        $row['participants'] = json_decode($row['participants'] ?: '[]', true);
        return $row;
    }

    // ── Knowledge Entries ────────────────────────────────────

    public static function createEntry(array $data): array
    {
        $db = Database::connection();
        $stmt = $db->prepare(
            'INSERT INTO knowledge_entries
             (space_id, topic_id, entry_type, title, content, tags, confidence, extracted_by, created_by, source_message_id)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)'
        );
        $stmt->execute([
            $data['space_id'],
            $data['topic_id'] ?? null,
            $data['entry_type'] ?? 'fact',
            $data['title'],
            $data['content'],
            json_encode($data['tags'] ?? [], JSON_UNESCAPED_UNICODE),
            $data['confidence'] ?? 1.00,
            $data['extracted_by'] ?? 'auto',
            $data['created_by'] ?? null,
            $data['source_message_id'] ?? null,
        ]);
        return self::findEntry((int) $db->lastInsertId());
    }

    public static function findEntry(int $id): ?array
    {
        $stmt = Database::connection()->prepare('SELECT * FROM knowledge_entries WHERE id = ?');
        $stmt->execute([$id]);
        $row = $stmt->fetch();
        return $row ? self::hydrateEntry($row) : null;
    }

    public static function listEntries(int $spaceId, ?int $topicId = null, ?string $type = null, int $limit = 50): array
    {
        $sql = 'SELECT * FROM knowledge_entries WHERE space_id = ?';
        $params = [$spaceId];
        if ($topicId !== null) {
            $sql .= ' AND topic_id = ?';
            $params[] = $topicId;
        }
        if ($type !== null) {
            $sql .= ' AND entry_type = ?';
            $params[] = $type;
        }
        $sql .= ' ORDER BY created_at DESC LIMIT ?';
        $params[] = $limit;
        $stmt = Database::connection()->prepare($sql);
        $stmt->execute($params);
        return array_map([self::class, 'hydrateEntry'], $stmt->fetchAll());
    }

    /** Fulltext search across knowledge entries. */
    public static function searchEntries(int $spaceId, string $query, int $limit = 30): array
    {
        $stmt = Database::connection()->prepare(
            "SELECT *, MATCH(title, content) AGAINST(? IN BOOLEAN MODE) AS score
             FROM knowledge_entries
             WHERE space_id = ? AND MATCH(title, content) AGAINST(? IN BOOLEAN MODE)
             ORDER BY score DESC
             LIMIT ?"
        );
        $stmt->execute([$query, $spaceId, $query, $limit]);
        return array_map([self::class, 'hydrateEntry'], $stmt->fetchAll());
    }

    public static function updateEntry(int $id, array $data): void
    {
        $fields = [];
        $params = [];
        foreach (['title', 'content', 'entry_type', 'topic_id', 'confidence'] as $f) {
            if (array_key_exists($f, $data)) {
                $fields[] = "$f = ?";
                $params[] = $data[$f];
            }
        }
        if (array_key_exists('tags', $data)) {
            $fields[] = 'tags = ?';
            $params[] = json_encode($data['tags'], JSON_UNESCAPED_UNICODE);
        }
        if (empty($fields)) return;
        $params[] = $id;
        Database::connection()->prepare(
            'UPDATE knowledge_entries SET ' . implode(', ', $fields) . ' WHERE id = ?'
        )->execute($params);
    }

    public static function deleteEntry(int $id): void
    {
        Database::connection()->prepare('DELETE FROM knowledge_entries WHERE id = ?')->execute([$id]);
    }

    private static function hydrateEntry(array $row): array
    {
        $row['id'] = (int) $row['id'];
        $row['space_id'] = (int) $row['space_id'];
        $row['topic_id'] = $row['topic_id'] !== null ? (int) $row['topic_id'] : null;
        $row['confidence'] = (float) $row['confidence'];
        $row['created_by'] = $row['created_by'] !== null ? (int) $row['created_by'] : null;
        $row['source_message_id'] = $row['source_message_id'] !== null ? (int) $row['source_message_id'] : null;
        $row['tags'] = json_decode($row['tags'] ?: '[]', true);
        return $row;
    }

    // ── Knowledge Sources (message links) ────────────────────

    public static function addSource(array $data): void
    {
        Database::connection()->prepare(
            'INSERT INTO knowledge_sources (entry_id, summary_id, decision_id, message_id, relevance)
             VALUES (?, ?, ?, ?, ?)'
        )->execute([
            $data['entry_id'] ?? null,
            $data['summary_id'] ?? null,
            $data['decision_id'] ?? null,
            $data['message_id'],
            $data['relevance'] ?? 1.00,
        ]);
    }

    public static function addSourcesBatch(array $sources): void
    {
        if (empty($sources)) return;
        $db = Database::connection();
        $stmt = $db->prepare(
            'INSERT INTO knowledge_sources (entry_id, summary_id, decision_id, message_id, relevance)
             VALUES (?, ?, ?, ?, ?)'
        );
        foreach ($sources as $s) {
            $stmt->execute([
                $s['entry_id'] ?? null,
                $s['summary_id'] ?? null,
                $s['decision_id'] ?? null,
                $s['message_id'],
                $s['relevance'] ?? 1.00,
            ]);
        }
    }

    /** Get all source messages for a knowledge item. */
    public static function sourcesForEntry(int $entryId): array
    {
        $stmt = Database::connection()->prepare(
            'SELECT ks.*, m.body, m.user_id, m.channel_id, m.conversation_id, m.created_at AS message_created_at
             FROM knowledge_sources ks
             JOIN messages m ON m.id = ks.message_id
             WHERE ks.entry_id = ?
             ORDER BY ks.relevance DESC'
        );
        $stmt->execute([$entryId]);
        return $stmt->fetchAll();
    }

    public static function sourcesForSummary(int $summaryId): array
    {
        $stmt = Database::connection()->prepare(
            'SELECT ks.*, m.body, m.user_id, m.created_at AS message_created_at
             FROM knowledge_sources ks
             JOIN messages m ON m.id = ks.message_id
             WHERE ks.summary_id = ?
             ORDER BY m.id ASC'
        );
        $stmt->execute([$summaryId]);
        return $stmt->fetchAll();
    }

    /** Find all knowledge linked to a specific message. */
    public static function knowledgeForMessage(int $messageId): array
    {
        $db = Database::connection();

        $entries = $db->prepare(
            'SELECT ke.* FROM knowledge_entries ke
             JOIN knowledge_sources ks ON ks.entry_id = ke.id
             WHERE ks.message_id = ?'
        );
        $entries->execute([$messageId]);

        $summaries = $db->prepare(
            'SELECT ks2.* FROM knowledge_summaries ks2
             JOIN knowledge_sources ks ON ks.summary_id = ks2.id
             WHERE ks.message_id = ?'
        );
        $summaries->execute([$messageId]);

        $decisions = $db->prepare(
            'SELECT kd.* FROM knowledge_decisions kd
             JOIN knowledge_sources ks ON ks.decision_id = kd.id
             WHERE ks.message_id = ?'
        );
        $decisions->execute([$messageId]);

        return [
            'entries'   => array_map([self::class, 'hydrateEntry'], $entries->fetchAll()),
            'summaries' => array_map([self::class, 'hydrateSummary'], $summaries->fetchAll()),
            'decisions' => array_map([self::class, 'hydrateDecision'], $decisions->fetchAll()),
        ];
    }

    // ── Knowledge Jobs Tracking ──────────────────────────────

    public static function getOrCreateJob(int $spaceId, string $scopeType, ?int $scopeId): array
    {
        $db = Database::connection();
        $stmt = $db->prepare(
            'SELECT * FROM knowledge_jobs WHERE space_id = ? AND scope_type = ? AND scope_id <=> ?'
        );
        $stmt->execute([$spaceId, $scopeType, $scopeId]);
        $row = $stmt->fetch();

        if ($row) {
            return self::hydrateJob($row);
        }

        $db->prepare(
            'INSERT INTO knowledge_jobs (space_id, scope_type, scope_id) VALUES (?, ?, ?)'
        )->execute([$spaceId, $scopeType, $scopeId]);

        return self::hydrateJob([
            'id' => (int) $db->lastInsertId(),
            'space_id' => $spaceId,
            'scope_type' => $scopeType,
            'scope_id' => $scopeId,
            'last_message_id' => null,
            'last_run_at' => null,
            'next_run_at' => null,
            'status' => 'idle',
            'error_message' => null,
        ]);
    }

    public static function updateJob(int $id, array $data): void
    {
        $fields = [];
        $params = [];
        foreach (['last_message_id', 'last_run_at', 'next_run_at', 'status', 'error_message'] as $f) {
            if (array_key_exists($f, $data)) {
                $fields[] = "$f = ?";
                $params[] = $data[$f];
            }
        }
        if (empty($fields)) return;
        $params[] = $id;
        Database::connection()->prepare(
            'UPDATE knowledge_jobs SET ' . implode(', ', $fields) . ' WHERE id = ?'
        )->execute($params);
    }

    public static function pendingJobs(int $limit = 10): array
    {
        $stmt = Database::connection()->prepare(
            "SELECT * FROM knowledge_jobs
             WHERE status = 'idle' AND (next_run_at IS NULL OR next_run_at <= NOW())
             ORDER BY next_run_at ASC
             LIMIT ?"
        );
        $stmt->execute([$limit]);
        return array_map([self::class, 'hydrateJob'], $stmt->fetchAll());
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

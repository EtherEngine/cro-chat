<?php

declare(strict_types=1);

namespace App\Repositories;

use App\Support\Database;

/**
 * Data-access layer for tasks, assignees, comments, reminders, and activity log.
 */
final class TaskRepository
{
    // ── Tasks ────────────────────────────────────────────

    public static function create(array $data): array
    {
        $db = Database::connection();
        $db->prepare(
            'INSERT INTO tasks (space_id, channel_id, title, description, status, priority, created_by, source_message_id, thread_id, due_date)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)'
        )->execute([
            $data['space_id'],
            $data['channel_id'] ?? null,
            $data['title'],
            $data['description'] ?? null,
            $data['status'] ?? 'open',
            $data['priority'] ?? 'normal',
            $data['created_by'],
            $data['source_message_id'] ?? null,
            $data['thread_id'] ?? null,
            $data['due_date'] ?? null,
        ]);

        return self::find((int) $db->lastInsertId());
    }

    public static function find(int $id): ?array
    {
        $db = Database::connection();
        $stmt = $db->prepare('SELECT * FROM tasks WHERE id = ?');
        $stmt->execute([$id]);
        $row = $stmt->fetch();
        return $row ? self::hydrate($row) : null;
    }

    public static function listForSpace(int $spaceId, ?string $status = null, ?int $assigneeId = null, ?int $channelId = null, int $limit = 50, int $offset = 0): array
    {
        $db = Database::connection();
        $where = ['t.space_id = ?'];
        $params = [$spaceId];

        if ($status !== null) {
            $where[] = 't.status = ?';
            $params[] = $status;
        }
        if ($assigneeId !== null) {
            $where[] = 'EXISTS (SELECT 1 FROM task_assignees ta WHERE ta.task_id = t.id AND ta.user_id = ?)';
            $params[] = $assigneeId;
        }
        if ($channelId !== null) {
            $where[] = 't.channel_id = ?';
            $params[] = $channelId;
        }

        $params[] = $limit;
        $params[] = $offset;

        $sql = 'SELECT t.* FROM tasks t WHERE ' . implode(' AND ', $where)
             . ' ORDER BY FIELD(t.priority, "urgent", "high", "normal", "low"), t.created_at DESC LIMIT ? OFFSET ?';

        $stmt = $db->prepare($sql);
        $stmt->execute($params);
        return array_map(fn($r) => self::hydrate($r), $stmt->fetchAll());
    }

    public static function myTasks(int $userId, ?string $status = null, int $limit = 50, int $offset = 0): array
    {
        $db = Database::connection();
        $where = ['EXISTS (SELECT 1 FROM task_assignees ta WHERE ta.task_id = t.id AND ta.user_id = ?)'];
        $params = [$userId];

        if ($status !== null) {
            $where[] = 't.status = ?';
            $params[] = $status;
        }

        $params[] = $limit;
        $params[] = $offset;

        $sql = 'SELECT t.* FROM tasks t WHERE ' . implode(' AND ', $where)
             . ' ORDER BY CASE WHEN t.due_date IS NULL THEN 1 ELSE 0 END, t.due_date ASC, t.created_at DESC LIMIT ? OFFSET ?';

        $stmt = $db->prepare($sql);
        $stmt->execute($params);
        return array_map(fn($r) => self::hydrate($r), $stmt->fetchAll());
    }

    public static function update(int $id, array $data): array
    {
        $db = Database::connection();
        $sets = [];
        $params = [];

        foreach (['title', 'description', 'status', 'priority', 'due_date', 'completed_at', 'channel_id'] as $col) {
            if (array_key_exists($col, $data)) {
                $sets[] = "`$col` = ?";
                $params[] = $data[$col];
            }
        }

        if (empty($sets)) {
            return self::find($id);
        }

        $params[] = $id;
        $db->prepare('UPDATE tasks SET ' . implode(', ', $sets) . ' WHERE id = ?')->execute($params);
        return self::find($id);
    }

    public static function delete(int $id): void
    {
        Database::connection()->prepare('DELETE FROM tasks WHERE id = ?')->execute([$id]);
    }

    public static function countForSpace(int $spaceId): array
    {
        $db = Database::connection();
        $stmt = $db->prepare(
            'SELECT status, COUNT(*) as cnt FROM tasks WHERE space_id = ? GROUP BY status'
        );
        $stmt->execute([$spaceId]);
        $counts = ['open' => 0, 'in_progress' => 0, 'done' => 0, 'cancelled' => 0, 'total' => 0];
        foreach ($stmt->fetchAll() as $row) {
            $counts[$row['status']] = (int) $row['cnt'];
            $counts['total'] += (int) $row['cnt'];
        }
        return $counts;
    }

    // ── Assignees ────────────────────────────────────────

    public static function assign(int $taskId, int $userId, int $assignedBy): void
    {
        $db = Database::connection();
        $db->prepare(
            'INSERT IGNORE INTO task_assignees (task_id, user_id, assigned_by) VALUES (?, ?, ?)'
        )->execute([$taskId, $userId, $assignedBy]);
    }

    public static function unassign(int $taskId, int $userId): void
    {
        Database::connection()->prepare(
            'DELETE FROM task_assignees WHERE task_id = ? AND user_id = ?'
        )->execute([$taskId, $userId]);
    }

    public static function assignees(int $taskId): array
    {
        $db = Database::connection();
        $stmt = $db->prepare(
            'SELECT ta.user_id, ta.assigned_at, ta.assigned_by, u.display_name, u.email
             FROM task_assignees ta
             JOIN users u ON u.id = ta.user_id
             WHERE ta.task_id = ?
             ORDER BY ta.assigned_at'
        );
        $stmt->execute([$taskId]);
        return array_map(fn($r) => [
            'user_id' => (int) $r['user_id'],
            'display_name' => $r['display_name'],
            'email' => $r['email'],
            'assigned_at' => $r['assigned_at'],
            'assigned_by' => (int) $r['assigned_by'],
        ], $stmt->fetchAll());
    }

    public static function isAssigned(int $taskId, int $userId): bool
    {
        $db = Database::connection();
        $stmt = $db->prepare('SELECT 1 FROM task_assignees WHERE task_id = ? AND user_id = ?');
        $stmt->execute([$taskId, $userId]);
        return (bool) $stmt->fetch();
    }

    // ── Comments ─────────────────────────────────────────

    public static function addComment(int $taskId, int $userId, string $body): array
    {
        $db = Database::connection();
        $db->prepare(
            'INSERT INTO task_comments (task_id, user_id, body) VALUES (?, ?, ?)'
        )->execute([$taskId, $userId, $body]);

        $id = (int) $db->lastInsertId();
        $stmt = $db->prepare(
            'SELECT tc.*, u.display_name FROM task_comments tc JOIN users u ON u.id = tc.user_id WHERE tc.id = ?'
        );
        $stmt->execute([$id]);
        $row = $stmt->fetch();
        return [
            'id' => (int) $row['id'],
            'task_id' => (int) $row['task_id'],
            'user_id' => (int) $row['user_id'],
            'display_name' => $row['display_name'],
            'body' => $row['body'],
            'created_at' => $row['created_at'],
        ];
    }

    public static function listComments(int $taskId): array
    {
        $db = Database::connection();
        $stmt = $db->prepare(
            'SELECT tc.*, u.display_name FROM task_comments tc
             JOIN users u ON u.id = tc.user_id
             WHERE tc.task_id = ? ORDER BY tc.created_at'
        );
        $stmt->execute([$taskId]);
        return array_map(fn($r) => [
            'id' => (int) $r['id'],
            'task_id' => (int) $r['task_id'],
            'user_id' => (int) $r['user_id'],
            'display_name' => $r['display_name'],
            'body' => $r['body'],
            'created_at' => $r['created_at'],
        ], $stmt->fetchAll());
    }

    // ── Reminders ────────────────────────────────────────

    public static function addReminder(int $taskId, int $userId, string $remindAt): array
    {
        $db = Database::connection();
        $db->prepare(
            'INSERT INTO task_reminders (task_id, user_id, remind_at) VALUES (?, ?, ?)'
        )->execute([$taskId, $userId, $remindAt]);

        $id = (int) $db->lastInsertId();
        return self::findReminder($id);
    }

    public static function findReminder(int $id): ?array
    {
        $db = Database::connection();
        $stmt = $db->prepare('SELECT * FROM task_reminders WHERE id = ?');
        $stmt->execute([$id]);
        $row = $stmt->fetch();
        if (!$row) {
            return null;
        }
        return [
            'id' => (int) $row['id'],
            'task_id' => (int) $row['task_id'],
            'user_id' => (int) $row['user_id'],
            'remind_at' => $row['remind_at'],
            'sent_at' => $row['sent_at'],
            'created_at' => $row['created_at'],
        ];
    }

    public static function deleteReminder(int $id): void
    {
        Database::connection()->prepare('DELETE FROM task_reminders WHERE id = ?')->execute([$id]);
    }

    public static function pendingReminders(string $now): array
    {
        $db = Database::connection();
        $stmt = $db->prepare(
            'SELECT r.*, t.title AS task_title, t.space_id
             FROM task_reminders r
             JOIN tasks t ON t.id = r.task_id
             WHERE r.sent_at IS NULL AND r.remind_at <= ?
             ORDER BY r.remind_at
             LIMIT 100'
        );
        $stmt->execute([$now]);
        return array_map(fn($r) => [
            'id' => (int) $r['id'],
            'task_id' => (int) $r['task_id'],
            'user_id' => (int) $r['user_id'],
            'remind_at' => $r['remind_at'],
            'task_title' => $r['task_title'],
            'space_id' => (int) $r['space_id'],
        ], $stmt->fetchAll());
    }

    public static function markReminderSent(int $id): void
    {
        Database::connection()->prepare(
            'UPDATE task_reminders SET sent_at = NOW() WHERE id = ?'
        )->execute([$id]);
    }

    public static function overdueReminders(string $now): array
    {
        $db = Database::connection();
        $stmt = $db->prepare(
            'SELECT t.id AS task_id, t.title, t.due_date, t.space_id, ta.user_id
             FROM tasks t
             JOIN task_assignees ta ON ta.task_id = t.id
             WHERE t.status IN ("open", "in_progress")
               AND t.due_date IS NOT NULL
               AND t.due_date <= ?
             ORDER BY t.due_date
             LIMIT 100'
        );
        $stmt->execute([$now]);
        return array_map(fn($r) => [
            'task_id' => (int) $r['task_id'],
            'title' => $r['title'],
            'due_date' => $r['due_date'],
            'space_id' => (int) $r['space_id'],
            'user_id' => (int) $r['user_id'],
        ], $stmt->fetchAll());
    }

    // ── Activity Log ─────────────────────────────────────

    public static function logActivity(int $taskId, int $userId, string $action, ?string $oldValue = null, ?string $newValue = null): void
    {
        Database::connection()->prepare(
            'INSERT INTO task_activity (task_id, user_id, action, old_value, new_value) VALUES (?, ?, ?, ?, ?)'
        )->execute([$taskId, $userId, $action, $oldValue, $newValue]);
    }

    public static function activity(int $taskId): array
    {
        $db = Database::connection();
        $stmt = $db->prepare(
            'SELECT a.*, u.display_name FROM task_activity a
             JOIN users u ON u.id = a.user_id
             WHERE a.task_id = ? ORDER BY a.created_at'
        );
        $stmt->execute([$taskId]);
        return array_map(fn($r) => [
            'id' => (int) $r['id'],
            'task_id' => (int) $r['task_id'],
            'user_id' => (int) $r['user_id'],
            'display_name' => $r['display_name'],
            'action' => $r['action'],
            'old_value' => $r['old_value'],
            'new_value' => $r['new_value'],
            'created_at' => $r['created_at'],
        ], $stmt->fetchAll());
    }

    // ── Hydration ────────────────────────────────────────

    private static function hydrate(array $row): array
    {
        return [
            'id' => (int) $row['id'],
            'space_id' => (int) $row['space_id'],
            'channel_id' => $row['channel_id'] !== null ? (int) $row['channel_id'] : null,
            'title' => $row['title'],
            'description' => $row['description'],
            'status' => $row['status'],
            'priority' => $row['priority'],
            'created_by' => (int) $row['created_by'],
            'source_message_id' => $row['source_message_id'] !== null ? (int) $row['source_message_id'] : null,
            'thread_id' => $row['thread_id'] !== null ? (int) $row['thread_id'] : null,
            'due_date' => $row['due_date'],
            'completed_at' => $row['completed_at'],
            'created_at' => $row['created_at'],
            'updated_at' => $row['updated_at'],
        ];
    }
}

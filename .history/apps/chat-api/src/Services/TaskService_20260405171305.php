<?php

declare(strict_types=1);

namespace App\Services;

use App\Exceptions\ApiException;
use App\Repositories\EventRepository;
use App\Repositories\NotificationRepository;
use App\Repositories\TaskRepository;
use App\Support\Database;

/**
 * Business logic for task management.
 *
 * Handles task CRUD, assignment, comments, reminders,
 * message-to-task conversion, and notification integration.
 */
final class TaskService
{
    private const VALID_STATUSES = ['open', 'in_progress', 'done', 'cancelled'];
    private const VALID_PRIORITIES = ['low', 'normal', 'high', 'urgent'];

    // ── Task CRUD ────────────────────────────────────────

    public static function create(int $spaceId, int $userId, array $input): array
    {
        self::requireSpaceMember($spaceId, $userId);

        $title = trim($input['title'] ?? '');
        if ($title === '' || mb_strlen($title) > 500) {
            throw ApiException::validation('Aufgaben-Titel erforderlich (max 500 Zeichen)', 'TASK_TITLE_INVALID');
        }

        if (isset($input['priority']) && !in_array($input['priority'], self::VALID_PRIORITIES, true)) {
            throw ApiException::validation('Ungültige Priorität', 'TASK_PRIORITY_INVALID');
        }

        if (isset($input['due_date'])) {
            $due = self::parseDate($input['due_date']);
            if ($due === null) {
                throw ApiException::validation('Ungültiges Fälligkeitsdatum', 'TASK_DUE_DATE_INVALID');
            }
        }

        $task = TaskRepository::create([
            'space_id' => $spaceId,
            'channel_id' => isset($input['channel_id']) ? (int) $input['channel_id'] : null,
            'title' => $title,
            'description' => isset($input['description']) ? trim($input['description']) : null,
            'status' => 'open',
            'priority' => $input['priority'] ?? 'normal',
            'created_by' => $userId,
            'source_message_id' => isset($input['source_message_id']) ? (int) $input['source_message_id'] : null,
            'thread_id' => isset($input['thread_id']) ? (int) $input['thread_id'] : null,
            'due_date' => isset($due) ? $due : null,
        ]);

        TaskRepository::logActivity($task['id'], $userId, 'created');

        // Auto-assign to specified users
        if (!empty($input['assignee_ids'])) {
            foreach ((array) $input['assignee_ids'] as $assigneeId) {
                TaskRepository::assign($task['id'], (int) $assigneeId, $userId);
                TaskRepository::logActivity($task['id'], $userId, 'assigned', null, (string) $assigneeId);
                self::notifyAssignment($task, (int) $assigneeId, $userId);
            }
        }

        // Auto-create due-date reminder if due_date is set
        if ($task['due_date'] !== null && !empty($input['assignee_ids'])) {
            $reminderTime = date('Y-m-d H:i:s', strtotime($task['due_date']) - 3600);
            if (strtotime($reminderTime) > time()) {
                foreach ((array) $input['assignee_ids'] as $assigneeId) {
                    TaskRepository::addReminder($task['id'], (int) $assigneeId, $reminderTime);
                }
            }
        }

        $task['assignees'] = TaskRepository::assignees($task['id']);
        return $task;
    }

    public static function get(int $taskId, int $userId): array
    {
        $task = TaskRepository::find($taskId);
        if (!$task) {
            throw ApiException::notFound('Aufgabe nicht gefunden', 'TASK_NOT_FOUND');
        }
        self::requireSpaceMember($task['space_id'], $userId);

        $task['assignees'] = TaskRepository::assignees($taskId);
        $task['comments'] = TaskRepository::listComments($taskId);
        return $task;
    }

    public static function listForSpace(int $spaceId, int $userId, ?string $status = null, ?int $assigneeId = null, ?int $channelId = null): array
    {
        self::requireSpaceMember($spaceId, $userId);

        if ($status !== null && !in_array($status, self::VALID_STATUSES, true)) {
            throw ApiException::validation('Ungültiger Status-Filter', 'TASK_STATUS_INVALID');
        }

        return TaskRepository::listForSpace($spaceId, $status, $assigneeId, $channelId);
    }

    public static function myTasks(int $userId, ?string $status = null): array
    {
        return TaskRepository::myTasks($userId, $status);
    }

    public static function update(int $taskId, int $userId, array $input): array
    {
        $task = TaskRepository::find($taskId);
        if (!$task) {
            throw ApiException::notFound('Aufgabe nicht gefunden', 'TASK_NOT_FOUND');
        }
        self::requireSpaceMember($task['space_id'], $userId);

        $data = [];

        if (isset($input['title'])) {
            $t = trim($input['title']);
            if ($t === '' || mb_strlen($t) > 500) {
                throw ApiException::validation('Titel ungültig', 'TASK_TITLE_INVALID');
            }
            $data['title'] = $t;
        }

        if (array_key_exists('description', $input)) {
            $data['description'] = $input['description'] !== null ? trim($input['description']) : null;
        }

        if (isset($input['status'])) {
            if (!in_array($input['status'], self::VALID_STATUSES, true)) {
                throw ApiException::validation('Ungültiger Status', 'TASK_STATUS_INVALID');
            }
            if ($input['status'] !== $task['status']) {
                TaskRepository::logActivity($taskId, $userId, 'status_changed', $task['status'], $input['status']);
                $data['status'] = $input['status'];

                if ($input['status'] === 'done') {
                    $data['completed_at'] = date('Y-m-d H:i:s');
                } elseif ($task['status'] === 'done') {
                    $data['completed_at'] = null;
                }
            }
        }

        if (isset($input['priority'])) {
            if (!in_array($input['priority'], self::VALID_PRIORITIES, true)) {
                throw ApiException::validation('Ungültige Priorität', 'TASK_PRIORITY_INVALID');
            }
            $data['priority'] = $input['priority'];
        }

        if (array_key_exists('due_date', $input)) {
            if ($input['due_date'] !== null) {
                $due = self::parseDate($input['due_date']);
                if ($due === null) {
                    throw ApiException::validation('Ungültiges Fälligkeitsdatum', 'TASK_DUE_DATE_INVALID');
                }
                $data['due_date'] = $due;
            } else {
                $data['due_date'] = null;
            }
            TaskRepository::logActivity($taskId, $userId, 'due_changed', $task['due_date'], $data['due_date']);
        }

        $updated = TaskRepository::update($taskId, $data);
        $updated['assignees'] = TaskRepository::assignees($taskId);
        return $updated;
    }

    public static function delete(int $taskId, int $userId): void
    {
        $task = TaskRepository::find($taskId);
        if (!$task) {
            throw ApiException::notFound('Aufgabe nicht gefunden', 'TASK_NOT_FOUND');
        }
        self::requireSpaceAdmin($task['space_id'], $userId);
        TaskRepository::delete($taskId);
    }

    public static function stats(int $spaceId, int $userId): array
    {
        self::requireSpaceMember($spaceId, $userId);
        return TaskRepository::countForSpace($spaceId);
    }

    // ── Assignment ───────────────────────────────────────

    public static function assign(int $taskId, int $userId, int $actorId): void
    {
        $task = TaskRepository::find($taskId);
        if (!$task) {
            throw ApiException::notFound('Aufgabe nicht gefunden', 'TASK_NOT_FOUND');
        }
        self::requireSpaceMember($task['space_id'], $actorId);
        self::requireSpaceMember($task['space_id'], $userId);

        if (TaskRepository::isAssigned($taskId, $userId)) {
            throw ApiException::conflict('Benutzer bereits zugewiesen', 'TASK_ALREADY_ASSIGNED');
        }

        TaskRepository::assign($taskId, $userId, $actorId);
        TaskRepository::logActivity($taskId, $actorId, 'assigned', null, (string) $userId);
        self::notifyAssignment($task, $userId, $actorId);
    }

    public static function unassign(int $taskId, int $userId, int $actorId): void
    {
        $task = TaskRepository::find($taskId);
        if (!$task) {
            throw ApiException::notFound('Aufgabe nicht gefunden', 'TASK_NOT_FOUND');
        }
        self::requireSpaceMember($task['space_id'], $actorId);

        TaskRepository::unassign($taskId, $userId);
        TaskRepository::logActivity($taskId, $actorId, 'unassigned', (string) $userId, null);
    }

    // ── Comments ─────────────────────────────────────────

    public static function addComment(int $taskId, int $userId, array $input): array
    {
        $task = TaskRepository::find($taskId);
        if (!$task) {
            throw ApiException::notFound('Aufgabe nicht gefunden', 'TASK_NOT_FOUND');
        }
        self::requireSpaceMember($task['space_id'], $userId);

        $body = trim($input['body'] ?? '');
        if ($body === '' || mb_strlen($body) > 5000) {
            throw ApiException::validation('Kommentar erforderlich (max 5000 Zeichen)', 'TASK_COMMENT_INVALID');
        }

        $comment = TaskRepository::addComment($taskId, $userId, $body);
        TaskRepository::logActivity($taskId, $userId, 'commented');

        // Notify assignees about the comment (except the commenter)
        $assignees = TaskRepository::assignees($taskId);
        foreach ($assignees as $assignee) {
            if ($assignee['user_id'] !== $userId) {
                self::notifyTaskComment($task, $comment, $assignee['user_id'], $userId);
            }
        }

        return $comment;
    }

    public static function listComments(int $taskId, int $userId): array
    {
        $task = TaskRepository::find($taskId);
        if (!$task) {
            throw ApiException::notFound('Aufgabe nicht gefunden', 'TASK_NOT_FOUND');
        }
        self::requireSpaceMember($task['space_id'], $userId);
        return TaskRepository::listComments($taskId);
    }

    // ── Reminders ────────────────────────────────────────

    public static function addReminder(int $taskId, int $userId, array $input): array
    {
        $task = TaskRepository::find($taskId);
        if (!$task) {
            throw ApiException::notFound('Aufgabe nicht gefunden', 'TASK_NOT_FOUND');
        }
        self::requireSpaceMember($task['space_id'], $userId);

        $remindAt = self::parseDate($input['remind_at'] ?? '');
        if ($remindAt === null) {
            throw ApiException::validation('Ungültiger Erinnerungszeitpunkt', 'TASK_REMINDER_INVALID');
        }

        if (strtotime($remindAt) <= time()) {
            throw ApiException::validation('Erinnerung muss in der Zukunft liegen', 'TASK_REMINDER_PAST');
        }

        $reminder = TaskRepository::addReminder($taskId, $userId, $remindAt);
        TaskRepository::logActivity($taskId, $userId, 'reminder_set', null, $remindAt);
        return $reminder;
    }

    public static function deleteReminder(int $reminderId, int $userId): void
    {
        $reminder = TaskRepository::findReminder($reminderId);
        if (!$reminder) {
            throw ApiException::notFound('Erinnerung nicht gefunden', 'TASK_REMINDER_NOT_FOUND');
        }
        if ($reminder['user_id'] !== $userId) {
            throw ApiException::forbidden('Nur eigene Erinnerungen löschbar', 'TASK_REMINDER_FORBIDDEN');
        }
        TaskRepository::deleteReminder($reminderId);
    }

    // ── Message-to-Task Conversion ───────────────────────

    public static function createFromMessage(int $messageId, int $userId, array $input): array
    {
        $db = Database::connection();
        $stmt = $db->prepare(
            'SELECT m.*, c.space_id FROM messages m
             LEFT JOIN channels c ON c.id = m.channel_id
             LEFT JOIN conversations cv ON cv.id = m.conversation_id
             WHERE m.id = ? AND m.deleted_at IS NULL'
        );
        $stmt->execute([$messageId]);
        $msg = $stmt->fetch();

        if (!$msg) {
            throw ApiException::notFound('Nachricht nicht gefunden', 'MESSAGE_NOT_FOUND');
        }

        $spaceId = (int) ($msg['space_id'] ?? 0);
        if (!$spaceId && $msg['conversation_id']) {
            $cv = $db->prepare('SELECT space_id FROM conversations WHERE id = ?');
            $cv->execute([$msg['conversation_id']]);
            $cvRow = $cv->fetch();
            $spaceId = $cvRow ? (int) $cvRow['space_id'] : 0;
        }

        if (!$spaceId) {
            throw ApiException::validation('Space konnte nicht ermittelt werden', 'SPACE_RESOLVE_FAILED');
        }

        self::requireSpaceMember($spaceId, $userId);

        $title = trim($input['title'] ?? '');
        if ($title === '') {
            $title = mb_substr(strip_tags($msg['body']), 0, 200);
            if ($title === '') {
                $title = 'Aufgabe aus Nachricht #' . $messageId;
            }
        }

        return self::create($spaceId, $userId, array_merge($input, [
            'title' => $title,
            'source_message_id' => $messageId,
            'thread_id' => $msg['thread_id'] ? (int) $msg['thread_id'] : null,
            'channel_id' => $msg['channel_id'] ? (int) $msg['channel_id'] : null,
        ]));
    }

    // ── Activity ─────────────────────────────────────────

    public static function activity(int $taskId, int $userId): array
    {
        $task = TaskRepository::find($taskId);
        if (!$task) {
            throw ApiException::notFound('Aufgabe nicht gefunden', 'TASK_NOT_FOUND');
        }
        self::requireSpaceMember($task['space_id'], $userId);
        return TaskRepository::activity($taskId);
    }

    // ── Reminder Processing (called by job handler) ──────

    public static function processPendingReminders(): int
    {
        $now = date('Y-m-d H:i:s');
        $reminders = TaskRepository::pendingReminders($now);
        $count = 0;

        foreach ($reminders as $reminder) {
            NotificationRepository::create(
                $reminder['user_id'],
                'task_reminder',
                $reminder['user_id'],
                null,
                null,
                null,
                null,
                ['task_id' => $reminder['task_id'], 'task_title' => $reminder['task_title']],
                $reminder['space_id']
            );

            EventRepository::publish(
                'notification.created',
                "user:{$reminder['user_id']}",
                ['type' => 'task_reminder', 'task_id' => $reminder['task_id'], 'task_title' => $reminder['task_title']]
            );

            TaskRepository::markReminderSent($reminder['id']);
            $count++;
        }

        return $count;
    }

    public static function processOverdueNotifications(): int
    {
        $now = date('Y-m-d H:i:s');
        $overdue = TaskRepository::overdueReminders($now);
        $count = 0;

        foreach ($overdue as $item) {
            $key = "task_overdue:{$item['task_id']}:{$item['user_id']}:" . date('Y-m-d');
            $db = Database::connection();
            $stmt = $db->prepare(
                "SELECT 1 FROM notifications WHERE data->'$.task_id' = ? AND user_id = ? AND type = 'task_overdue' AND DATE(created_at) = CURDATE()"
            );
            $stmt->execute([$item['task_id'], $item['user_id']]);
            if ($stmt->fetch()) {
                continue;
            }

            NotificationRepository::create(
                $item['user_id'],
                'task_overdue',
                $item['user_id'],
                null,
                null,
                null,
                null,
                ['task_id' => $item['task_id'], 'task_title' => $item['title'], 'due_date' => $item['due_date']],
                $item['space_id']
            );

            EventRepository::publish(
                'notification.created',
                "user:{$item['user_id']}",
                ['type' => 'task_overdue', 'task_id' => $item['task_id'], 'task_title' => $item['title']]
            );

            $count++;
        }

        return $count;
    }

    // ── Helpers ──────────────────────────────────────────

    private static function requireSpaceMember(int $spaceId, int $userId): void
    {
        $db = Database::connection();
        $stmt = $db->prepare('SELECT 1 FROM space_members WHERE space_id = ? AND user_id = ?');
        $stmt->execute([$spaceId, $userId]);
        if (!$stmt->fetch()) {
            throw ApiException::forbidden('Kein Mitglied dieses Space', 'SPACE_MEMBER_REQUIRED');
        }
    }

    private static function requireSpaceAdmin(int $spaceId, int $userId): void
    {
        $db = Database::connection();
        $stmt = $db->prepare("SELECT role FROM space_members WHERE space_id = ? AND user_id = ?");
        $stmt->execute([$spaceId, $userId]);
        $row = $stmt->fetch();
        if (!$row || !in_array($row['role'], ['owner', 'admin'], true)) {
            throw ApiException::forbidden('Admin-Berechtigung erforderlich', 'ADMIN_REQUIRED');
        }
    }

    private static function parseDate(string $value): ?string
    {
        if ($value === '') {
            return null;
        }
        $ts = strtotime($value);
        if ($ts === false) {
            return null;
        }
        return date('Y-m-d H:i:s', $ts);
    }

    private static function notifyAssignment(array $task, int $assigneeId, int $actorId): void
    {
        if ($assigneeId === $actorId) {
            return;
        }

        NotificationRepository::create(
            $assigneeId,
            'task_assigned',
            $actorId,
            $task['source_message_id'],
            $task['channel_id'],
            null,
            $task['thread_id'],
            ['task_id' => $task['id'], 'task_title' => $task['title']],
            $task['space_id']
        );

        EventRepository::publish(
            'notification.created',
            "user:$assigneeId",
            ['type' => 'task_assigned', 'task_id' => $task['id'], 'task_title' => $task['title']]
        );
    }

    private static function notifyTaskComment(array $task, array $comment, int $recipientId, int $actorId): void
    {
        NotificationRepository::create(
            $recipientId,
            'task_comment',
            $actorId,
            null,
            $task['channel_id'],
            null,
            null,
            ['task_id' => $task['id'], 'task_title' => $task['title'], 'comment_id' => $comment['id']],
            $task['space_id']
        );

        EventRepository::publish(
            'notification.created',
            "user:$recipientId",
            ['type' => 'task_comment', 'task_id' => $task['id'], 'task_title' => $task['title']]
        );
    }
}

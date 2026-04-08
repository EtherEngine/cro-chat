<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Services\TaskService;
use App\Support\Request;
use App\Support\Response;

/**
 * REST endpoints for task management.
 *
 * Tasks, assignments, comments, reminders, activity, and message-to-task conversion.
 */
final class TaskController
{
    // ── Task CRUD ────────────────────────────────────────

    /** GET /api/spaces/{spaceId}/tasks */
    public function index(int $spaceId): void
    {
        $userId = Request::requireUserId();
        $status = Request::query('status');
        $assigneeId = Request::queryInt('assignee_id') ?: null;
        $channelId = Request::queryInt('channel_id') ?: null;
        Response::json(TaskService::listForSpace($spaceId, $userId, $status, $assigneeId, $channelId));
    }

    /** POST /api/spaces/{spaceId}/tasks */
    public function create(int $spaceId): void
    {
        $userId = Request::requireUserId();
        $task = TaskService::create($spaceId, $userId, Request::json());
        Response::json($task, 201);
    }

    /** GET /api/tasks/my */
    public function myTasks(): void
    {
        $userId = Request::requireUserId();
        $status = Request::query('status');
        Response::json(TaskService::myTasks($userId, $status));
    }

    /** GET /api/tasks/{taskId} */
    public function show(int $taskId): void
    {
        $userId = Request::requireUserId();
        Response::json(TaskService::get($taskId, $userId));
    }

    /** PUT /api/tasks/{taskId} */
    public function update(int $taskId): void
    {
        $userId = Request::requireUserId();
        Response::json(TaskService::update($taskId, $userId, Request::json()));
    }

    /** DELETE /api/tasks/{taskId} */
    public function delete(int $taskId): void
    {
        $userId = Request::requireUserId();
        TaskService::delete($taskId, $userId);
        Response::json(['deleted' => true]);
    }

    /** GET /api/spaces/{spaceId}/tasks/stats */
    public function stats(int $spaceId): void
    {
        $userId = Request::requireUserId();
        Response::json(TaskService::stats($spaceId, $userId));
    }

    // ── Assignment ───────────────────────────────────────

    /** POST /api/tasks/{taskId}/assignees */
    public function assign(int $taskId): void
    {
        $userId = Request::requireUserId();
        $input = Request::json();
        $assigneeId = (int) ($input['user_id'] ?? 0);
        TaskService::assign($taskId, $assigneeId, $userId);
        Response::json(['assigned' => true], 201);
    }

    /** DELETE /api/tasks/{taskId}/assignees/{userId} */
    public function unassign(int $taskId, int $userId): void
    {
        $actorId = Request::requireUserId();
        TaskService::unassign($taskId, $userId, $actorId);
        Response::json(['unassigned' => true]);
    }

    // ── Comments ─────────────────────────────────────────

    /** GET /api/tasks/{taskId}/comments */
    public function listComments(int $taskId): void
    {
        $userId = Request::requireUserId();
        Response::json(TaskService::listComments($taskId, $userId));
    }

    /** POST /api/tasks/{taskId}/comments */
    public function addComment(int $taskId): void
    {
        $userId = Request::requireUserId();
        $comment = TaskService::addComment($taskId, $userId, Request::json());
        Response::json($comment, 201);
    }

    // ── Reminders ────────────────────────────────────────

    /** POST /api/tasks/{taskId}/reminders */
    public function addReminder(int $taskId): void
    {
        $userId = Request::requireUserId();
        $reminder = TaskService::addReminder($taskId, $userId, Request::json());
        Response::json($reminder, 201);
    }

    /** DELETE /api/reminders/{reminderId} */
    public function deleteReminder(int $reminderId): void
    {
        $userId = Request::requireUserId();
        TaskService::deleteReminder($reminderId, $userId);
        Response::json(['deleted' => true]);
    }

    // ── Activity ─────────────────────────────────────────

    /** GET /api/tasks/{taskId}/activity */
    public function activity(int $taskId): void
    {
        $userId = Request::requireUserId();
        Response::json(TaskService::activity($taskId, $userId));
    }

    // ── Message-to-Task ──────────────────────────────────

    /** POST /api/messages/{messageId}/task */
    public function createFromMessage(int $messageId): void
    {
        $userId = Request::requireUserId();
        $task = TaskService::createFromMessage($messageId, $userId, Request::json());
        Response::json($task, 201);
    }
}

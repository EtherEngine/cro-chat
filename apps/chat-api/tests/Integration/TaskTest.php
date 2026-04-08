<?php

declare(strict_types=1);

namespace Tests\Integration;

use App\Repositories\TaskRepository;
use App\Services\TaskService;
use Tests\TestCase;

/**
 * Integration tests for Task management: CRUD, assignment, comments, reminders, message-to-task.
 */
final class TaskTest extends TestCase
{
    private array $admin;
    private array $user;
    private array $space;
    private array $channel;

    protected function setUp(): void
    {
        parent::setUp();
        $this->admin = $this->createUser(['display_name' => 'Admin']);
        $this->user = $this->createUser(['display_name' => 'Member']);
        $this->space = $this->createSpace($this->admin['id']);
        $this->addSpaceMember($this->space['id'], $this->user['id']);
        $this->channel = $this->createChannel($this->space['id'], $this->admin['id']);
        $this->addChannelMember($this->channel['id'], $this->user['id']);
    }

    // ── Task CRUD ────────────────────────────────────────

    public function test_create_task(): void
    {
        $this->actingAs($this->user['id']);
        $task = TaskService::create($this->space['id'], $this->user['id'], [
            'title' => 'API-Dokumentation schreiben',
            'description' => 'Alle Endpoints dokumentieren',
            'priority' => 'high',
        ]);

        $this->assertSame('API-Dokumentation schreiben', $task['title']);
        $this->assertSame('open', $task['status']);
        $this->assertSame('high', $task['priority']);
        $this->assertSame($this->user['id'], $task['created_by']);
        $this->assertNotEmpty($task['id']);
    }

    public function test_create_task_with_due_date(): void
    {
        $this->actingAs($this->user['id']);
        $due = date('Y-m-d H:i:s', strtotime('+7 days'));
        $task = TaskService::create($this->space['id'], $this->user['id'], [
            'title' => 'Deploy vorbereiten',
            'due_date' => $due,
        ]);

        $this->assertNotNull($task['due_date']);
    }

    public function test_create_task_invalid_title_fails(): void
    {
        $this->actingAs($this->user['id']);
        $this->assertApiException(422, 'TASK_TITLE_INVALID', function () {
            TaskService::create($this->space['id'], $this->user['id'], ['title' => '']);
        });
    }

    public function test_create_task_invalid_priority_fails(): void
    {
        $this->actingAs($this->user['id']);
        $this->assertApiException(422, 'TASK_PRIORITY_INVALID', function () {
            TaskService::create($this->space['id'], $this->user['id'], [
                'title' => 'Test',
                'priority' => 'extreme',
            ]);
        });
    }

    public function test_list_tasks_for_space(): void
    {
        $this->actingAs($this->user['id']);
        TaskService::create($this->space['id'], $this->user['id'], ['title' => 'A', 'priority' => 'high']);
        TaskService::create($this->space['id'], $this->user['id'], ['title' => 'B', 'priority' => 'low']);

        $tasks = TaskService::listForSpace($this->space['id'], $this->user['id']);
        $this->assertCount(2, $tasks);
    }

    public function test_list_tasks_filter_by_status(): void
    {
        $this->actingAs($this->user['id']);
        $t1 = TaskService::create($this->space['id'], $this->user['id'], ['title' => 'Open']);
        TaskService::create($this->space['id'], $this->user['id'], ['title' => 'Done']);
        TaskService::update($t1['id'], $this->user['id'], ['status' => 'done']);

        $open = TaskService::listForSpace($this->space['id'], $this->user['id'], 'open');
        $this->assertCount(1, $open);
        $this->assertSame('Done', $open[0]['title']);
    }

    public function test_get_task_with_details(): void
    {
        $this->actingAs($this->user['id']);
        $task = TaskService::create($this->space['id'], $this->user['id'], ['title' => 'Test']);
        TaskService::assign($task['id'], $this->user['id'], $this->admin['id']);

        $detail = TaskService::get($task['id'], $this->user['id']);
        $this->assertArrayHasKey('assignees', $detail);
        $this->assertArrayHasKey('comments', $detail);
        $this->assertCount(1, $detail['assignees']);
    }

    public function test_update_task_status(): void
    {
        $this->actingAs($this->user['id']);
        $task = TaskService::create($this->space['id'], $this->user['id'], ['title' => 'In Progress']);

        $updated = TaskService::update($task['id'], $this->user['id'], ['status' => 'in_progress']);
        $this->assertSame('in_progress', $updated['status']);
        $this->assertNull($updated['completed_at']);

        $done = TaskService::update($task['id'], $this->user['id'], ['status' => 'done']);
        $this->assertSame('done', $done['status']);
        $this->assertNotNull($done['completed_at']);
    }

    public function test_update_task_invalid_status_fails(): void
    {
        $this->actingAs($this->user['id']);
        $task = TaskService::create($this->space['id'], $this->user['id'], ['title' => 'Test']);

        $this->assertApiException(422, 'TASK_STATUS_INVALID', function () use ($task) {
            TaskService::update($task['id'], $this->user['id'], ['status' => 'invalid']);
        });
    }

    public function test_delete_task_requires_admin(): void
    {
        $this->actingAs($this->user['id']);
        $task = TaskService::create($this->space['id'], $this->user['id'], ['title' => 'Test']);

        $this->assertApiException(403, 'ADMIN_REQUIRED', function () use ($task) {
            TaskService::delete($task['id'], $this->user['id']);
        });
    }

    public function test_admin_can_delete_task(): void
    {
        $this->actingAs($this->admin['id']);
        $task = TaskService::create($this->space['id'], $this->admin['id'], ['title' => 'ToDelete']);
        TaskService::delete($task['id'], $this->admin['id']);

        $this->assertApiException(404, 'TASK_NOT_FOUND', function () use ($task) {
            TaskService::get($task['id'], $this->admin['id']);
        });
    }

    public function test_non_member_cannot_create_task(): void
    {
        $outsider = $this->createUser(['display_name' => 'Outsider']);
        $this->assertApiException(403, 'SPACE_MEMBER_REQUIRED', function () use ($outsider) {
            TaskService::create($this->space['id'], $outsider['id'], ['title' => 'Test']);
        });
    }

    public function test_task_stats(): void
    {
        $this->actingAs($this->user['id']);
        TaskService::create($this->space['id'], $this->user['id'], ['title' => 'A']);
        TaskService::create($this->space['id'], $this->user['id'], ['title' => 'B']);
        $t = TaskService::create($this->space['id'], $this->user['id'], ['title' => 'C']);
        TaskService::update($t['id'], $this->user['id'], ['status' => 'done']);

        $stats = TaskService::stats($this->space['id'], $this->user['id']);
        $this->assertSame(2, $stats['open']);
        $this->assertSame(1, $stats['done']);
        $this->assertSame(3, $stats['total']);
    }

    // ── Assignment ───────────────────────────────────────

    public function test_assign_and_unassign(): void
    {
        $this->actingAs($this->admin['id']);
        $task = TaskService::create($this->space['id'], $this->admin['id'], ['title' => 'Assign Test']);

        TaskService::assign($task['id'], $this->user['id'], $this->admin['id']);
        $detail = TaskService::get($task['id'], $this->admin['id']);
        $this->assertCount(1, $detail['assignees']);
        $this->assertSame($this->user['id'], $detail['assignees'][0]['user_id']);

        TaskService::unassign($task['id'], $this->user['id'], $this->admin['id']);
        $detail2 = TaskService::get($task['id'], $this->admin['id']);
        $this->assertCount(0, $detail2['assignees']);
    }

    public function test_duplicate_assignment_fails(): void
    {
        $this->actingAs($this->admin['id']);
        $task = TaskService::create($this->space['id'], $this->admin['id'], ['title' => 'Test']);
        TaskService::assign($task['id'], $this->user['id'], $this->admin['id']);

        $this->assertApiException(409, 'TASK_ALREADY_ASSIGNED', function () use ($task) {
            TaskService::assign($task['id'], $this->user['id'], $this->admin['id']);
        });
    }

    public function test_my_tasks(): void
    {
        $this->actingAs($this->user['id']);
        $t1 = TaskService::create($this->space['id'], $this->user['id'], ['title' => 'My Task']);
        TaskService::assign($t1['id'], $this->user['id'], $this->user['id']);
        TaskService::create($this->space['id'], $this->admin['id'], ['title' => 'Not Mine']);

        $mine = TaskService::myTasks($this->user['id']);
        $this->assertCount(1, $mine);
        $this->assertSame('My Task', $mine[0]['title']);
    }

    public function test_create_task_with_auto_assign(): void
    {
        $this->actingAs($this->admin['id']);
        $task = TaskService::create($this->space['id'], $this->admin['id'], [
            'title' => 'Auto Assign',
            'assignee_ids' => [$this->user['id']],
        ]);

        $this->assertCount(1, $task['assignees']);
        $this->assertSame($this->user['id'], $task['assignees'][0]['user_id']);
    }

    // ── Comments ─────────────────────────────────────────

    public function test_add_and_list_comments(): void
    {
        $this->actingAs($this->user['id']);
        $task = TaskService::create($this->space['id'], $this->user['id'], ['title' => 'Commented']);

        $c1 = TaskService::addComment($task['id'], $this->user['id'], ['body' => 'First comment']);
        $c2 = TaskService::addComment($task['id'], $this->admin['id'], ['body' => 'Second comment']);

        $comments = TaskService::listComments($task['id'], $this->user['id']);
        $this->assertCount(2, $comments);
        $this->assertSame('First comment', $comments[0]['body']);
        $this->assertSame('Second comment', $comments[1]['body']);
    }

    public function test_empty_comment_fails(): void
    {
        $this->actingAs($this->user['id']);
        $task = TaskService::create($this->space['id'], $this->user['id'], ['title' => 'Test']);

        $this->assertApiException(422, 'TASK_COMMENT_INVALID', function () use ($task) {
            TaskService::addComment($task['id'], $this->user['id'], ['body' => '']);
        });
    }

    // ── Reminders ────────────────────────────────────────

    public function test_add_reminder(): void
    {
        $this->actingAs($this->user['id']);
        $task = TaskService::create($this->space['id'], $this->user['id'], ['title' => 'Remind Me']);

        $future = date('Y-m-d H:i:s', strtotime('+1 hour'));
        $reminder = TaskService::addReminder($task['id'], $this->user['id'], ['remind_at' => $future]);

        $this->assertSame($task['id'], $reminder['task_id']);
        $this->assertSame($this->user['id'], $reminder['user_id']);
        $this->assertNull($reminder['sent_at']);
    }

    public function test_past_reminder_fails(): void
    {
        $this->actingAs($this->user['id']);
        $task = TaskService::create($this->space['id'], $this->user['id'], ['title' => 'Test']);

        $this->assertApiException(422, 'TASK_REMINDER_PAST', function () use ($task) {
            TaskService::addReminder($task['id'], $this->user['id'], [
                'remind_at' => '2020-01-01 00:00:00',
            ]);
        });
    }

    public function test_delete_own_reminder(): void
    {
        $this->actingAs($this->user['id']);
        $task = TaskService::create($this->space['id'], $this->user['id'], ['title' => 'Test']);
        $future = date('Y-m-d H:i:s', strtotime('+2 hours'));
        $reminder = TaskService::addReminder($task['id'], $this->user['id'], ['remind_at' => $future]);

        TaskService::deleteReminder($reminder['id'], $this->user['id']);

        $this->assertApiException(404, 'TASK_REMINDER_NOT_FOUND', function () use ($reminder) {
            TaskService::deleteReminder($reminder['id'], $this->user['id']);
        });
    }

    public function test_cannot_delete_other_users_reminder(): void
    {
        $this->actingAs($this->user['id']);
        $task = TaskService::create($this->space['id'], $this->user['id'], ['title' => 'Test']);
        $future = date('Y-m-d H:i:s', strtotime('+2 hours'));
        $reminder = TaskService::addReminder($task['id'], $this->user['id'], ['remind_at' => $future]);

        $this->assertApiException(403, 'TASK_REMINDER_FORBIDDEN', function () use ($reminder) {
            TaskService::deleteReminder($reminder['id'], $this->admin['id']);
        });
    }

    // ── Message-to-Task ──────────────────────────────────

    public function test_create_task_from_message(): void
    {
        $this->actingAs($this->user['id']);
        $msg = $this->createMessage($this->user['id'], $this->channel['id'], null, 'Bitte API-Tests schreiben');

        $task = TaskService::createFromMessage($msg['id'], $this->user['id'], []);
        $this->assertSame($msg['id'], $task['source_message_id']);
        $this->assertStringContainsString('API-Tests', $task['title']);
        $this->assertSame($this->channel['id'], $task['channel_id']);
    }

    public function test_create_task_from_message_with_custom_title(): void
    {
        $this->actingAs($this->user['id']);
        $msg = $this->createMessage($this->user['id'], $this->channel['id'], null, 'Some message');

        $task = TaskService::createFromMessage($msg['id'], $this->user['id'], [
            'title' => 'Custom Title',
            'priority' => 'urgent',
        ]);
        $this->assertSame('Custom Title', $task['title']);
        $this->assertSame('urgent', $task['priority']);
    }

    public function test_create_task_from_nonexistent_message_fails(): void
    {
        $this->actingAs($this->user['id']);
        $this->assertApiException(404, 'MESSAGE_NOT_FOUND', function () {
            TaskService::createFromMessage(99999, $this->user['id'], []);
        });
    }

    // ── Activity Log ─────────────────────────────────────

    public function test_activity_log(): void
    {
        $this->actingAs($this->user['id']);
        $task = TaskService::create($this->space['id'], $this->user['id'], ['title' => 'Tracked']);
        TaskService::update($task['id'], $this->user['id'], ['status' => 'in_progress']);
        TaskService::addComment($task['id'], $this->user['id'], ['body' => 'A comment']);

        $log = TaskService::activity($task['id'], $this->user['id']);
        $actions = array_column($log, 'action');
        $this->assertContains('created', $actions);
        $this->assertContains('status_changed', $actions);
        $this->assertContains('commented', $actions);
    }

    // ── Reminder Processing ──────────────────────────────

    public function test_process_pending_reminders(): void
    {
        $this->actingAs($this->user['id']);
        $task = TaskService::create($this->space['id'], $this->user['id'], ['title' => 'Remind Task']);

        // Insert a reminder that is already due
        $past = date('Y-m-d H:i:s', strtotime('-1 minute'));
        $db = \App\Support\Database::connection();
        $db->prepare('INSERT INTO task_reminders (task_id, user_id, remind_at) VALUES (?, ?, ?)')
            ->execute([$task['id'], $this->user['id'], $past]);

        $count = TaskService::processPendingReminders();
        $this->assertSame(1, $count);

        // Should not re-send
        $count2 = TaskService::processPendingReminders();
        $this->assertSame(0, $count2);
    }

    public function test_process_overdue_notifications(): void
    {
        $this->actingAs($this->user['id']);
        $past = date('Y-m-d H:i:s', strtotime('-1 hour'));
        $task = TaskService::create($this->space['id'], $this->user['id'], [
            'title' => 'Overdue Task',
            'due_date' => $past,
        ]);
        TaskService::assign($task['id'], $this->user['id'], $this->admin['id']);

        $count = TaskService::processOverdueNotifications();
        $this->assertSame(1, $count);

        // Idempotent: same day won't re-notify
        $count2 = TaskService::processOverdueNotifications();
        $this->assertSame(0, $count2);
    }
}

<?php

declare(strict_types=1);

namespace App\Jobs\Handlers;

use App\Jobs\JobHandler;
use App\Services\TaskService;
use App\Support\Logger;

/**
 * Processes pending task reminders and overdue notifications.
 *
 * Designed to be scheduled periodically (e.g. every 5 minutes via maintenance queue).
 *
 * Idempotent:
 *  - Reminders have sent_at flag — won't be sent twice
 *  - Overdue notifications check for existing same-day notification before creating
 *
 * Payload: {} (empty — scans all pending reminders globally)
 */
final class TaskReminderHandler implements JobHandler
{
    public function handle(array $payload): void
    {
        Logger::info('[TaskReminder] Processing pending reminders');

        $reminderCount = TaskService::processPendingReminders();
        Logger::info("[TaskReminder] Sent $reminderCount reminder notifications");

        $overdueCount = TaskService::processOverdueNotifications();
        Logger::info("[TaskReminder] Sent $overdueCount overdue notifications");
    }
}

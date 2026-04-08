<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Exceptions\ApiException;
use App\Repositories\NotificationRepository;
use App\Support\Request;
use App\Support\Response;

final class NotificationController
{
    /**
     * GET /api/notifications?before=<cursor>
     */
    public function index(): void
    {
        $userId = Request::requireUserId();
        $before = isset($_GET['before']) ? (int) $_GET['before'] : null;
        $limit = isset($_GET['limit']) ? min((int) $_GET['limit'], 100) : 30;

        $result = NotificationRepository::forUser($userId, $before, $limit);
        Response::json($result);
    }

    /**
     * GET /api/notifications/unread-count
     */
    public function unreadCount(): void
    {
        $userId = Request::requireUserId();
        Response::json(['unread_count' => NotificationRepository::unreadCount($userId)]);
    }

    /**
     * POST /api/notifications/{notificationId}/read
     */
    public function markRead(int $notificationId): void
    {
        $userId = Request::requireUserId();

        $marked = NotificationRepository::markRead($notificationId, $userId);
        if (!$marked) {
            throw ApiException::notFound('Benachrichtigung nicht gefunden oder bereits gelesen', 'NOTIFICATION_NOT_FOUND');
        }

        Response::json(['success' => true]);
    }

    /**
     * POST /api/notifications/read-all
     */
    public function markAllRead(): void
    {
        $userId = Request::requireUserId();
        $count = NotificationRepository::markAllRead($userId);
        Response::json(['marked' => $count]);
    }
}

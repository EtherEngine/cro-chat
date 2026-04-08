<?php

namespace App\Controllers;

use App\Services\PresenceService;
use App\Repositories\UserRepository;
use App\Support\Request;
use App\Support\Response;

final class PresenceController
{
    public function heartbeat(): void
    {
        $userId = Request::requireUserId();
        PresenceService::heartbeat($userId);
        Response::json(['ok' => true]);
    }

    public function status(): void
    {
        Request::requireUserId();
        PresenceService::expireStale();
        $users = UserRepository::all();
        $statuses = [];
        foreach ($users as $user) {
            $statuses[$user['id']] = $user['status'] ?? 'offline';
        }
        Response::json(['statuses' => $statuses]);
    }
}

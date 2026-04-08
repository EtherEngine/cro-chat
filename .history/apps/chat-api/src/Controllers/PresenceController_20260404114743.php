<?php

namespace App\Controllers;

use App\Services\PresenceService;
use App\Support\Request;
use App\Support\Response;

final class PresenceController
{
    public function heartbeat(): void
    {
        $userId = Request::requireUserId();
        PresenceService::heartbeat($userId);

        // Piggyback expiry on heartbeat (happens at most once per heartbeat interval)
        PresenceService::expireStale();

        Response::json(['ok' => true]);
    }

    public function status(): void
    {
        $userId = Request::requireUserId();

        // Return only id → status map for co-members (lightweight query)
        $statuses = PresenceService::statusMap($userId);
        Response::json(['statuses' => $statuses]);
    }
}

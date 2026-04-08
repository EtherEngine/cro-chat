<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Services\ModerationService;
use App\Support\Request;
use App\Support\Response;
use App\Support\Validator;

final class ModerationController
{
    // ── Delete message (moderator+) ───────────

    public function deleteMessage(array $params): void
    {
        $userId = Request::requireUserId();
        $input  = Request::json();

        ModerationService::deleteMessage(
            (int) $params['messageId'],
            $userId,
            $input['reason'] ?? null
        );
        Response::json(['ok' => true]);
    }

    // ── Mute user in channel ──────────────────

    public function muteUser(array $params): void
    {
        $userId = Request::requireUserId();
        $input  = Request::json();
        (new Validator($input))
            ->required('user_id', 'duration_minutes')
            ->integer('user_id')
            ->integer('duration_minutes')
            ->validate();

        ModerationService::muteUser(
            (int) $params['channelId'],
            (int) $input['user_id'],
            $userId,
            (int) $input['duration_minutes'],
            $input['reason'] ?? null
        );
        Response::json(['ok' => true]);
    }

    // ── Unmute user in channel ────────────────

    public function unmuteUser(array $params): void
    {
        $userId = Request::requireUserId();
        $input  = Request::json();
        (new Validator($input))->required('user_id')->integer('user_id')->validate();

        ModerationService::unmuteUser(
            (int) $params['channelId'],
            (int) $input['user_id'],
            $userId
        );
        Response::json(['ok' => true]);
    }

    // ── Kick user from channel ────────────────

    public function kickUser(array $params): void
    {
        $userId = Request::requireUserId();
        $input  = Request::json();

        ModerationService::kickFromChannel(
            (int) $params['channelId'],
            (int) $params['userId'],
            $userId,
            $input['reason'] ?? null
        );
        Response::json(['ok' => true]);
    }

    // ── Change space role (admin+) ────────────

    public function changeSpaceRole(array $params): void
    {
        $userId = Request::requireUserId();
        $input  = Request::json();
        (new Validator($input))->required('role')->validate();

        ModerationService::changeSpaceRole(
            (int) $params['spaceId'],
            (int) $params['userId'],
            $input['role'],
            $userId,
            $input['reason'] ?? null
        );
        Response::json(['ok' => true]);
    }

    // ── Change channel role (channel admin+) ──

    public function changeChannelRole(array $params): void
    {
        $userId = Request::requireUserId();
        $input  = Request::json();
        (new Validator($input))->required('role')->validate();

        ModerationService::changeChannelRole(
            (int) $params['channelId'],
            (int) $params['userId'],
            $input['role'],
            $userId,
            $input['reason'] ?? null
        );
        Response::json(['ok' => true]);
    }

    // ── Moderation audit log ──────────────────

    public function spaceLog(array $params): void
    {
        $userId = Request::requireUserId();
        $before = Request::queryInt('before');
        $limit  = min(Request::queryInt('limit', 50), 100);

        $actions = ModerationService::spaceLog(
            (int) $params['spaceId'],
            $userId,
            $limit,
            $before
        );
        Response::json(['actions' => $actions]);
    }

    public function channelLog(array $params): void
    {
        $userId = Request::requireUserId();
        $before = Request::queryInt('before');
        $limit  = min(Request::queryInt('limit', 50), 100);

        $actions = ModerationService::channelLog(
            (int) $params['channelId'],
            $userId,
            $limit,
            $before
        );
        Response::json(['actions' => $actions]);
    }
}

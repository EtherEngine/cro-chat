<?php

namespace App\Controllers;

use App\Repositories\SpaceRepository;
use App\Support\Request;
use App\Support\Response;
use App\Support\Validator;

final class SpaceController
{
    public function index(): void
    {
        $userId = Request::requireUserId();
        $spaces = SpaceRepository::forUser($userId);
        Response::json(['spaces' => $spaces]);
    }

    public function show(array $params): void
    {
        $userId = Request::requireUserId();
        $spaceId = (int) $params['spaceId'];
        if (!SpaceRepository::isMember($spaceId, $userId)) {
            Response::error('Not a member of this space', 403);
        }
        $space = SpaceRepository::find($spaceId);
        Response::json(['space' => $space]);
    }

    public function create(): void
    {
        $userId = Request::requireUserId();
        $input = Request::json();
        (new Validator($input))->required('name')->maxLength('name', 100)->validate();

        $space = SpaceRepository::create($input['name'], $userId);
        Response::json(['space' => $space], 201);
    }

    public function members(array $params): void
    {
        $userId = Request::requireUserId();
        $spaceId = (int) $params['spaceId'];
        if (!SpaceRepository::isMember($spaceId, $userId)) {
            Response::error('Not a member of this space', 403);
        }
        $members = SpaceRepository::members($spaceId);
        Response::json(['members' => $members]);
    }

    public function addMember(array $params): void
    {
        $userId = Request::requireUserId();
        $spaceId = (int) $params['spaceId'];
        if (!SpaceRepository::isAdminOrOwner($spaceId, $userId)) {
            Response::error('Only admins can add members', 403);
        }
        $input = Request::json();
        (new Validator($input))->required('user_id')->validate();

        SpaceRepository::addMember($spaceId, (int) $input['user_id'], $input['role'] ?? 'member');
        Response::json(['ok' => true]);
    }

    public function updateMember(array $params): void
    {
        $userId = Request::requireUserId();
        $spaceId = (int) $params['spaceId'];
        if (!SpaceRepository::isAdminOrOwner($spaceId, $userId)) {
            Response::error('Only admins can update roles', 403);
        }
        $input = Request::json();
        (new Validator($input))->required('role')->validate();

        $targetUserId = (int) $params['userId'];
        $targetRole = SpaceRepository::memberRole($spaceId, $targetUserId);
        if ($targetRole === 'owner') {
            Response::error('Cannot change owner role', 403);
        }

        SpaceRepository::updateMemberRole($spaceId, $targetUserId, $input['role']);
        Response::json(['ok' => true]);
    }

    public function removeMember(array $params): void
    {
        $userId = Request::requireUserId();
        $spaceId = (int) $params['spaceId'];
        if (!SpaceRepository::isAdminOrOwner($spaceId, $userId)) {
            Response::error('Only admins can remove members', 403);
        }

        $targetUserId = (int) $params['userId'];
        $targetRole = SpaceRepository::memberRole($spaceId, $targetUserId);
        if ($targetRole === 'owner') {
            Response::error('Cannot remove the space owner', 403);
        }

        SpaceRepository::removeMember($spaceId, $targetUserId);
        Response::json(['ok' => true]);
    }
}

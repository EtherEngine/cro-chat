<?php

namespace App\Services;

use App\Repositories\ConversationRepository;
use App\Repositories\SpaceRepository;
use App\Support\Response;

final class ConversationService
{
    public static function listForUser(int $userId): array
    {
        return ConversationRepository::forUser($userId);
    }

    public static function create(int $spaceId, int $userId, array $participantIds): array
    {
        // Validate space membership for all participants
        $allIds = array_unique(array_merge([$userId], $participantIds));

        foreach ($allIds as $uid) {
            if (!SpaceRepository::isMember($spaceId, $uid)) {
                Response::error("Benutzer $uid ist kein Mitglied dieses Space", 403);
            }
        }

        // Check if conversation already exists between these users
        $existing = ConversationRepository::findBetween($spaceId, $allIds);
        if ($existing) {
            return $existing;
        }

        return ConversationRepository::create($spaceId, $allIds);
    }

    public static function requireMember(int $conversationId, int $userId): void
    {
        if (!ConversationRepository::isMember($conversationId, $userId)) {
            Response::error('Kein Zugriff auf dieses Gespräch', 403);
        }
    }
}

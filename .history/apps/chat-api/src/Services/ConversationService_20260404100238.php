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

    /**
     * Get-or-create a 1:1 DM.
     * Enforces:
     *  - caller and target are different users
     *  - both must be members of the space
     *  - reuses existing 1:1 via participant_hash
     */
    public static function getOrCreateDirect(int $spaceId, int $callerId, int $targetUserId): array
    {
        if ($callerId === $targetUserId) {
            Response::error('Kann keine Konversation mit sich selbst erstellen', 422);
        }

        foreach ([$callerId, $targetUserId] as $uid) {
            if (!SpaceRepository::isMember($spaceId, $uid)) {
                Response::error("Benutzer $uid ist kein Mitglied dieses Space", 403);
            }
        }

        return ConversationRepository::getOrCreate($spaceId, [$callerId, $targetUserId], false);
    }

    /**
     * Create a group DM.
     * Enforces:
     *  - at least 3 participants (including caller)
     *  - all must be members of the space
     */
    public static function createGroup(int $spaceId, int $callerId, array $participantIds): array
    {
        $allIds = array_values(array_unique(array_merge([$callerId], $participantIds)));

        if (count($allIds) < 3) {
            Response::error('Gruppen-DM benötigt mindestens 3 Teilnehmer', 422);
        }

        foreach ($allIds as $uid) {
            if (!SpaceRepository::isMember($spaceId, (int) $uid)) {
                Response::error("Benutzer $uid ist kein Mitglied dieses Space", 403);
            }
        }

        return ConversationRepository::getOrCreate($spaceId, $allIds, true);
    }

    public static function show(int $conversationId, int $userId): array
    {
        self::requireMember($conversationId, $userId);
        $conv = ConversationRepository::find($conversationId);
        if (!$conv) {
            Response::error('Gespräch nicht gefunden', 404);
        }
        $conv['users'] = ConversationRepository::members($conversationId);
        return $conv;
    }

    public static function members(int $conversationId, int $userId): array
    {
        self::requireMember($conversationId, $userId);
        return ConversationRepository::members($conversationId);
    }

    public static function requireMember(int $conversationId, int $userId): void
    {
        if (!ConversationRepository::isMember($conversationId, $userId)) {
            Response::error('Kein Zugriff auf dieses Gespräch', 403);
        }
    }
}

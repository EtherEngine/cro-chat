<?php

namespace App\Services;

use App\Exceptions\ApiException;
use App\Repositories\ConversationRepository;
use App\Repositories\SpaceRepository;

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
            throw ApiException::validation('Kann keine Konversation mit sich selbst erstellen', 'SELF_CONVERSATION');
        }

        foreach ([$callerId, $targetUserId] as $uid) {
            if (!SpaceRepository::isMember($spaceId, $uid)) {
                throw ApiException::forbidden("Benutzer $uid ist kein Mitglied dieses Space", 'SPACE_MEMBER_REQUIRED');
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
            throw ApiException::validation('Gruppen-DM benötigt mindestens 3 Teilnehmer', 'GROUP_TOO_SMALL');
        }

        foreach ($allIds as $uid) {
            if (!SpaceRepository::isMember($spaceId, (int) $uid)) {
                throw ApiException::forbidden("Benutzer $uid ist kein Mitglied dieses Space", 'SPACE_MEMBER_REQUIRED');
            }
        }

        return ConversationRepository::getOrCreate($spaceId, $allIds, true);
    }

    public static function show(int $conversationId, int $userId): array
    {
        self::requireMember($conversationId, $userId);
        $conv = ConversationRepository::find($conversationId);
        if (!$conv) {
            throw ApiException::notFound('Gespräch nicht gefunden', 'CONVERSATION_NOT_FOUND');
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
            throw ApiException::forbidden('Kein Zugriff auf dieses Gespräch', 'CONVERSATION_ACCESS_DENIED');
        }
    }
}

<?php

namespace App\Services;

use App\Exceptions\ApiException;
use App\Repositories\ConversationRepository;
use App\Repositories\EventRepository;
use App\Repositories\SpaceRepository;
use App\Support\Database;

final class ConversationService
{
    public static function listForUser(int $userId, ?int $spaceId = null): array
    {
        return ConversationRepository::forUser($userId, $spaceId);
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
    public static function createGroup(int $spaceId, int $callerId, array $participantIds, string $title = ''): array
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

        return ConversationRepository::getOrCreate($spaceId, $allIds, true, $title, $callerId);
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

    // ── Group DM management ─────────────────────

    /**
     * Rename a group DM.  Only members of a group conversation may rename it.
     */
    public static function rename(int $conversationId, int $userId, string $title): array
    {
        $conv = self::requireGroupConversation($conversationId);
        self::requireMember($conversationId, $userId);

        Database::transaction(function () use ($conversationId, $title, $userId, $conv) {
            ConversationRepository::updateTitle($conversationId, $title);
            EventRepository::publish('conversation.updated', "conversation:$conversationId", [
                'conversation_id' => $conversationId,
                'title' => $title,
                'updated_by' => $userId,
            ]);
        });

        return self::show($conversationId, $userId);
    }

    /**
     * Update avatar of a group DM.  Only members may change it.
     */
    public static function updateAvatar(int $conversationId, int $userId, string $avatarUrl): array
    {
        $conv = self::requireGroupConversation($conversationId);
        self::requireMember($conversationId, $userId);

        Database::transaction(function () use ($conversationId, $avatarUrl, $userId) {
            ConversationRepository::updateAvatarUrl($conversationId, $avatarUrl);
            EventRepository::publish('conversation.updated', "conversation:$conversationId", [
                'conversation_id' => $conversationId,
                'avatar_url' => $avatarUrl,
                'updated_by' => $userId,
            ]);
        });

        return self::show($conversationId, $userId);
    }

    /**
     * Add a member to a group DM.
     * Only existing members may add; target must be a space member.
     */
    public static function addMember(int $conversationId, int $actingUserId, int $targetUserId): array
    {
        $conv = self::requireGroupConversation($conversationId);
        self::requireMember($conversationId, $actingUserId);

        if (!SpaceRepository::isMember((int) $conv['space_id'], $targetUserId)) {
            throw ApiException::forbidden("Benutzer $targetUserId ist kein Mitglied dieses Space", 'SPACE_MEMBER_REQUIRED');
        }

        Database::transaction(function () use ($conversationId, $targetUserId, $actingUserId) {
            $added = ConversationRepository::addMember($conversationId, $targetUserId);
            if ($added) {
                EventRepository::publish('conversation.member_added', "conversation:$conversationId", [
                    'conversation_id' => $conversationId,
                    'user_id' => $targetUserId,
                    'added_by' => $actingUserId,
                ]);
            }
        });

        return self::show($conversationId, $actingUserId);
    }

    /**
     * Remove a member from a group DM.
     * Only the creator may remove others; any member may leave (remove self).
     */
    public static function removeMember(int $conversationId, int $actingUserId, int $targetUserId): array
    {
        $conv = self::requireGroupConversation($conversationId);
        self::requireMember($conversationId, $actingUserId);

        if ($actingUserId !== $targetUserId) {
            // Only creator may remove others
            if ((int) $conv['created_by'] !== $actingUserId) {
                throw ApiException::forbidden('Nur der Ersteller darf Mitglieder entfernen', 'CREATOR_ONLY');
            }
        }

        $memberCount = ConversationRepository::memberCount($conversationId);
        if ($memberCount <= 2) {
            throw ApiException::validation('Gruppen-DM benötigt mindestens 2 verbleibende Mitglieder', 'GROUP_TOO_SMALL');
        }

        Database::transaction(function () use ($conversationId, $targetUserId, $actingUserId) {
            $removed = ConversationRepository::removeMember($conversationId, $targetUserId);
            if ($removed) {
                EventRepository::publish('conversation.member_removed', "conversation:$conversationId", [
                    'conversation_id' => $conversationId,
                    'user_id' => $targetUserId,
                    'removed_by' => $actingUserId,
                ]);
            }
        });

        // If the acting user removed themselves, they can't see the convo anymore
        if ($actingUserId === $targetUserId) {
            return ['left' => true, 'conversation_id' => $conversationId];
        }

        return self::show($conversationId, $actingUserId);
    }

    // ── Helpers ─────────────────────────────────

    private static function requireGroupConversation(int $conversationId): array
    {
        $conv = ConversationRepository::find($conversationId);
        if (!$conv) {
            throw ApiException::notFound('Gespräch nicht gefunden', 'CONVERSATION_NOT_FOUND');
        }
        if (!(int) $conv['is_group']) {
            throw ApiException::validation('Diese Aktion ist nur für Gruppen-DMs erlaubt', 'GROUP_ONLY');
        }
        return $conv;
    }
}

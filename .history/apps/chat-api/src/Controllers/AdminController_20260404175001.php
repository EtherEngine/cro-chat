<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Exceptions\ApiException;
use App\Repositories\SpaceRepository;
use App\Services\RoleService;
use App\Support\Database;
use App\Support\Request;
use App\Support\Response;

final class AdminController
{
    /**
     * GET /api/spaces/{spaceId}/admin/stats
     * Owner/Admin only — returns comprehensive workspace statistics.
     */
    public function stats(array $params): void
    {
        $userId = Request::requireUserId();
        $spaceId = (int) $params['spaceId'];

        $role = SpaceRepository::memberRole($spaceId, $userId);
        if (!$role || !RoleService::isSpaceAdminOrAbove($role)) {
            throw ApiException::forbidden('Nur Admins können Statistiken einsehen.');
        }

        $db = Database::connection();

        // ── Members ──
        $members = $db->prepare('
            SELECT
                COUNT(*) as total,
                SUM(role = "owner") as owners,
                SUM(role = "admin") as admins,
                SUM(role = "moderator") as moderators,
                SUM(role = "member") as members,
                SUM(role = "guest") as guests,
                SUM(u.status = "online") as online,
                SUM(u.status = "away") as away
            FROM space_members sm
            JOIN users u ON u.id = sm.user_id
            WHERE sm.space_id = ?
        ');
        $members->execute([$spaceId]);
        $memberStats = $members->fetch();

        // ── Channels ──
        $channels = $db->prepare('
            SELECT
                COUNT(*) as total,
                SUM(is_private = 1) as private_count,
                SUM(is_private = 0) as public_count
            FROM channels
            WHERE space_id = ?
        ');
        $channels->execute([$spaceId]);
        $channelStats = $channels->fetch();

        // ── Messages ──
        $messages = $db->prepare('
            SELECT
                COUNT(*) as total,
                SUM(deleted_at IS NOT NULL) as deleted,
                SUM(edited_at IS NOT NULL) as edited,
                SUM(reply_to_id IS NOT NULL) as replies,
                SUM(DATE(m.created_at) = CURDATE()) as today,
                SUM(m.created_at >= DATE_SUB(NOW(), INTERVAL 7 DAY)) as last_7_days,
                SUM(m.created_at >= DATE_SUB(NOW(), INTERVAL 30 DAY)) as last_30_days
            FROM messages m
            LEFT JOIN channels ch ON ch.id = m.channel_id
            LEFT JOIN conversations cv ON cv.id = m.conversation_id
            WHERE (ch.space_id = ? OR cv.space_id = ?)
        ');
        $messages->execute([$spaceId, $spaceId]);
        $messageStats = $messages->fetch();

        // ── Conversations (DMs) ──
        $convs = $db->prepare('
            SELECT COUNT(*) as total FROM conversations WHERE space_id = ?
        ');
        $convs->execute([$spaceId]);
        $convStats = $convs->fetch();

        // ── Attachments ──
        $att = $db->prepare('
            SELECT
                COUNT(*) as total,
                COALESCE(SUM(a.file_size), 0) as total_bytes
            FROM attachments a
            JOIN messages m ON m.id = a.message_id
            LEFT JOIN channels ch ON ch.id = m.channel_id
            LEFT JOIN conversations cv ON cv.id = m.conversation_id
            WHERE (ch.space_id = ? OR cv.space_id = ?)
        ');
        $att->execute([$spaceId, $spaceId]);
        $attachmentStats = $att->fetch();

        // ── Reactions ──
        $react = $db->prepare('
            SELECT COUNT(*) as total FROM message_reactions r
            JOIN messages m ON m.id = r.message_id
            LEFT JOIN channels ch ON ch.id = m.channel_id
            LEFT JOIN conversations cv ON cv.id = m.conversation_id
            WHERE (ch.space_id = ? OR cv.space_id = ?)
        ');
        $react->execute([$spaceId, $spaceId]);
        $reactionStats = $react->fetch();

        // ── Threads ──
        $threads = $db->prepare('
            SELECT COUNT(*) as total FROM threads t
            LEFT JOIN channels ch ON ch.id = t.channel_id
            LEFT JOIN conversations cv ON cv.id = t.conversation_id
            WHERE (ch.space_id = ? OR cv.space_id = ?)
        ');
        $threads->execute([$spaceId, $spaceId]);
        $threadStats = $threads->fetch();

        // ── Moderation actions ──
        $modActions = $db->prepare('
            SELECT
                COUNT(*) as total,
                SUM(action_type = "message_delete") as message_deletes,
                SUM(action_type = "user_mute") as mutes,
                SUM(action_type = "user_kick") as kicks,
                SUM(action_type = "role_change") as role_changes
            FROM moderation_actions
            WHERE space_id = ?
        ');
        $modActions->execute([$spaceId]);
        $modStats = $modActions->fetch();

        // ── Pins ──
        $pins = $db->prepare('
            SELECT COUNT(*) as total FROM pinned_messages pm
            JOIN messages m ON m.id = pm.message_id
            LEFT JOIN channels ch ON ch.id = m.channel_id
            LEFT JOIN conversations cv ON cv.id = m.conversation_id
            WHERE (ch.space_id = ? OR cv.space_id = ?)
        ');
        $pins->execute([$spaceId, $spaceId]);
        $pinStats = $pins->fetch();

        // ── Top channels by message count ──
        $topChannels = $db->prepare('
            SELECT ch.id, ch.name, ch.color, COUNT(m.id) as message_count,
                   (SELECT COUNT(*) FROM channel_members WHERE channel_id = ch.id) as member_count
            FROM channels ch
            LEFT JOIN messages m ON m.channel_id = ch.id AND m.deleted_at IS NULL
            WHERE ch.space_id = ?
            GROUP BY ch.id
            ORDER BY message_count DESC
            LIMIT 5
        ');
        $topChannels->execute([$spaceId]);
        $topChannelsList = $topChannels->fetchAll();

        // ── Most active users (last 30 days) ──
        $topUsers = $db->prepare('
            SELECT u.id, u.display_name, u.avatar_color, u.status,
                   COUNT(m.id) as message_count
            FROM users u
            JOIN space_members sm ON sm.user_id = u.id AND sm.space_id = ?
            LEFT JOIN messages m ON m.user_id = u.id
                AND m.deleted_at IS NULL
                AND m.created_at >= DATE_SUB(NOW(), INTERVAL 30 DAY)
                AND (m.channel_id IN (SELECT id FROM channels WHERE space_id = ?)
                     OR m.conversation_id IN (SELECT id FROM conversations WHERE space_id = ?))
            GROUP BY u.id
            ORDER BY message_count DESC
            LIMIT 10
        ');
        $topUsers->execute([$spaceId, $spaceId, $spaceId]);
        $topUsersList = $topUsers->fetchAll();

        // ── Messages per day (last 14 days) ──
        $daily = $db->prepare('
            SELECT DATE(m.created_at) as day, COUNT(*) as count
            FROM messages m
            LEFT JOIN channels ch ON ch.id = m.channel_id
            LEFT JOIN conversations cv ON cv.id = m.conversation_id
            WHERE (ch.space_id = ? OR cv.space_id = ?)
              AND m.created_at >= DATE_SUB(CURDATE(), INTERVAL 14 DAY)
              AND m.deleted_at IS NULL
            GROUP BY DATE(m.created_at)
            ORDER BY day ASC
        ');
        $daily->execute([$spaceId, $spaceId]);
        $dailyMessages = $daily->fetchAll();

        // ── Recent moderation actions ──
        $recentMod = $db->prepare('
            SELECT ma.id, ma.action_type, ma.reason, ma.created_at,
                   actor.display_name as actor_name, actor.avatar_color as actor_color,
                   target.display_name as target_name
            FROM moderation_actions ma
            JOIN users actor ON actor.id = ma.actor_id
            LEFT JOIN users target ON target.id = ma.target_user_id
            WHERE ma.space_id = ?
            ORDER BY ma.id DESC
            LIMIT 10
        ');
        $recentMod->execute([$spaceId]);
        $recentModList = $recentMod->fetchAll();

        // Cast numerics
        $cast = fn($row) => array_map(fn($v) => is_numeric($v) ? (int) $v : $v, $row);

        Response::json([
            'members' => $cast($memberStats),
            'channels' => $cast($channelStats),
            'messages' => $cast($messageStats),
            'conversations' => $cast($convStats),
            'attachments' => [...$cast($attachmentStats), 'total_mb' => round((int) $attachmentStats['total_bytes'] / 1048576, 2)],
            'reactions' => $cast($reactionStats),
            'threads' => $cast($threadStats),
            'moderation' => $cast($modStats),
            'pins' => $cast($pinStats),
            'topChannels' => array_map($cast, $topChannelsList),
            'topUsers' => array_map($cast, $topUsersList),
            'dailyMessages' => array_map($cast, $dailyMessages),
            'recentModeration' => $recentModList,
        ]);
    }

    /**
     * GET /api/spaces/{spaceId}/admin/members
     * Owner/Admin only — returns full member list with roles.
     */
    public function members(array $params): void
    {
        $userId = Request::requireUserId();
        $spaceId = (int) $params['spaceId'];

        $role = SpaceRepository::memberRole($spaceId, $userId);
        if (!$role || !RoleService::isSpaceAdminOrAbove($role)) {
            throw ApiException::forbidden('Nur Admins können Mitglieder verwalten.');
        }

        $db = Database::connection();
        $stmt = $db->prepare('
            SELECT u.id, u.email, u.display_name, u.title, u.avatar_color,
                   u.status, u.last_seen_at, sm.role, sm.muted_until
            FROM space_members sm
            JOIN users u ON u.id = sm.user_id
            WHERE sm.space_id = ?
            ORDER BY
                FIELD(sm.role, "owner","admin","moderator","member","guest"),
                u.display_name
        ');
        $stmt->execute([$spaceId]);
        Response::json(['members' => $stmt->fetchAll()]);
    }
}

<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Exceptions\ApiException;
use App\Repositories\ChannelRepository;
use App\Repositories\JobRepository;
use App\Repositories\ModerationRepository;
use App\Repositories\NotificationRepository;
use App\Repositories\SpaceRepository;
use App\Services\ModerationService;
use App\Services\RoleService;
use App\Support\Database;
use App\Support\Request;
use App\Support\Response;
use App\Support\Validator;

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
                COALESCE(SUM(action_type = "message_delete"), 0) as message_deletes,
                COALESCE(SUM(action_type = "user_mute"), 0) as mutes,
                COALESCE(SUM(action_type = "user_kick"), 0) as kicks,
                COALESCE(SUM(action_type = "role_change"), 0) as role_changes
            FROM moderation_actions
            WHERE space_id = ?
        ');
        $modActions->execute([$spaceId]);
        $modStats = $modActions->fetch();

        // ── Pins ──
        $pins = $db->prepare('
            SELECT COUNT(*) as total FROM pinned_messages pm
            LEFT JOIN channels ch ON ch.id = pm.channel_id
            LEFT JOIN conversations cv ON cv.id = pm.conversation_id
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

    /**
     * GET /api/spaces/{spaceId}/admin/channels
     * Returns all channels with member counts and message stats.
     */
    public function channels(array $params): void
    {
        $userId = Request::requireUserId();
        $spaceId = (int) $params['spaceId'];

        $role = SpaceRepository::memberRole($spaceId, $userId);
        if (!$role || !RoleService::isSpaceAdminOrAbove($role)) {
            throw ApiException::forbidden('Nur Admins können Kanäle verwalten.');
        }

        $db = Database::connection();
        $stmt = $db->prepare('
            SELECT ch.id, ch.name, ch.description, ch.color, ch.is_private, ch.created_at,
                   (SELECT COUNT(*) FROM channel_members WHERE channel_id = ch.id) as member_count,
                   (SELECT COUNT(*) FROM messages WHERE channel_id = ch.id AND deleted_at IS NULL) as message_count,
                   (SELECT MAX(m2.created_at) FROM messages m2 WHERE m2.channel_id = ch.id AND m2.deleted_at IS NULL) as last_activity
            FROM channels ch
            WHERE ch.space_id = ?
            ORDER BY ch.name
        ');
        $stmt->execute([$spaceId]);
        Response::json(['channels' => $stmt->fetchAll()]);
    }

    /**
     * DELETE /api/spaces/{spaceId}/admin/members/{userId}
     * Owner/Admin removes a user from the space entirely.
     */
    public function removeMember(array $params): void
    {
        $actorId = Request::requireUserId();
        $spaceId = (int) $params['spaceId'];
        $targetId = (int) $params['userId'];

        $actorRole = SpaceRepository::memberRole($spaceId, $actorId);
        if (!$actorRole || !RoleService::isSpaceAdminOrAbove($actorRole)) {
            throw ApiException::forbidden('Admin-Berechtigung erforderlich.');
        }

        if ($actorId === $targetId) {
            throw ApiException::validation('Eigene Mitgliedschaft kann nicht entfernt werden.', 'SELF_ACTION_DENIED');
        }

        $targetRole = SpaceRepository::memberRole($spaceId, $targetId);
        if (!$targetRole) {
            throw ApiException::notFound('Benutzer nicht im Space.', 'TARGET_NOT_MEMBER');
        }

        if (RoleService::spaceLevel($targetRole) >= RoleService::spaceLevel($actorRole)) {
            throw ApiException::forbidden('Kann keinen gleichrangigen oder höheren Benutzer entfernen.', 'TARGET_OUTRANKS');
        }

        $db = Database::connection();
        $db->beginTransaction();
        try {
            // Remove from all channels in this space
            $db->prepare('
                DELETE cm FROM channel_members cm
                JOIN channels ch ON ch.id = cm.channel_id
                WHERE ch.space_id = ? AND cm.user_id = ?
            ')->execute([$spaceId, $targetId]);

            // Remove from space
            $db->prepare('DELETE FROM space_members WHERE space_id = ? AND user_id = ?')
                ->execute([$spaceId, $targetId]);

            ModerationRepository::log($spaceId, 'user_kick', $actorId, null, $targetId, null, 'Aus Space entfernt');

            $db->commit();
        } catch (\Throwable $e) {
            $db->rollBack();
            throw $e;
        }

        Response::json(['ok' => true]);
    }

    /**
     * PUT /api/spaces/{spaceId}/admin/members/{userId}/mute
     * Mute a user at the space level (mutes in all channels).
     */
    public function muteMember(array $params): void
    {
        $actorId = Request::requireUserId();
        $spaceId = (int) $params['spaceId'];
        $targetId = (int) $params['userId'];
        $input = Request::json();
        (new Validator($input))->required('duration_minutes')->integer('duration_minutes')->validate();

        $actorRole = SpaceRepository::memberRole($spaceId, $actorId);
        if (!$actorRole || !RoleService::isSpaceModeratorOrAbove($actorRole)) {
            throw ApiException::forbidden('Moderator-Berechtigung erforderlich.');
        }

        if ($actorId === $targetId) {
            throw ApiException::validation('Eigene Stummschaltung nicht möglich.', 'SELF_ACTION_DENIED');
        }

        $targetRole = SpaceRepository::memberRole($spaceId, $targetId);
        if (!$targetRole) {
            throw ApiException::notFound('Benutzer nicht im Space.', 'TARGET_NOT_MEMBER');
        }

        if (RoleService::spaceLevel($targetRole) >= RoleService::spaceLevel($actorRole)) {
            throw ApiException::forbidden('Kann keinen gleichrangigen oder höheren Benutzer stummschalten.', 'TARGET_OUTRANKS');
        }

        $duration = (int) $input['duration_minutes'];
        $mutedUntil = date('Y-m-d H:i:s', time() + ($duration * 60));
        $reason = $input['reason'] ?? null;

        $db = Database::connection();
        $db->beginTransaction();
        try {
            // Mute in all channels of this space
            $db->prepare('
                UPDATE channel_members cm
                JOIN channels ch ON ch.id = cm.channel_id
                SET cm.muted_until = ?
                WHERE ch.space_id = ? AND cm.user_id = ?
            ')->execute([$mutedUntil, $spaceId, $targetId]);

            ModerationRepository::log($spaceId, 'user_mute', $actorId, null, $targetId, null, $reason, [
                'duration_minutes' => $duration,
                'muted_until' => $mutedUntil,
                'scope' => 'space',
            ]);

            $db->commit();
        } catch (\Throwable $e) {
            $db->rollBack();
            throw $e;
        }

        Response::json(['ok' => true, 'muted_until' => $mutedUntil]);
    }

    /**
     * DELETE /api/spaces/{spaceId}/admin/members/{userId}/mute
     * Unmute a user at the space level.
     */
    public function unmuteMember(array $params): void
    {
        $actorId = Request::requireUserId();
        $spaceId = (int) $params['spaceId'];
        $targetId = (int) $params['userId'];

        $actorRole = SpaceRepository::memberRole($spaceId, $actorId);
        if (!$actorRole || !RoleService::isSpaceModeratorOrAbove($actorRole)) {
            throw ApiException::forbidden('Moderator-Berechtigung erforderlich.');
        }

        $db = Database::connection();
        $db->beginTransaction();
        try {
            $db->prepare('
                UPDATE channel_members cm
                JOIN channels ch ON ch.id = cm.channel_id
                SET cm.muted_until = NULL
                WHERE ch.space_id = ? AND cm.user_id = ?
            ')->execute([$spaceId, $targetId]);

            ModerationRepository::log($spaceId, 'user_unmute', $actorId, null, $targetId);

            $db->commit();
        } catch (\Throwable $e) {
            $db->rollBack();
            throw $e;
        }

        Response::json(['ok' => true]);
    }

    // ═══════════════════════════════════════════════════════════════════
    // Job Management
    // ═══════════════════════════════════════════════════════════════════

    /**
     * GET /api/spaces/{spaceId}/admin/jobs?status=&queue=&type=&before=&limit=
     */
    public function jobs(array $params): void
    {
        $userId = Request::requireUserId();
        $spaceId = (int) $params['spaceId'];
        self::requireAdmin($spaceId, $userId);

        $status = isset($_GET['status']) ? $_GET['status'] : null;
        $queue = isset($_GET['queue']) ? $_GET['queue'] : null;
        $type = isset($_GET['type']) ? $_GET['type'] : null;
        $before = isset($_GET['before']) ? (int) $_GET['before'] : null;
        $limit = isset($_GET['limit']) ? min((int) $_GET['limit'], 100) : 50;

        $result = JobRepository::list($queue, $status, $type, $limit, $before);
        $stats = JobRepository::stats($queue);

        Response::json([
            'jobs' => $result['jobs'],
            'next_cursor' => $result['next_cursor'],
            'has_more' => $result['has_more'],
            'stats' => $stats,
        ]);
    }

    /**
     * POST /api/spaces/{spaceId}/admin/jobs/{jobId}/retry
     */
    public function retryJob(array $params): void
    {
        $userId = Request::requireUserId();
        $spaceId = (int) $params['spaceId'];
        self::requireAdmin($spaceId, $userId);

        $job = JobRepository::retry((int) $params['jobId']);
        if (!$job) {
            throw ApiException::notFound('Job nicht gefunden oder nicht fehlgeschlagen.', 'JOB_NOT_RETRYABLE');
        }

        Response::json(['job' => $job]);
    }

    /**
     * POST /api/spaces/{spaceId}/admin/jobs/purge
     */
    public function purgeJobs(array $params): void
    {
        $userId = Request::requireUserId();
        $spaceId = (int) $params['spaceId'];
        self::requireAdmin($spaceId, $userId);

        $input = Request::json();
        $hours = isset($input['older_than_hours']) ? max((int) $input['older_than_hours'], 1) : 48;
        $purged = JobRepository::purgeOlderThan($hours);

        Response::json(['purged' => $purged]);
    }

    // ═══════════════════════════════════════════════════════════════════
    // Notification Analytics
    // ═══════════════════════════════════════════════════════════════════

    /**
     * GET /api/spaces/{spaceId}/admin/notifications?limit=
     */
    public function notifications(array $params): void
    {
        $userId = Request::requireUserId();
        $spaceId = (int) $params['spaceId'];
        self::requireAdmin($spaceId, $userId);

        $db = Database::connection();

        // Stats by type
        $stmt = $db->prepare('
            SELECT type, COUNT(*) AS cnt,
                   SUM(read_at IS NULL) AS unread
            FROM notifications
            WHERE space_id = ?
            GROUP BY type
        ');
        $stmt->execute([$spaceId]);
        $byType = $stmt->fetchAll();

        // Total + unread
        $stmt = $db->prepare('
            SELECT COUNT(*) AS total, SUM(read_at IS NULL) AS unread
            FROM notifications WHERE space_id = ?
        ');
        $stmt->execute([$spaceId]);
        $totals = $stmt->fetch();

        // Per-day volume (14 days)
        $stmt = $db->prepare('
            SELECT DATE(created_at) AS day, COUNT(*) AS cnt
            FROM notifications
            WHERE space_id = ? AND created_at >= DATE_SUB(CURDATE(), INTERVAL 14 DAY)
            GROUP BY DATE(created_at)
            ORDER BY day ASC
        ');
        $stmt->execute([$spaceId]);
        $daily = $stmt->fetchAll();

        // Recent notifications
        $limit = isset($_GET['limit']) ? min((int) $_GET['limit'], 50) : 20;
        $stmt = $db->prepare('
            SELECT n.id, n.user_id, n.type, n.read_at, n.created_at,
                   a.display_name AS actor_name, a.avatar_color AS actor_color,
                   u.display_name AS user_name
            FROM notifications n
            JOIN users a ON a.id = n.actor_id
            JOIN users u ON u.id = n.user_id
            WHERE n.space_id = ?
            ORDER BY n.id DESC
            LIMIT ' . $limit . '
        ');
        $stmt->execute([$spaceId]);
        $recent = $stmt->fetchAll();

        Response::json([
            'total' => (int) $totals['total'],
            'unread' => (int) $totals['unread'],
            'by_type' => array_map(fn($r) => [
                'type' => $r['type'],
                'count' => (int) $r['cnt'],
                'unread' => (int) $r['unread'],
            ], $byType),
            'daily' => array_map(fn($r) => [
                'day' => $r['day'],
                'count' => (int) $r['cnt'],
            ], $daily),
            'recent' => $recent,
        ]);
    }

    // ═══════════════════════════════════════════════════════════════════
    // Realtime / Presence Overview
    // ═══════════════════════════════════════════════════════════════════

    /**
     * GET /api/spaces/{spaceId}/admin/realtime
     */
    public function realtime(array $params): void
    {
        $userId = Request::requireUserId();
        $spaceId = (int) $params['spaceId'];
        self::requireAdmin($spaceId, $userId);

        $db = Database::connection();

        // Status breakdown
        $stmt = $db->prepare('
            SELECT u.status, COUNT(*) AS cnt
            FROM space_members sm
            JOIN users u ON u.id = sm.user_id
            WHERE sm.space_id = ?
            GROUP BY u.status
        ');
        $stmt->execute([$spaceId]);
        $statusCounts = ['online' => 0, 'away' => 0, 'offline' => 0];
        foreach ($stmt->fetchAll() as $row) {
            $statusCounts[$row['status']] = (int) $row['cnt'];
        }

        // Online users with details
        $stmt = $db->prepare('
            SELECT u.id, u.display_name, u.avatar_color, u.status, u.last_seen_at, sm.role
            FROM space_members sm
            JOIN users u ON u.id = sm.user_id
            WHERE sm.space_id = ? AND u.status IN ("online", "away")
            ORDER BY u.status ASC, u.last_seen_at DESC
        ');
        $stmt->execute([$spaceId]);
        $onlineUsers = $stmt->fetchAll();

        // Channel activity (members who sent a message in last 1 hour)
        $stmt = $db->prepare('
            SELECT ch.id, ch.name, ch.color,
                   COUNT(DISTINCT m.user_id) AS active_users,
                   COUNT(m.id) AS msg_count
            FROM channels ch
            LEFT JOIN messages m ON m.channel_id = ch.id
                AND m.created_at >= DATE_SUB(NOW(), INTERVAL 1 HOUR)
                AND m.deleted_at IS NULL
            WHERE ch.space_id = ?
            GROUP BY ch.id
            HAVING active_users > 0 OR msg_count > 0
            ORDER BY msg_count DESC
            LIMIT 20
        ');
        $stmt->execute([$spaceId]);
        $activeChannels = $stmt->fetchAll();

        // Recent presence activity (last 50 heartbeats)
        $stmt = $db->prepare('
            SELECT u.id, u.display_name, u.avatar_color, u.status, u.last_seen_at
            FROM space_members sm
            JOIN users u ON u.id = sm.user_id
            WHERE sm.space_id = ?
              AND u.last_seen_at >= DATE_SUB(NOW(), INTERVAL 30 MINUTE)
            ORDER BY u.last_seen_at DESC
            LIMIT 50
        ');
        $stmt->execute([$spaceId]);
        $recentActivity = $stmt->fetchAll();

        Response::json([
            'status_counts' => $statusCounts,
            'online_users' => $onlineUsers,
            'active_channels' => array_map(fn($r) => [
                'id' => (int) $r['id'],
                'name' => $r['name'],
                'color' => $r['color'],
                'active_users' => (int) $r['active_users'],
                'msg_count' => (int) $r['msg_count'],
            ], $activeChannels),
            'recent_activity' => $recentActivity,
        ]);
    }

    // ═══════════════════════════════════════════════════════════════════
    // Helpers
    // ═══════════════════════════════════════════════════════════════════

    private static function requireAdmin(int $spaceId, int $userId): void
    {
        $role = SpaceRepository::memberRole($spaceId, $userId);
        if (!$role || !RoleService::isSpaceAdminOrAbove($role)) {
            throw ApiException::forbidden('Admin-Berechtigung erforderlich.');
        }
    }
}

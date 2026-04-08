<?php

declare(strict_types=1);

namespace App\Services;

use App\Repositories\ComplianceRepository;
use App\Support\Database;

/**
 * Orchestrates compliance operations: retention cleanup, data export
 * generation, account anonymization/deletion.
 */
final class ComplianceService
{
    // ═══════════════════════════════════════════════════════════════
    // Retention Enforcement
    // ═══════════════════════════════════════════════════════════════

    /**
     * Apply all enabled retention policies. Returns summary of actions.
     */
    public static function applyRetentionPolicies(): array
    {
        $policies = ComplianceRepository::enabledPolicies();
        $results = [];

        foreach ($policies as $policy) {
            $count = self::applyPolicy($policy);
            if ($count > 0) {
                $results[] = [
                    'space_id' => $policy['space_id'],
                    'target' => $policy['target'],
                    'affected' => $count,
                    'hard_delete' => $policy['hard_delete'],
                ];
            }
        }

        return $results;
    }

    private static function applyPolicy(array $policy): int
    {
        $spaceId = $policy['space_id'];
        $days = $policy['retention_days'];
        $hard = $policy['hard_delete'];

        return match ($policy['target']) {
            'messages' => self::retainMessages($spaceId, $days, $hard),
            'attachments' => self::retainAttachments($spaceId, $days),
            'notifications' => self::retainNotifications($spaceId, $days),
            'events' => self::retainEvents($days),
            'jobs' => self::retainJobs($days),
            'moderation_log' => self::retainModerationLog($spaceId, $days),
            default => 0,
        };
    }

    private static function retainMessages(int $spaceId, int $days, bool $hardDelete): int
    {
        $db = Database::connection();
        $cutoff = date('Y-m-d H:i:s', time() - $days * 86400);

        if ($hardDelete) {
            // Hard delete: remove soft-deleted messages older than retention
            $stmt = $db->prepare(
                'DELETE m FROM messages m
                 LEFT JOIN channels ch ON ch.id = m.channel_id
                 LEFT JOIN conversations cv ON cv.id = m.conversation_id
                 WHERE m.deleted_at IS NOT NULL
                   AND m.deleted_at < ?
                   AND (ch.space_id = ? OR cv.space_id = ?)'
            );
            $stmt->execute([$cutoff, $spaceId, $spaceId]);
            return $stmt->rowCount();
        }

        // Soft delete: mark old messages as deleted (body will be omitted on read)
        $stmt = $db->prepare(
            'UPDATE messages m
             LEFT JOIN channels ch ON ch.id = m.channel_id
             LEFT JOIN conversations cv ON cv.id = m.conversation_id
             SET m.deleted_at = NOW()
             WHERE m.deleted_at IS NULL
               AND m.created_at < ?
               AND (ch.space_id = ? OR cv.space_id = ?)'
        );
        $stmt->execute([$cutoff, $spaceId, $spaceId]);
        return $stmt->rowCount();
    }

    private static function retainAttachments(int $spaceId, int $days): int
    {
        $db = Database::connection();
        $cutoff = date('Y-m-d H:i:s', time() - $days * 86400);

        // Find attachment files to delete
        $stmt = $db->prepare(
            'SELECT a.id, a.storage_name FROM attachments a
             JOIN messages m ON m.id = a.message_id
             LEFT JOIN channels ch ON ch.id = m.channel_id
             LEFT JOIN conversations cv ON cv.id = m.conversation_id
             WHERE a.created_at < ?
               AND (ch.space_id = ? OR cv.space_id = ?)'
        );
        $stmt->execute([$cutoff, $spaceId, $spaceId]);
        $rows = $stmt->fetchAll();

        if (empty($rows)) {
            return 0;
        }

        // Delete physical files
        $uploadDir = dirname(__DIR__, 2) . '/storage/uploads/';
        foreach ($rows as $row) {
            $path = $uploadDir . $row['storage_name'];
            if (file_exists($path)) {
                @unlink($path);
            }
            // Delete thumbnail too
            $thumb = $uploadDir . 'thumb_' . $row['storage_name'];
            if (file_exists($thumb)) {
                @unlink($thumb);
            }
        }

        // Delete DB rows
        $ids = array_column($rows, 'id');
        $placeholders = implode(',', array_fill(0, count($ids), '?'));
        $db->prepare("DELETE FROM attachments WHERE id IN ($placeholders)")->execute($ids);

        return count($ids);
    }

    private static function retainNotifications(int $spaceId, int $days): int
    {
        $cutoff = date('Y-m-d H:i:s', time() - $days * 86400);
        $stmt = Database::connection()->prepare(
            'DELETE FROM notifications WHERE space_id = ? AND created_at < ?'
        );
        $stmt->execute([$spaceId, $cutoff]);
        return $stmt->rowCount();
    }

    private static function retainEvents(int $days): int
    {
        $stmt = Database::connection()->prepare(
            'DELETE FROM domain_events WHERE created_at < DATE_SUB(NOW(), INTERVAL ? DAY)'
        );
        $stmt->execute([$days]);
        return $stmt->rowCount();
    }

    private static function retainJobs(int $days): int
    {
        $hours = $days * 24;
        return \App\Repositories\JobRepository::purgeOlderThan($hours);
    }

    private static function retainModerationLog(int $spaceId, int $days): int
    {
        $cutoff = date('Y-m-d H:i:s', time() - $days * 86400);
        $stmt = Database::connection()->prepare(
            'DELETE FROM moderation_actions WHERE space_id = ? AND created_at < ?'
        );
        $stmt->execute([$spaceId, $cutoff]);
        return $stmt->rowCount();
    }

    // ═══════════════════════════════════════════════════════════════
    // Data Export
    // ═══════════════════════════════════════════════════════════════

    /**
     * Generate a JSON data export for a user in a space.
     * Returns the file path and size.
     */
    public static function generateExport(int $exportId): void
    {
        $export = ComplianceRepository::findExport($exportId);
        if (!$export) {
            return;
        }

        $userId = $export['user_id'];
        $spaceId = $export['space_id'];
        $db = Database::connection();

        try {
            $data = [];

            // ── Profile ──
            $stmt = $db->prepare(
                'SELECT id, email, display_name, title, avatar_color, status, last_seen_at, created_at
                 FROM users WHERE id = ?'
            );
            $stmt->execute([$userId]);
            $data['profile'] = $stmt->fetch();

            // ── Space memberships ──
            $stmt = $db->prepare(
                'SELECT sm.role, sm.joined_at, s.name AS space_name
                 FROM space_members sm JOIN spaces s ON s.id = sm.space_id
                 WHERE sm.user_id = ? AND sm.space_id = ?'
            );
            $stmt->execute([$userId, $spaceId]);
            $data['space_membership'] = $stmt->fetch();

            // ── Channel memberships ──
            $stmt = $db->prepare(
                'SELECT c.name, cm.role, cm.joined_at
                 FROM channel_members cm
                 JOIN channels c ON c.id = cm.channel_id
                 WHERE cm.user_id = ? AND c.space_id = ?'
            );
            $stmt->execute([$userId, $spaceId]);
            $data['channel_memberships'] = $stmt->fetchAll();

            // ── Messages (own, excluding deleted body) ──
            $stmt = $db->prepare(
                'SELECT m.id, CASE WHEN m.deleted_at IS NULL THEN m.body ELSE NULL END AS body,
                        m.channel_id, m.conversation_id, m.thread_id, m.edited_at, m.deleted_at, m.created_at
                 FROM messages m
                 LEFT JOIN channels ch ON ch.id = m.channel_id
                 LEFT JOIN conversations cv ON cv.id = m.conversation_id
                 WHERE m.user_id = ? AND (ch.space_id = ? OR cv.space_id = ?)
                 ORDER BY m.created_at'
            );
            $stmt->execute([$userId, $spaceId, $spaceId]);
            $data['messages'] = $stmt->fetchAll();

            // ── Reactions ──
            $stmt = $db->prepare(
                'SELECT mr.emoji, mr.created_at, mr.message_id
                 FROM message_reactions mr
                 JOIN messages m ON m.id = mr.message_id
                 LEFT JOIN channels ch ON ch.id = m.channel_id
                 LEFT JOIN conversations cv ON cv.id = m.conversation_id
                 WHERE mr.user_id = ? AND (ch.space_id = ? OR cv.space_id = ?)'
            );
            $stmt->execute([$userId, $spaceId, $spaceId]);
            $data['reactions'] = $stmt->fetchAll();

            // ── Read receipts ──
            $stmt = $db->prepare(
                'SELECT rr.channel_id, rr.conversation_id, rr.thread_id, rr.last_read_message_id, rr.read_at
                 FROM read_receipts rr
                 WHERE rr.user_id = ?'
            );
            $stmt->execute([$userId]);
            $data['read_receipts'] = $stmt->fetchAll();

            // ── Notifications ──
            $stmt = $db->prepare(
                'SELECT n.type, n.data, n.read_at, n.created_at
                 FROM notifications n
                 WHERE n.user_id = ? AND n.space_id = ?
                 ORDER BY n.created_at DESC LIMIT 500'
            );
            $stmt->execute([$userId, $spaceId]);
            $data['notifications'] = $stmt->fetchAll();

            // ── Saved messages ──
            $stmt = $db->prepare(
                'SELECT sm.message_id, sm.created_at FROM saved_messages sm WHERE sm.user_id = ?'
            );
            $stmt->execute([$userId]);
            $data['saved_messages'] = $stmt->fetchAll();

            // ── Pinned messages (by user) ──
            $stmt = $db->prepare(
                'SELECT pm.message_id, pm.channel_id, pm.conversation_id, pm.created_at
                 FROM pinned_messages pm WHERE pm.pinned_by = ?'
            );
            $stmt->execute([$userId]);
            $data['pinned_messages'] = $stmt->fetchAll();

            // Write to JSON file
            $exportDir = dirname(__DIR__, 2) . '/storage/exports';
            if (!is_dir($exportDir)) {
                mkdir($exportDir, 0750, true);
            }

            $filename = sprintf('export_%d_%d_%s.json', $userId, $spaceId, date('Ymd_His'));
            $filePath = $exportDir . '/' . $filename;

            $json = json_encode($data, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);
            file_put_contents($filePath, $json);

            ComplianceRepository::completeExport($exportId, $filePath, strlen($json));
        } catch (\Throwable $e) {
            ComplianceRepository::failExport($exportId, $e->getMessage());
        }
    }

    // ═══════════════════════════════════════════════════════════════
    // Account Anonymization / Deletion
    // ═══════════════════════════════════════════════════════════════

    /**
     * Anonymize a user's data in a space: replaces PII with placeholder.
     * Messages remain but are attributed to "Gelöschter Benutzer".
     */
    public static function anonymizeUser(int $userId, int $spaceId): array
    {
        $db = Database::connection();
        $anonName = 'Gelöschter Benutzer';
        $anonEmail = "deleted_{$userId}@anonymized.local";
        $stats = ['messages_anonymized' => 0, 'reactions_removed' => 0, 'mentions_removed' => 0];

        // ── Anonymize user profile ──
        $db->prepare(
            "UPDATE users SET display_name = ?, title = '', email = ?,
             password_hash = '', avatar_color = '#9CA3AF', status = 'offline',
             last_seen_at = NULL, anonymized_at = NOW()
             WHERE id = ? AND anonymized_at IS NULL"
        )->execute([$anonName, $anonEmail, $userId]);

        // ── Soft-delete all messages in this space ──
        $stmt = $db->prepare(
            'UPDATE messages m
             LEFT JOIN channels ch ON ch.id = m.channel_id
             LEFT JOIN conversations cv ON cv.id = m.conversation_id
             SET m.deleted_at = NOW()
             WHERE m.user_id = ? AND m.deleted_at IS NULL
               AND (ch.space_id = ? OR cv.space_id = ?)'
        );
        $stmt->execute([$userId, $spaceId, $spaceId]);
        $stats['messages_anonymized'] = $stmt->rowCount();

        // ── Remove reactions ──
        $stmt = $db->prepare(
            'DELETE mr FROM message_reactions mr
             JOIN messages m ON m.id = mr.message_id
             LEFT JOIN channels ch ON ch.id = m.channel_id
             LEFT JOIN conversations cv ON cv.id = m.conversation_id
             WHERE mr.user_id = ? AND (ch.space_id = ? OR cv.space_id = ?)'
        );
        $stmt->execute([$userId, $spaceId, $spaceId]);
        $stats['reactions_removed'] = $stmt->rowCount();

        // ── Remove mentions ──
        $stmt = $db->prepare(
            'DELETE FROM message_mentions WHERE mentioned_user_id = ?'
        );
        $stmt->execute([$userId]);
        $stats['mentions_removed'] = $stmt->rowCount();

        // ── Remove space + channel memberships ──
        $db->prepare('DELETE FROM channel_members WHERE user_id = ? AND channel_id IN (SELECT id FROM channels WHERE space_id = ?)')
           ->execute([$userId, $spaceId]);
        $db->prepare('DELETE FROM space_members WHERE user_id = ? AND space_id = ?')
           ->execute([$userId, $spaceId]);

        // ── Remove notifications ──
        $db->prepare('DELETE FROM notifications WHERE user_id = ? AND space_id = ?')
           ->execute([$userId, $spaceId]);

        // ── Remove saved messages ──
        $db->prepare('DELETE FROM saved_messages WHERE user_id = ?')
           ->execute([$userId]);

        // ── Remove read receipts ──
        $db->prepare('DELETE FROM read_receipts WHERE user_id = ?')
           ->execute([$userId]);

        // ── Remove E2EE keys ──
        $db->prepare('DELETE FROM user_keys WHERE user_id = ?')
           ->execute([$userId]);
        $db->prepare('DELETE FROM conversation_keys WHERE user_id = ?')
           ->execute([$userId]);

        return $stats;
    }

    /**
     * Hard-delete a user entirely: removes all data including messages.
     * Should be used only after data export is offered.
     */
    public static function hardDeleteUser(int $userId, int $spaceId): array
    {
        $db = Database::connection();
        $stats = ['messages_deleted' => 0, 'attachments_deleted' => 0];

        // ── Delete attachment files first ──
        $stmt = $db->prepare(
            'SELECT a.storage_name FROM attachments a
             JOIN messages m ON m.id = a.message_id
             WHERE m.user_id = ?'
        );
        $stmt->execute([$userId]);
        $uploadDir = dirname(__DIR__, 2) . '/storage/uploads/';
        foreach ($stmt->fetchAll() as $row) {
            $path = $uploadDir . $row['storage_name'];
            if (file_exists($path)) {
                @unlink($path);
            }
            $thumb = $uploadDir . 'thumb_' . $row['storage_name'];
            if (file_exists($thumb)) {
                @unlink($thumb);
            }
            $stats['attachments_deleted']++;
        }

        // ── Count messages before cascade ──
        $stmt = $db->prepare(
            'SELECT COUNT(*) FROM messages m
             LEFT JOIN channels ch ON ch.id = m.channel_id
             LEFT JOIN conversations cv ON cv.id = m.conversation_id
             WHERE m.user_id = ? AND (ch.space_id = ? OR cv.space_id = ?)'
        );
        $stmt->execute([$userId, $spaceId, $spaceId]);
        $stats['messages_deleted'] = (int) $stmt->fetchColumn();

        // ── Remove space membership (CASCADE handles channel_members, etc.) ──
        $db->prepare('DELETE FROM space_members WHERE user_id = ? AND space_id = ?')
           ->execute([$userId, $spaceId]);

        // ── Hard-delete messages in this space ──
        $db->prepare(
            'DELETE m FROM messages m
             LEFT JOIN channels ch ON ch.id = m.channel_id
             LEFT JOIN conversations cv ON cv.id = m.conversation_id
             WHERE m.user_id = ? AND (ch.space_id = ? OR cv.space_id = ?)'
        )->execute([$userId, $spaceId, $spaceId]);

        // ── Anonymize profile (keep user row to avoid FK issues) ──
        $db->prepare(
            "UPDATE users SET display_name = 'Gelöschter Benutzer', title = '',
             email = CONCAT('deleted_', id, '@anonymized.local'),
             password_hash = '', avatar_color = '#9CA3AF', status = 'offline',
             last_seen_at = NULL, anonymized_at = NOW()
             WHERE id = ?"
        )->execute([$userId]);

        return $stats;
    }
}

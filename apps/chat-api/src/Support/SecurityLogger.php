<?php

declare(strict_types=1);

namespace App\Support;

/**
 * Security audit log — writes to security_log table.
 */
final class SecurityLogger
{
    public static function log(
        ?int $userId,
        string $eventType,
        string $severity = 'info',
        array $details = []
    ): void {
        $ip = $_SERVER['REMOTE_ADDR'] ?? '0.0.0.0';
        $ua = $_SERVER['HTTP_USER_AGENT'] ?? '';

        $pdo = Database::connection();
        $stmt = $pdo->prepare(
            'INSERT INTO security_log (user_id, event_type, severity, ip_address, user_agent, details, created_at)
             VALUES (?, ?, ?, ?, ?, ?, NOW())'
        );
        $stmt->execute([
            $userId,
            $eventType,
            $severity,
            $ip,
            mb_substr($ua, 0, 500),
            json_encode($details, JSON_UNESCAPED_UNICODE),
        ]);
    }

    public static function info(?int $userId, string $event, array $details = []): void
    {
        self::log($userId, $event, 'info', $details);
    }

    public static function warning(?int $userId, string $event, array $details = []): void
    {
        self::log($userId, $event, 'warning', $details);
    }

    public static function critical(?int $userId, string $event, array $details = []): void
    {
        self::log($userId, $event, 'critical', $details);
    }

    /** Query security logs with filters. */
    public static function query(array $filters = [], int $limit = 50, int $offset = 0): array
    {
        $where = [];
        $params = [];

        if (!empty($filters['user_id'])) {
            $where[] = 'user_id = ?';
            $params[] = $filters['user_id'];
        }
        if (!empty($filters['event_type'])) {
            $where[] = 'event_type = ?';
            $params[] = $filters['event_type'];
        }
        if (!empty($filters['severity'])) {
            $where[] = 'severity = ?';
            $params[] = $filters['severity'];
        }
        if (!empty($filters['ip_address'])) {
            $where[] = 'ip_address = ?';
            $params[] = $filters['ip_address'];
        }
        if (!empty($filters['after'])) {
            $where[] = 'created_at >= ?';
            $params[] = $filters['after'];
        }
        if (!empty($filters['before'])) {
            $where[] = 'created_at <= ?';
            $params[] = $filters['before'];
        }

        $sql = 'SELECT * FROM security_log';
        if ($where) {
            $sql .= ' WHERE ' . implode(' AND ', $where);
        }
        $sql .= ' ORDER BY created_at DESC LIMIT ? OFFSET ?';
        $params[] = $limit;
        $params[] = $offset;

        $pdo = Database::connection();
        $stmt = $pdo->prepare($sql);
        $stmt->execute($params);
        return $stmt->fetchAll(\PDO::FETCH_ASSOC);
    }

    /** Count events matching filters. */
    public static function count(array $filters = []): int
    {
        $where = [];
        $params = [];

        if (!empty($filters['user_id'])) {
            $where[] = 'user_id = ?';
            $params[] = $filters['user_id'];
        }
        if (!empty($filters['event_type'])) {
            $where[] = 'event_type = ?';
            $params[] = $filters['event_type'];
        }
        if (!empty($filters['severity'])) {
            $where[] = 'severity = ?';
            $params[] = $filters['severity'];
        }

        $sql = 'SELECT COUNT(*) FROM security_log';
        if ($where) {
            $sql .= ' WHERE ' . implode(' AND ', $where);
        }

        $pdo = Database::connection();
        $stmt = $pdo->prepare($sql);
        $stmt->execute($params);
        return (int) $stmt->fetchColumn();
    }

    /** Purge old logs. */
    public static function purge(int $olderThanDays = 90): int
    {
        $pdo = Database::connection();
        $stmt = $pdo->prepare(
            'DELETE FROM security_log WHERE created_at < DATE_SUB(NOW(), INTERVAL ? DAY)'
        );
        $stmt->execute([$olderThanDays]);
        return $stmt->rowCount();
    }
}

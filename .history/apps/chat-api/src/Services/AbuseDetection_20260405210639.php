<?php

declare(strict_types=1);

namespace App\Services;

use App\Support\Database;
use App\Support\SecurityLogger;

/**
 * Score-based abuse detection with auto-blocking.
 */
final class AbuseDetection
{
    private const DEFAULT_BLOCK_THRESHOLD = 100;
    private const DEFAULT_BLOCK_DURATION  = 3600; // 1 hour
    private const DECAY_RATE_PER_HOUR     = 10;

    /** Predefined violation scores. */
    private const VIOLATION_SCORES = [
        'failed_login'       => 10,
        'invalid_token'      => 15,
        'rate_limit_hit'     => 5,
        'csrf_violation'     => 25,
        'brute_force'        => 30,
        'suspicious_request' => 20,
        'spam'               => 15,
        'forbidden_access'   => 20,
    ];

    /** Record a violation & return updated score. */
    public static function recordViolation(
        string $subjectType,
        string $subjectKey,
        string $violationType,
        ?int $customScore = null
    ): array {
        $score = $customScore ?? (self::VIOLATION_SCORES[$violationType] ?? 10);

        $pdo = Database::connection();

        // Upsert score
        $stmt = $pdo->prepare(
            'INSERT INTO abuse_scores (subject_type, subject_key, score, violations, last_violation_at, created_at, updated_at)
             VALUES (?, ?, ?, ?, NOW(), NOW(), NOW())
             ON DUPLICATE KEY UPDATE
                score = score + VALUES(score),
                violations = JSON_ARRAY_APPEND(COALESCE(violations, JSON_ARRAY()), \'$\', ?),
                last_violation_at = NOW(),
                updated_at = NOW()'
        );

        $violation = json_encode([
            'type'  => $violationType,
            'score' => $score,
            'time'  => date('Y-m-d\TH:i:s\Z'),
        ]);

        $stmt->execute([
            $subjectType,
            $subjectKey,
            $score,
            json_encode([$violation]),
            $violation,
        ]);

        // Get current state
        $current = self::getScore($subjectType, $subjectKey);

        // Auto-block if threshold exceeded
        $threshold = (int) (\App\Support\Env::get('ABUSE_BLOCK_THRESHOLD', (string) self::DEFAULT_BLOCK_THRESHOLD));
        if ($current['score'] >= $threshold && !$current['blocked_until']) {
            $duration = (int) (\App\Support\Env::get('ABUSE_BLOCK_DURATION', (string) self::DEFAULT_BLOCK_DURATION));
            self::block($subjectType, $subjectKey, $duration);
            $current['blocked_until'] = date('Y-m-d H:i:s', time() + $duration);

            SecurityLogger::critical(null, 'abuse.auto_blocked', [
                'subject_type' => $subjectType,
                'subject_key'  => $subjectKey,
                'score'        => $current['score'],
            ]);
        }

        return $current;
    }

    /** Get current abuse score. */
    public static function getScore(string $subjectType, string $subjectKey): array
    {
        $pdo = Database::connection();
        $stmt = $pdo->prepare(
            'SELECT * FROM abuse_scores WHERE subject_type = ? AND subject_key = ?'
        );
        $stmt->execute([$subjectType, $subjectKey]);
        $row = $stmt->fetch(\PDO::FETCH_ASSOC);

        if (!$row) {
            return [
                'subject_type' => $subjectType,
                'subject_key'  => $subjectKey,
                'score'        => 0,
                'blocked_until' => null,
                'violations'   => [],
            ];
        }

        $row['violations'] = json_decode($row['violations'] ?: '[]', true);
        return $row;
    }

    /** Check if a subject is currently blocked. */
    public static function isBlocked(string $subjectType, string $subjectKey): bool
    {
        $pdo = Database::connection();
        $stmt = $pdo->prepare(
            'SELECT blocked_until FROM abuse_scores
             WHERE subject_type = ? AND subject_key = ? AND blocked_until > NOW()'
        );
        $stmt->execute([$subjectType, $subjectKey]);
        return (bool) $stmt->fetchColumn();
    }

    /** Manually block a subject for given seconds. */
    public static function block(string $subjectType, string $subjectKey, int $durationSeconds): void
    {
        $pdo = Database::connection();
        $until = date('Y-m-d H:i:s', time() + $durationSeconds);

        $stmt = $pdo->prepare(
            'UPDATE abuse_scores SET blocked_until = ?, updated_at = NOW()
             WHERE subject_type = ? AND subject_key = ?'
        );
        $stmt->execute([$until, $subjectType, $subjectKey]);
    }

    /** Unblock a subject. */
    public static function unblock(string $subjectType, string $subjectKey): void
    {
        $pdo = Database::connection();
        $pdo->prepare(
            'UPDATE abuse_scores SET blocked_until = NULL, score = 0, updated_at = NOW()
             WHERE subject_type = ? AND subject_key = ?'
        )->execute([$subjectType, $subjectKey]);
    }

    /** Decay scores over time (run periodically). */
    public static function decayScores(): int
    {
        $pdo = Database::connection();
        // Reduce scores proportional to time since last violation
        $stmt = $pdo->prepare(
            'UPDATE abuse_scores
             SET score = GREATEST(0, score - FLOOR(TIMESTAMPDIFF(SECOND, last_violation_at, NOW()) / 3600) * ?),
                 updated_at = NOW()
             WHERE score > 0'
        );
        $stmt->execute([self::DECAY_RATE_PER_HOUR]);
        return $stmt->rowCount();
    }

    /** Purge entries with zero score and no block. */
    public static function cleanup(): int
    {
        $pdo = Database::connection();
        $stmt = $pdo->prepare(
            'DELETE FROM abuse_scores
             WHERE score <= 0 AND (blocked_until IS NULL OR blocked_until < NOW())'
        );
        $stmt->execute();
        return $stmt->rowCount();
    }

    /** Reset a subject's score. */
    public static function reset(string $subjectType, string $subjectKey): void
    {
        $pdo = Database::connection();
        $pdo->prepare(
            'DELETE FROM abuse_scores WHERE subject_type = ? AND subject_key = ?'
        )->execute([$subjectType, $subjectKey]);
    }
}

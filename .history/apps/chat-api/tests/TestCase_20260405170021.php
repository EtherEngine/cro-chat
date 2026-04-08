<?php

declare(strict_types=1);

namespace Tests;

use App\Exceptions\ApiException;
use App\Support\Database;
use PHPUnit\Framework\TestCase as BaseTestCase;

/**
 * Integration test base class.
 *
 * Tests the Service layer directly — no HTTP/Router/exit issues.
 *
 * Provides:
 *  - Full table truncation between tests
 *  - Seed helpers for users, spaces, channels, conversations, messages
 *  - Session simulation (actingAs)
 *  - assertApiException helper
 */
abstract class TestCase extends BaseTestCase
{
    // ── Lifecycle ─────────────────────────────────────────

    protected function setUp(): void
    {
        parent::setUp();
        $this->truncateAll();
        $_SESSION = [];
    }

    private function truncateAll(): void
    {
        $db = Database::connection();
        $db->exec('SET FOREIGN_KEY_CHECKS = 0');

        $tables = $db->query("SHOW TABLES")->fetchAll(\PDO::FETCH_COLUMN);
        foreach ($tables as $table) {
            $db->exec("DELETE FROM `$table`");
            $db->exec("ALTER TABLE `$table` AUTO_INCREMENT = 1");
        }

        $db->exec('SET FOREIGN_KEY_CHECKS = 1');
    }

    // ── Seed helpers ──────────────────────────────────────

    private int $userSeq = 0;
    private int $spaceSeq = 0;
    private int $channelSeq = 0;

    protected function createUser(array $overrides = []): array
    {
        $this->userSeq++;
        $n = $this->userSeq;

        $data = array_merge([
            'email' => "user{$n}@test.dev",
            'password_hash' => password_hash('password', PASSWORD_BCRYPT),
            'display_name' => "User {$n}",
            'title' => '',
            'avatar_color' => '#7C3AED',
        ], $overrides);

        $db = Database::connection();
        $db->prepare(
            'INSERT INTO users (email, password_hash, display_name, title, avatar_color) VALUES (?, ?, ?, ?, ?)'
        )->execute([
                    $data['email'],
                    $data['password_hash'],
                    $data['display_name'],
                    $data['title'],
                    $data['avatar_color'],
                ]);

        return ['id' => (int) $db->lastInsertId()] + $data;
    }

    protected function createSpace(int $ownerId, array $overrides = []): array
    {
        $this->spaceSeq++;
        $n = $this->spaceSeq;

        $data = array_merge([
            'name' => "Space {$n}",
            'slug' => "space-{$n}",
            'description' => '',
        ], $overrides);

        $db = Database::connection();
        $db->prepare(
            'INSERT INTO spaces (name, slug, description, owner_id) VALUES (?, ?, ?, ?)'
        )->execute([$data['name'], $data['slug'], $data['description'], $ownerId]);

        $id = (int) $db->lastInsertId();
        $db->prepare(
            'INSERT INTO space_members (space_id, user_id, role) VALUES (?, ?, ?)'
        )->execute([$id, $ownerId, 'owner']);

        return ['id' => $id, 'owner_id' => $ownerId] + $data;
    }

    protected function addSpaceMember(int $spaceId, int $userId, string $role = 'member'): void
    {
        Database::connection()->prepare(
            'INSERT INTO space_members (space_id, user_id, role) VALUES (?, ?, ?)'
        )->execute([$spaceId, $userId, $role]);
    }

    protected function createChannel(int $spaceId, int $creatorId, array $overrides = []): array
    {
        $this->channelSeq++;
        $n = $this->channelSeq;

        $data = array_merge([
            'name' => "channel-{$n}",
            'description' => '',
            'color' => '#7C3AED',
            'is_private' => 0,
        ], $overrides);

        $db = Database::connection();
        $db->prepare(
            'INSERT INTO channels (space_id, name, description, color, is_private, created_by) VALUES (?, ?, ?, ?, ?, ?)'
        )->execute([$spaceId, $data['name'], $data['description'], $data['color'], $data['is_private'], $creatorId]);

        $id = (int) $db->lastInsertId();
        $db->prepare(
            'INSERT INTO channel_members (channel_id, user_id, role) VALUES (?, ?, ?)'
        )->execute([$id, $creatorId, 'admin']);

        return ['id' => $id, 'space_id' => $spaceId] + $data;
    }

    protected function addChannelMember(int $channelId, int $userId, string $role = 'member'): void
    {
        Database::connection()->prepare(
            'INSERT INTO channel_members (channel_id, user_id, role) VALUES (?, ?, ?)'
        )->execute([$channelId, $userId, $role]);
    }

    protected function createMessage(int $userId, ?int $channelId, ?int $conversationId, string $body = 'Hello', ?int $threadId = null): array
    {
        $db = Database::connection();
        $db->prepare(
            'INSERT INTO messages (body, user_id, channel_id, conversation_id, thread_id) VALUES (?, ?, ?, ?, ?)'
        )->execute([$body, $userId, $channelId, $conversationId, $threadId]);

        return [
            'id' => (int) $db->lastInsertId(),
            'body' => $body,
            'user_id' => $userId,
            'channel_id' => $channelId,
            'conversation_id' => $conversationId,
            'thread_id' => $threadId,
        ];
    }

    protected function createThread(int $rootMessageId, ?int $channelId, ?int $conversationId, int $createdBy): array
    {
        $db = Database::connection();
        $db->prepare(
            'INSERT INTO threads (root_message_id, channel_id, conversation_id, created_by) VALUES (?, ?, ?, ?)'
        )->execute([$rootMessageId, $channelId, $conversationId, $createdBy]);

        return [
            'id' => (int) $db->lastInsertId(),
            'root_message_id' => $rootMessageId,
            'channel_id' => $channelId,
            'conversation_id' => $conversationId,
            'reply_count' => 0,
            'created_by' => $createdBy,
        ];
    }

    protected function createConversation(int $spaceId, array $userIds, bool $isGroup = false, ?int $createdBy = null, string $title = ''): array
    {
        $db = Database::connection();
        $hash = $isGroup ? null : \App\Repositories\ConversationRepository::participantHash($userIds);

        $db->prepare(
            'INSERT INTO conversations (space_id, is_group, title, created_by, participant_hash) VALUES (?, ?, ?, ?, ?)'
        )->execute([$spaceId, $isGroup ? 1 : 0, $title, $createdBy, $hash]);

        $id = (int) $db->lastInsertId();
        $stmt = $db->prepare('INSERT INTO conversation_members (conversation_id, user_id) VALUES (?, ?)');
        foreach ($userIds as $uid) {
            $stmt->execute([$id, $uid]);
        }

        return ['id' => $id, 'space_id' => $spaceId, 'is_group' => $isGroup, 'title' => $title, 'created_by' => $createdBy];
    }

    // ── Auth helper ───────────────────────────────────────

    protected function actingAs(int $userId): static
    {
        $_SESSION['user_id'] = $userId;
        return $this;
    }

    // ── Assertion helpers ─────────────────────────────────

    /**
     * Assert that a callable throws an ApiException with the given status and error code.
     */
    protected function assertApiException(int $expectedStatus, string $expectedCode, callable $callback): ApiException
    {
        try {
            $callback();
            $this->fail("Expected ApiException with code {$expectedCode} was not thrown");
        } catch (ApiException $e) {
            $this->assertSame($expectedStatus, $e->statusCode, "HTTP status mismatch");
            $this->assertSame($expectedCode, $e->errorCode, "Error code mismatch");
            return $e;
        }
    }
}

<?php

declare(strict_types=1);

namespace Tests;

use App\Exceptions\ApiException;
use App\Support\Database;
use App\Support\Router;
use PHPUnit\Framework\TestCase as BaseTestCase;

/**
 * Integration test base class.
 *
 * Provides:
 *  - Full table truncation between tests (fast: TRUNCATE + disable FK checks)
 *  - Seed helpers for users, spaces, channels, conversations, messages
 *  - HTTP-request simulation through the Router (no Apache needed)
 *  - Session simulation (loginAs / actingAs)
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
            $db->exec("TRUNCATE TABLE `$table`");
        }

        $db->exec('SET FOREIGN_KEY_CHECKS = 1');
    }

    // ── Seed helpers ──────────────────────────────────────

    protected function createUser(array $overrides = []): array
    {
        static $seq = 0;
        $seq++;

        $data = array_merge([
            'email'         => "user{$seq}@test.dev",
            'password_hash' => password_hash('password', PASSWORD_BCRYPT),
            'display_name'  => "User {$seq}",
            'title'         => '',
            'avatar_color'  => '#7C3AED',
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

        $id = (int) $db->lastInsertId();
        return ['id' => $id] + $data;
    }

    protected function createSpace(int $ownerId, array $overrides = []): array
    {
        static $seq = 0;
        $seq++;

        $data = array_merge([
            'name'        => "Space {$seq}",
            'slug'        => "space-{$seq}",
            'description' => '',
        ], $overrides);

        $db = Database::connection();
        $db->prepare(
            'INSERT INTO spaces (name, slug, description, owner_id) VALUES (?, ?, ?, ?)'
        )->execute([$data['name'], $data['slug'], $data['description'], $ownerId]);

        $id = (int) $db->lastInsertId();

        // Owner is automatically a member with 'owner' role
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
        static $seq = 0;
        $seq++;

        $data = array_merge([
            'name'        => "channel-{$seq}",
            'description' => '',
            'color'       => '#7C3AED',
            'is_private'  => 0,
        ], $overrides);

        $db = Database::connection();
        $db->prepare(
            'INSERT INTO channels (space_id, name, description, color, is_private, created_by) VALUES (?, ?, ?, ?, ?, ?)'
        )->execute([$spaceId, $data['name'], $data['description'], $data['color'], $data['is_private'], $creatorId]);

        $id = (int) $db->lastInsertId();

        // Creator joins as admin
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

    protected function createMessage(int $userId, ?int $channelId, ?int $conversationId, string $body = 'Hello'): array
    {
        $db = Database::connection();
        $db->prepare(
            'INSERT INTO messages (body, user_id, channel_id, conversation_id) VALUES (?, ?, ?, ?)'
        )->execute([$body, $userId, $channelId, $conversationId]);

        $id = (int) $db->lastInsertId();
        return [
            'id'              => $id,
            'body'            => $body,
            'user_id'         => $userId,
            'channel_id'      => $channelId,
            'conversation_id' => $conversationId,
        ];
    }

    protected function createConversation(int $spaceId, array $userIds, bool $isGroup = false): array
    {
        $db = Database::connection();
        $hash = \App\Repositories\ConversationRepository::participantHash($userIds);

        $db->prepare(
            'INSERT INTO conversations (space_id, is_group, participant_hash) VALUES (?, ?, ?)'
        )->execute([$spaceId, $isGroup ? 1 : 0, $hash]);

        $id = (int) $db->lastInsertId();

        $stmt = $db->prepare('INSERT INTO conversation_members (conversation_id, user_id) VALUES (?, ?)');
        foreach ($userIds as $uid) {
            $stmt->execute([$id, $uid]);
        }

        return ['id' => $id, 'space_id' => $spaceId, 'is_group' => $isGroup];
    }

    // ── Auth helpers ──────────────────────────────────────

    protected function actingAs(int $userId): static
    {
        $_SESSION['user_id'] = $userId;
        return $this;
    }

    // ── HTTP simulation ───────────────────────────────────

    /**
     * Dispatch a request through the Router and return the captured response.
     *
     * @return array{status: int, body: array, error: ?string, errorCode: ?string}
     */
    protected function request(string $method, string $uri, array $body = []): array
    {
        // Prepare superglobals
        $_SERVER['REQUEST_METHOD'] = $method;
        $_GET = [];
        $_POST = [];

        // Parse query string
        $parts = parse_url($uri);
        $path = $parts['path'] ?? $uri;
        if (isset($parts['query'])) {
            parse_str($parts['query'], $_GET);
        }

        // Set JSON body for non-GET requests
        if ($method !== 'GET' && $body !== []) {
            $encoded = json_encode($body, JSON_UNESCAPED_UNICODE);
            // Override php://input via stream wrapper
            StreamWrapper::$body = $encoded;
            stream_wrapper_unregister('php');
            stream_wrapper_register('php', StreamWrapper::class);
        }

        $router = new Router();
        require __DIR__ . '/../routes/api.php';

        ob_start();
        $status = 200;
        $errorCode = null;
        $errorMsg = null;

        try {
            $router->dispatch($method, $path);
        } catch (ApiException $e) {
            $status = $e->statusCode;
            $errorCode = $e->errorCode;
            $errorMsg = $e->getMessage();
        } finally {
            // Restore php:// stream
            if (isset($encoded)) {
                stream_wrapper_restore('php');
                StreamWrapper::$body = '';
            }
        }

        $output = ob_get_clean();

        // If the controller called Response::json(), it set http_response_code and echoed JSON then exit.
        // We capture that via ob + exit override.
        // But exit is not catchable — so we use a different approach:
        // we'll parse whatever was echoed.
        $decoded = $output ? json_decode($output, true) : null;

        // The real status code is from http_response_code() — read it
        $realStatus = http_response_code();
        if ($realStatus && $realStatus !== 200 && $status === 200) {
            $status = $realStatus;
        }

        if ($errorCode !== null) {
            return [
                'status'    => $status,
                'body'      => ['error' => $errorCode, 'message' => $errorMsg],
                'error'     => $errorMsg,
                'errorCode' => $errorCode,
            ];
        }

        return [
            'status'    => $status,
            'body'      => $decoded ?? [],
            'error'     => null,
            'errorCode' => null,
        ];
    }

    protected function get(string $uri): array
    {
        return $this->request('GET', $uri);
    }

    protected function post(string $uri, array $body = []): array
    {
        return $this->request('POST', $uri, $body);
    }

    protected function put(string $uri, array $body = []): array
    {
        return $this->request('PUT', $uri, $body);
    }

    protected function delete(string $uri): array
    {
        return $this->request('DELETE', $uri);
    }
}

/**
 * Stream wrapper to override php://input for testing JSON POST bodies.
 */
class StreamWrapper
{
    public static string $body = '';
    private int $position = 0;

    public function stream_open(string $path, string $mode, int $options, ?string &$opened_path): bool
    {
        // Only intercept php://input
        if ($path === 'php://input') {
            $this->position = 0;
            return true;
        }
        return false;
    }

    public function stream_read(int $count): string
    {
        $chunk = substr(self::$body, $this->position, $count);
        $this->position += strlen($chunk);
        return $chunk;
    }

    public function stream_eof(): bool
    {
        return $this->position >= strlen(self::$body);
    }

    public function stream_stat(): array
    {
        return [];
    }
}

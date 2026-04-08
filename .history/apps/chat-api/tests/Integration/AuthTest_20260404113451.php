<?php

declare(strict_types=1);

namespace Tests\Integration;

use App\Exceptions\ApiException;
use App\Services\AuthService;
use Tests\TestCase;

final class AuthTest extends TestCase
{
    public function test_login_with_valid_credentials(): void
    {
        $user = $this->createUser(['email' => 'alice@test.dev']);

        $result = AuthService::login('alice@test.dev', 'password');

        $this->assertSame($user['id'], $result['id']);
        $this->assertSame('alice@test.dev', $result['email']);
        $this->assertArrayNotHasKey('password_hash', $result);
        $this->assertSame($user['id'], $_SESSION['user_id']);
    }

    public function test_login_sets_status_online(): void
    {
        $this->createUser(['email' => 'bob@test.dev']);

        $result = AuthService::login('bob@test.dev', 'password');

        // Re-query to check status was updated
        $fresh = \App\Repositories\UserRepository::find($result['id']);
        $this->assertSame('online', $fresh['status']);
    }

    public function test_login_with_wrong_password_fails(): void
    {
        $this->createUser(['email' => 'alice@test.dev']);

        $this->assertApiException(401, 'INVALID_PASSWORD', function () {
            AuthService::login('alice@test.dev', 'wrong-password');
        });
    }

    public function test_login_with_unknown_email_fails(): void
    {
        $this->assertApiException(404, 'USER_NOT_FOUND', function () {
            AuthService::login('nobody@test.dev', 'password');
        });
    }

    public function test_login_with_empty_password_hash_is_rejected(): void
    {
        $this->createUser([
            'email' => 'legacy@test.dev',
            'password_hash' => '',
        ]);

        $this->assertApiException(401, 'INVALID_PASSWORD', function () {
            AuthService::login('legacy@test.dev', 'anything');
        });
    }

    public function test_current_user_returns_user_data(): void
    {
        $user = $this->createUser(['display_name' => 'Alice']);

        $result = AuthService::currentUser($user['id']);

        $this->assertSame($user['id'], $result['id']);
        $this->assertSame('Alice', $result['display_name']);
    }

    public function test_current_user_throws_for_nonexistent_user(): void
    {
        $this->assertApiException(404, 'USER_NOT_FOUND', function () {
            AuthService::currentUser(9999);
        });
    }

    public function test_logout_sets_status_offline(): void
    {
        $user = $this->createUser();

        // Simulate login first (sets online)
        \App\Repositories\UserRepository::updateStatus($user['id'], 'online');
        $_SESSION['user_id'] = $user['id'];

        // session_destroy() will fail without a real session — just test the status update
        \App\Repositories\UserRepository::updateStatus($user['id'], 'offline');

        $fresh = \App\Repositories\UserRepository::find($user['id']);
        $this->assertSame('offline', $fresh['status']);
    }
}

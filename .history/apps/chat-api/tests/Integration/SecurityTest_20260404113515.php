<?php

declare(strict_types=1);

namespace Tests\Integration;

use App\Exceptions\ApiException;
use App\Middleware\CsrfMiddleware;
use App\Middleware\RateLimitMiddleware;
use App\Services\AuthService;
use App\Support\Validator;
use Tests\TestCase;

final class SecurityTest extends TestCase
{
    // ── CSRF ──────────────────────────────────

    public function test_csrf_middleware_generates_token(): void
    {
        $_SESSION = [];
        $_SERVER['REQUEST_METHOD'] = 'GET';

        $csrf = new CsrfMiddleware();
        $csrf->handle();

        $this->assertNotEmpty($_SESSION['csrf_token']);
        $this->assertSame(64, strlen($_SESSION['csrf_token'])); // 32 bytes hex
    }

    public function test_csrf_middleware_allows_get_without_token(): void
    {
        $_SESSION = [];
        $_SERVER['REQUEST_METHOD'] = 'GET';

        $csrf = new CsrfMiddleware();
        $csrf->handle(); // should not throw

        $this->assertTrue(true);
    }

    public function test_csrf_middleware_blocks_post_without_token(): void
    {
        $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
        $_SERVER['REQUEST_METHOD'] = 'POST';
        $_SERVER['HTTP_X_CSRF_TOKEN'] = '';

        $this->assertApiException(403, 'CSRF_TOKEN_INVALID', function () {
            (new CsrfMiddleware())->handle();
        });
    }

    public function test_csrf_middleware_allows_post_with_valid_token(): void
    {
        $token = bin2hex(random_bytes(32));
        $_SESSION['csrf_token'] = $token;
        $_SERVER['REQUEST_METHOD'] = 'POST';
        $_SERVER['HTTP_X_CSRF_TOKEN'] = $token;

        (new CsrfMiddleware())->handle(); // should not throw

        $this->assertTrue(true);
    }

    public function test_csrf_middleware_rejects_wrong_token(): void
    {
        $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
        $_SERVER['REQUEST_METHOD'] = 'DELETE';
        $_SERVER['HTTP_X_CSRF_TOKEN'] = 'wrong-token';

        $this->assertApiException(403, 'CSRF_TOKEN_INVALID', function () {
            (new CsrfMiddleware())->handle();
        });
    }

    // ── Rate Limiting ─────────────────────────

    public function test_rate_limit_allows_under_threshold(): void
    {
        for ($i = 0; $i < 3; $i++) {
            RateLimitMiddleware::check('test_action', '127.0.0.1', maxAttempts: 5, windowSeconds: 60);
        }
        $this->assertTrue(true); // no exception
    }

    public function test_rate_limit_blocks_at_threshold(): void
    {
        $this->assertApiException(429, 'RATE_LIMIT_EXCEEDED', function () {
            for ($i = 0; $i < 6; $i++) {
                RateLimitMiddleware::check('test_action', '127.0.0.1', maxAttempts: 5, windowSeconds: 60);
            }
        });
    }

    public function test_rate_limit_clear_resets_counter(): void
    {
        for ($i = 0; $i < 4; $i++) {
            RateLimitMiddleware::check('test_action', '127.0.0.1', maxAttempts: 5, windowSeconds: 60);
        }

        RateLimitMiddleware::clear('test_action', '127.0.0.1');

        // Should succeed again
        RateLimitMiddleware::check('test_action', '127.0.0.1', maxAttempts: 5, windowSeconds: 60);
        $this->assertTrue(true);
    }

    public function test_rate_limit_scoped_per_key(): void
    {
        for ($i = 0; $i < 5; $i++) {
            RateLimitMiddleware::check('test_action', '10.0.0.1', maxAttempts: 5, windowSeconds: 60);
        }

        // Different IP should still be allowed
        RateLimitMiddleware::check('test_action', '10.0.0.2', maxAttempts: 5, windowSeconds: 60);
        $this->assertTrue(true);
    }

    // ── Password hashing ──────────────────────

    public function test_empty_password_hash_rejects_login(): void
    {
        $this->createUser([
            'email' => 'legacy@test.dev',
            'password_hash' => '',
        ]);

        $this->assertApiException(401, 'INVALID_PASSWORD', function () {
            AuthService::login('legacy@test.dev', 'anything');
        });
    }

    public function test_password_verify_with_bcrypt(): void
    {
        $hash = password_hash('secret123', PASSWORD_BCRYPT, ['cost' => 12]);
        $this->createUser([
            'email' => 'secure@test.dev',
            'password_hash' => $hash,
        ]);

        $result = AuthService::login('secure@test.dev', 'secret123');
        $this->assertSame('secure@test.dev', $result['email']);
    }

    // ── Validator string type check ───────────

    public function test_validator_string_rejects_array(): void
    {
        $v = new Validator(['name' => ['injected', 'array']]);
        $v->string('name');

        $this->assertTrue($v->fails());
        $this->assertArrayHasKey('name', $v->errors());
    }

    public function test_validator_string_accepts_string(): void
    {
        $v = new Validator(['name' => 'Alice']);
        $v->string('name');

        $this->assertFalse($v->fails());
    }

    public function test_validator_string_accepts_null(): void
    {
        $v = new Validator([]);
        $v->string('name');

        $this->assertFalse($v->fails());
    }
}

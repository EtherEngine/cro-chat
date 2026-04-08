<?php

declare(strict_types=1);

namespace Tests\Integration;

use App\Services\ScalingService;
use App\Support\Cache;
use App\Support\ObjectStorage;
use App\Support\RedisQueue;
use Tests\TestCase;

final class ScalingTest extends TestCase
{
    private string $uploadDir;

    protected function setUp(): void
    {
        parent::setUp();
        Cache::reset();
        Cache::init(['prefix' => 'test:', 'host' => '0.0.0.0', 'port' => 1]);
        $this->uploadDir = sys_get_temp_dir() . '/cro_test_uploads_' . getmypid();
        ObjectStorage::init([
            'driver' => 'local',
            'local' => ['path' => $this->uploadDir],
        ]);
    }

    protected function tearDown(): void
    {
        Cache::flush();
        Cache::reset();
        ObjectStorage::reset();
        $this->cleanDir($this->uploadDir);
        parent::tearDown();
    }

    private function cleanDir(string $dir): void
    {
        if (!is_dir($dir)) {
            return;
        }
        $items = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($dir, \FilesystemIterator::SKIP_DOTS),
            \RecursiveIteratorIterator::CHILD_FIRST
        );
        foreach ($items as $item) {
            $item->isDir() ? @rmdir($item->getPathname()) : @unlink($item->getPathname());
        }
        @rmdir($dir);
    }

    // ── Cache: Basic Operations ──────────────────

    public function test_cache_set_and_get(): void
    {
        Cache::set('hello', 'world', 60);
        $this->assertSame('world', Cache::get('hello'));
    }

    public function test_cache_get_returns_null_on_miss(): void
    {
        $this->assertNull(Cache::get('nonexistent'));
    }

    public function test_cache_delete(): void
    {
        Cache::set('key', 'value', 60);
        Cache::delete('key');
        $this->assertNull(Cache::get('key'));
    }

    public function test_cache_remember_computes_on_miss(): void
    {
        $calls = 0;
        $fn = function () use (&$calls) {
            $calls++;
            return ['data' => 42];
        };

        $result = Cache::remember('rkey', 60, $fn);
        $this->assertSame(['data' => 42], $result);
        $this->assertSame(1, $calls);
    }

    public function test_cache_remember_returns_cached(): void
    {
        $calls = 0;
        $fn = function () use (&$calls) {
            $calls++;
            return 'computed';
        };

        Cache::remember('rkey2', 60, $fn);
        $result = Cache::remember('rkey2', 60, $fn);
        $this->assertSame('computed', $result);
        $this->assertSame(1, $calls);
    }

    public function test_cache_increment(): void
    {
        $val = Cache::increment('counter', 1, 60);
        $this->assertSame(1, $val);
        $val = Cache::increment('counter', 5, 60);
        $this->assertSame(6, $val);
    }

    public function test_cache_lock_and_unlock(): void
    {
        $this->assertTrue(Cache::lock('mylock', 10));
        $this->assertFalse(Cache::lock('mylock', 10));
        Cache::unlock('mylock');
        $this->assertTrue(Cache::lock('mylock', 10));
    }

    public function test_cache_get_many(): void
    {
        Cache::set('a', 1, 60);
        Cache::set('b', 2, 60);
        $result = Cache::getMany(['a', 'b', 'c']);
        $this->assertSame(1, $result['a']);
        $this->assertSame(2, $result['b']);
        $this->assertNull($result['c']);
    }

    public function test_cache_set_many(): void
    {
        Cache::setMany(['x' => 10, 'y' => 20], 60);
        $this->assertSame(10, Cache::get('x'));
        $this->assertSame(20, Cache::get('y'));
    }

    public function test_cache_invalidate_tag(): void
    {
        Cache::set('user:1:profile', 'data1', 60);
        Cache::set('user:1:settings', 'data2', 60);
        Cache::set('user:2:profile', 'data3', 60);

        $count = Cache::invalidateTag('user:1');
        $this->assertSame(2, $count);
        $this->assertNull(Cache::get('user:1:profile'));
        $this->assertNull(Cache::get('user:1:settings'));
        $this->assertSame('data3', Cache::get('user:2:profile'));
    }

    public function test_cache_stats(): void
    {
        Cache::get('miss1');
        Cache::set('hit1', 'val', 60);
        Cache::get('hit1');

        $stats = Cache::stats();
        $this->assertSame('memory', $stats['driver']);
        $this->assertFalse($stats['connected']);
        $this->assertSame(1, $stats['hits']);
        $this->assertSame(1, $stats['misses']);
        $this->assertSame(50.0, $stats['hit_ratio']);
    }

    public function test_cache_flush(): void
    {
        Cache::set('a', 1, 60);
        Cache::set('b', 2, 60);
        Cache::flush();
        $this->assertNull(Cache::get('a'));
        $this->assertNull(Cache::get('b'));
    }

    // ── Object Storage: Local Driver ─────────────

    public function test_storage_put_and_get(): void
    {
        ObjectStorage::putContent('Hello World', 'hello.txt', 'text/plain');
        $content = ObjectStorage::get('hello.txt');
        $this->assertSame('Hello World', $content);
    }

    public function test_storage_exists(): void
    {
        $this->assertFalse(ObjectStorage::exists('nope.txt'));
        ObjectStorage::putContent('data', 'nope.txt', 'text/plain');
        $this->assertTrue(ObjectStorage::exists('nope.txt'));
    }

    public function test_storage_delete(): void
    {
        ObjectStorage::putContent('data', 'del.txt', 'text/plain');
        $this->assertTrue(ObjectStorage::exists('del.txt'));
        ObjectStorage::delete('del.txt');
        $this->assertFalse(ObjectStorage::exists('del.txt'));
    }

    public function test_storage_url(): void
    {
        ObjectStorage::putContent('content', 'myfile.txt', 'text/plain');
        $url = ObjectStorage::url('myfile.txt');
        $this->assertStringContainsString('myfile.txt', $url);
    }

    public function test_storage_get_returns_null_for_missing(): void
    {
        // Ensure base dir exists first so realpath works
        ObjectStorage::putContent('tmp', 'tmp.txt', 'text/plain');
        $this->assertNull(ObjectStorage::get('nonexistent.txt'));
    }

    public function test_storage_driver_info(): void
    {
        $info = ObjectStorage::info();
        $this->assertSame('local', $info['driver']);
        $this->assertArrayHasKey('config', $info);
        $this->assertArrayHasKey('path', $info['config']);
    }

    // ── Redis Queue: Fallback (no Redis) ─────────

    public function test_redis_queue_not_connected(): void
    {
        $this->assertFalse(RedisQueue::isConnected());
    }

    public function test_redis_queue_stats_returns_null(): void
    {
        $stats = RedisQueue::stats('default');
        $this->assertNull($stats);
    }

    // ── Scaling Service ──────────────────────────

    public function test_readiness_check(): void
    {
        $result = ScalingService::readinessCheck();
        $this->assertSame('ready', $result['status']);
        $this->assertSame('ok', $result['checks']['database']['status']);
        $this->assertArrayHasKey('cache', $result['checks']);
        $this->assertArrayHasKey('queue', $result['checks']);
        $this->assertArrayHasKey('storage', $result['checks']);
    }

    public function test_scaling_info(): void
    {
        $info = ScalingService::scalingInfo();
        $this->assertArrayHasKey('instance', $info);
        $this->assertArrayHasKey('cache', $info);
        $this->assertArrayHasKey('queue', $info);
        $this->assertArrayHasKey('storage', $info);
        $this->assertArrayHasKey('features', $info);
        $this->assertArrayHasKey('hostname', $info['instance']);
        $this->assertArrayHasKey('php_version', $info['instance']);
    }

    public function test_cache_user_pattern(): void
    {
        $user = $this->createUser();
        ScalingService::cacheUser($user);
        $cached = ScalingService::getCachedUser($user['id']);
        $this->assertSame($user['id'], $cached['id']);

        ScalingService::invalidateUser($user['id']);
        $this->assertNull(ScalingService::getCachedUser($user['id']));
    }

    public function test_cache_presence_pattern(): void
    {
        $user = $this->createUser();
        $space = $this->createSpace($user['id']);

        $presence = ['online' => [$user['id']], 'idle' => []];
        ScalingService::cachePresence((int) $space['id'], $presence);
        $cached = ScalingService::getCachedPresence((int) $space['id']);
        $this->assertSame($presence, $cached);
    }

    public function test_rate_limit_allows_within_window(): void
    {
        $key = 'test:rate:' . uniqid();
        for ($i = 0; $i < 5; $i++) {
            $result = ScalingService::rateLimit($key, 10, 60);
            $this->assertTrue($result['allowed']);
        }
    }

    public function test_rate_limit_blocks_when_exceeded(): void
    {
        $key = 'test:rate:block:' . uniqid();
        for ($i = 0; $i < 3; $i++) {
            ScalingService::rateLimit($key, 3, 60);
        }
        $result = ScalingService::rateLimit($key, 3, 60);
        $this->assertFalse($result['allowed']);
    }

    // ── Scaling Controller (admin check) ────────

    public function test_scaling_admin_check_rejects_non_admin(): void
    {
        $user = $this->createUser();
        $this->actingAs($user['id']);

        $this->assertApiException(
            fn() => ScalingController::info(),
            403,
            'SCALING_ADMIN_REQUIRED'
        );
    }

    public function test_scaling_health_returns_status(): void
    {
        $result = ScalingService::readinessCheck();
        $this->assertContains($result['status'], ['ready', 'degraded']);
        $this->assertArrayHasKey('checks', $result);
        $this->assertArrayHasKey('instance', $result);
    }

    public function test_scaling_cache_stats_structure(): void
    {
        $stats = Cache::stats();
        $this->assertArrayHasKey('driver', $stats);
        $this->assertArrayHasKey('connected', $stats);
        $this->assertArrayHasKey('hits', $stats);
        $this->assertArrayHasKey('misses', $stats);
        $this->assertArrayHasKey('hit_ratio', $stats);
    }

    public function test_scaling_queue_stats_without_redis(): void
    {
        $this->assertFalse(RedisQueue::isConnected());
    }

    public function test_scaling_storage_info_structure(): void
    {
        $info = ObjectStorage::info();
        $this->assertSame('local', $info['driver']);
        $this->assertArrayHasKey('config', $info);
    }
}

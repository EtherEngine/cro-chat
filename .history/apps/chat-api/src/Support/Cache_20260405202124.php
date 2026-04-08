<?php

declare(strict_types=1);

namespace App\Support;

/**
 * Cache layer with Redis primary and in-memory fallback.
 *
 * Pattern: Cache-aside with TTL-based expiration.
 * Supports: get/set/delete/remember/tags/flush.
 * Privacy: Never caches user-identifiable data without explicit opt-in.
 */
final class Cache
{
    private static ?\Redis $redis = null;
    private static bool $connected = false;
    private static string $prefix = 'cro:';

    /** In-memory fallback when Redis is unavailable */
    private static array $memory = [];
    private static array $memoryExpiry = [];

    /** Stats for debugging */
    private static int $hits = 0;
    private static int $misses = 0;

    // ── Connection ───────────────────────────────

    public static function init(?array $config = null): void
    {
        if (self::$connected) {
            return;
        }

        $config = $config ?? [
            'host' => Env::get('REDIS_HOST', '127.0.0.1'),
            'port' => Env::int('REDIS_PORT', 6379),
            'password' => Env::get('REDIS_PASSWORD', ''),
            'database' => Env::int('REDIS_DATABASE', 0),
            'prefix' => Env::get('REDIS_PREFIX', 'cro:'),
            'timeout' => 2.0,
            'read_timeout' => 2.0,
        ];

        self::$prefix = $config['prefix'] ?? 'cro:';

        if (!class_exists(\Redis::class)) {
            Logger::info('cache.fallback', ['reason' => 'phpredis extension not installed']);
            return;
        }

        try {
            self::$redis = new \Redis();
            self::$redis->connect(
                $config['host'],
                $config['port'],
                $config['timeout'] ?? 2.0,
                null,
                0,
                $config['read_timeout'] ?? 2.0
            );

            if (!empty($config['password'])) {
                self::$redis->auth($config['password']);
            }

            if (($config['database'] ?? 0) !== 0) {
                self::$redis->select($config['database']);
            }

            self::$redis->setOption(\Redis::OPT_SERIALIZER, \Redis::SERIALIZER_JSON);
            self::$connected = true;

            Logger::info('cache.connected', ['host' => $config['host'], 'port' => $config['port']]);
        } catch (\Throwable $e) {
            self::$redis = null;
            Logger::warning('cache.connect_failed', ['error' => $e->getMessage()]);
        }
    }

    public static function isConnected(): bool
    {
        return self::$connected && self::$redis !== null;
    }

    // ── Core Operations ──────────────────────────

    /**
     * Get a cached value. Returns null on miss.
     */
    public static function get(string $key): mixed
    {
        $prefixed = self::$prefix . $key;

        if (self::$connected && self::$redis) {
            try {
                $value = self::$redis->get($prefixed);
                if ($value === false) {
                    self::$misses++;
                    return null;
                }
                self::$hits++;
                return $value;
            } catch (\Throwable $e) {
                Logger::warning('cache.get_error', ['key' => $key, 'error' => $e->getMessage()]);
            }
        }

        // In-memory fallback
        if (isset(self::$memoryExpiry[$prefixed]) && self::$memoryExpiry[$prefixed] < time()) {
            unset(self::$memory[$prefixed], self::$memoryExpiry[$prefixed]);
            self::$misses++;
            return null;
        }

        if (array_key_exists($prefixed, self::$memory)) {
            self::$hits++;
            return self::$memory[$prefixed];
        }

        self::$misses++;
        return null;
    }

    /**
     * Store a value with TTL (seconds). 0 = no expiry.
     */
    public static function set(string $key, mixed $value, int $ttl = 300): bool
    {
        $prefixed = self::$prefix . $key;

        if (self::$connected && self::$redis) {
            try {
                if ($ttl > 0) {
                    return self::$redis->setex($prefixed, $ttl, $value);
                }
                return self::$redis->set($prefixed, $value);
            } catch (\Throwable $e) {
                Logger::warning('cache.set_error', ['key' => $key, 'error' => $e->getMessage()]);
            }
        }

        // In-memory fallback
        self::$memory[$prefixed] = $value;
        self::$memoryExpiry[$prefixed] = $ttl > 0 ? time() + $ttl : PHP_INT_MAX;
        return true;
    }

    /**
     * Delete a cached key.
     */
    public static function delete(string $key): bool
    {
        $prefixed = self::$prefix . $key;

        if (self::$connected && self::$redis) {
            try {
                return self::$redis->del($prefixed) > 0;
            } catch (\Throwable $e) {
                Logger::warning('cache.del_error', ['key' => $key, 'error' => $e->getMessage()]);
            }
        }

        unset(self::$memory[$prefixed], self::$memoryExpiry[$prefixed]);
        return true;
    }

    /**
     * Cache-aside: get or compute + cache.
     *
     * @param string   $key
     * @param int      $ttl      Seconds
     * @param callable $compute  fn() => value
     */
    public static function remember(string $key, int $ttl, callable $compute): mixed
    {
        $cached = self::get($key);
        if ($cached !== null) {
            return $cached;
        }

        $value = $compute();
        if ($value !== null) {
            self::set($key, $value, $ttl);
        }
        return $value;
    }

    /**
     * Delete all keys matching a tag pattern.
     * Tags are simulated via prefixed sets.
     */
    public static function invalidateTag(string $tag): int
    {
        $pattern = self::$prefix . $tag . ':*';

        if (self::$connected && self::$redis) {
            try {
                $count = 0;
                $iterator = null;
                while (($keys = self::$redis->scan($iterator, $pattern, 100)) !== false) {
                    if (!empty($keys)) {
                        $count += self::$redis->del(...$keys);
                    }
                }
                return $count;
            } catch (\Throwable $e) {
                Logger::warning('cache.invalidate_error', ['tag' => $tag, 'error' => $e->getMessage()]);
            }
        }

        // In-memory fallback
        $count = 0;
        foreach (array_keys(self::$memory) as $k) {
            if (str_starts_with($k, $pattern = self::$prefix . $tag . ':')) {
                unset(self::$memory[$k], self::$memoryExpiry[$k]);
                $count++;
            }
        }
        return $count;
    }

    /**
     * Increment a counter atomically.
     */
    public static function increment(string $key, int $by = 1, int $ttl = 0): int
    {
        $prefixed = self::$prefix . $key;

        if (self::$connected && self::$redis) {
            try {
                $val = self::$redis->incrBy($prefixed, $by);
                if ($ttl > 0) {
                    self::$redis->expire($prefixed, $ttl);
                }
                return $val;
            } catch (\Throwable $e) {
                Logger::warning('cache.incr_error', ['key' => $key, 'error' => $e->getMessage()]);
            }
        }

        // In-memory fallback
        $current = self::$memory[$prefixed] ?? 0;
        self::$memory[$prefixed] = $current + $by;
        if ($ttl > 0) {
            self::$memoryExpiry[$prefixed] = time() + $ttl;
        }
        return self::$memory[$prefixed];
    }

    /**
     * Acquire a distributed lock (Redis SETNX).
     */
    public static function lock(string $key, int $ttlSeconds = 30): bool
    {
        $prefixed = self::$prefix . 'lock:' . $key;

        if (self::$connected && self::$redis) {
            try {
                return self::$redis->set($prefixed, time(), ['NX', 'EX' => $ttlSeconds]);
            } catch (\Throwable $e) {
                Logger::warning('cache.lock_error', ['key' => $key, 'error' => $e->getMessage()]);
            }
        }

        // In-memory (non-distributed) fallback
        if (isset(self::$memory[$prefixed])) {
            if (self::$memoryExpiry[$prefixed] > time()) {
                return false;
            }
        }
        self::$memory[$prefixed] = time();
        self::$memoryExpiry[$prefixed] = time() + $ttlSeconds;
        return true;
    }

    /**
     * Release a distributed lock.
     */
    public static function unlock(string $key): bool
    {
        return self::delete('lock:' . $key);
    }

    // ── Bulk Operations ──────────────────────────

    /**
     * Get multiple keys at once (Redis MGET).
     */
    public static function getMany(array $keys): array
    {
        if (empty($keys)) {
            return [];
        }

        $prefixed = array_map(fn(string $k) => self::$prefix . $k, $keys);

        if (self::$connected && self::$redis) {
            try {
                $values = self::$redis->mGet($prefixed);
                $result = [];
                foreach ($keys as $i => $key) {
                    $result[$key] = $values[$i] === false ? null : $values[$i];
                }
                return $result;
            } catch (\Throwable $e) {
                Logger::warning('cache.mget_error', ['error' => $e->getMessage()]);
            }
        }

        // In-memory fallback
        $result = [];
        foreach ($keys as $key) {
            $result[$key] = self::get($key);
        }
        return $result;
    }

    /**
     * Set multiple keys at once.
     */
    public static function setMany(array $data, int $ttl = 300): bool
    {
        foreach ($data as $key => $value) {
            self::set($key, $value, $ttl);
        }
        return true;
    }

    // ── Session Storage (Redis) ──────────────────

    /**
     * Register Redis as PHP session handler (for horizontal scaling).
     */
    public static function registerSessionHandler(): void
    {
        if (!self::$connected || !self::$redis) {
            return; // Fallback to default file sessions
        }

        $host = Env::get('REDIS_HOST', '127.0.0.1');
        $port = Env::int('REDIS_PORT', 6379);
        $password = Env::get('REDIS_PASSWORD', '');
        $database = Env::int('REDIS_SESSION_DATABASE', 1);

        $path = "tcp://{$host}:{$port}";
        if ($password !== '') {
            $path .= "?auth={$password}";
        }
        $path .= "&database={$database}";

        ini_set('session.save_handler', 'redis');
        ini_set('session.save_path', $path);
    }

    // ── Stats ────────────────────────────────────

    public static function stats(): array
    {
        return [
            'connected' => self::$connected,
            'driver' => self::$connected ? 'redis' : 'memory',
            'hits' => self::$hits,
            'misses' => self::$misses,
            'hit_ratio' => (self::$hits + self::$misses) > 0
                ? round(self::$hits / (self::$hits + self::$misses) * 100, 1)
                : 0,
            'memory_keys' => count(self::$memory),
        ];
    }

    /** @internal For testing only */
    public static function flush(): void
    {
        if (self::$connected && self::$redis) {
            try {
                $iterator = null;
                while (($keys = self::$redis->scan($iterator, self::$prefix . '*', 100)) !== false) {
                    if (!empty($keys)) {
                        self::$redis->del(...$keys);
                    }
                }
            } catch (\Throwable) {
                // ignore
            }
        }

        self::$memory = [];
        self::$memoryExpiry = [];
        self::$hits = 0;
        self::$misses = 0;
    }

    /** @internal Reset for testing */
    public static function reset(): void
    {
        self::$redis = null;
        self::$connected = false;
        self::$memory = [];
        self::$memoryExpiry = [];
        self::$hits = 0;
        self::$misses = 0;
    }
}

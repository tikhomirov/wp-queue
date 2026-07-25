<?php

declare(strict_types=1);

namespace WPQueue\Queue\Redis;

if (! defined('ABSPATH')) {
    exit;
}

/**
 * phpredis extension adapter.
 */
class PhpRedisClient implements RedisClientInterface
{
    protected ?\Redis $redis = null;

    protected bool $connected = false;

    /**
     * @param  array{
     *     host: string,
     *     port: int,
     *     password: string|array|null,
     *     database: int,
     *     prefix: string,
     *     scheme: string,
     *     path: string|null,
     *     timeout: float,
     *     read_timeout: float
     * }  $config
     */
    public function __construct(
        protected array $config,
    ) {}

    public function connect(): void
    {
        if ($this->connected && $this->redis !== null) {
            return;
        }

        if (! extension_loaded('redis')) {
            throw new \RuntimeException('Redis extension (phpredis) is not installed.');
        }

        $this->redis = new \Redis();

        try {
            if ($this->config['scheme'] === 'unix' && $this->config['path']) {
                $this->connected = $this->redis->connect(
                    $this->config['path'],
                    0,
                    $this->config['timeout'],
                    null,
                    0,
                    $this->config['read_timeout'],
                );
            } else {
                $host = $this->config['host'];

                // TLS support
                if ($this->config['scheme'] === 'tls' || $this->config['scheme'] === 'rediss') {
                    $host = 'tls://'.$host;
                }

                $this->connected = $this->redis->connect(
                    $host,
                    $this->config['port'],
                    $this->config['timeout'],
                    null,
                    0,
                    $this->config['read_timeout'],
                );
            }

            if (! $this->connected) {
                throw new \RuntimeException('Failed to connect to Redis server.');
            }

            // Authenticate
            if ($this->config['password']) {
                $password = $this->config['password'];

                if (is_array($password) && count($password) === 2) {
                    $this->redis->auth($password);
                } elseif (is_string($password)) {
                    $this->redis->auth($password);
                }
            }

            // Select database
            if ($this->config['database'] > 0) {
                $this->redis->select($this->config['database']);
            }

            // Set prefix
            $this->redis->setOption(\Redis::OPT_PREFIX, $this->config['prefix']);
        } catch (\RedisException $e) {
            $this->connected = false;
            throw new \RuntimeException(esc_html('Redis connection failed: '.$e->getMessage()), 0, $e);
        }
    }

    public function isConnected(): bool
    {
        return $this->connected && $this->redis !== null;
    }

    public function ping(): bool
    {
        $this->ensureConnected();

        try {
            return $this->redis->ping() !== false;
        } catch (\Throwable) {
            return false;
        }
    }

    public function close(): void
    {
        if ($this->redis !== null && $this->connected) {
            $this->redis->close();
            $this->connected = false;
        }
    }

    public function rPush(string $key, string $value): int
    {
        $this->ensureConnected();

        return (int) $this->redis->rPush($key, $value);
    }

    public function lPop(string $key): ?string
    {
        $this->ensureConnected();
        $result = $this->redis->lPop($key);

        return $result === false ? null : (string) $result;
    }

    public function lLen(string $key): int
    {
        $this->ensureConnected();

        return (int) $this->redis->lLen($key);
    }

    public function lRange(string $key, int $start, int $stop): array
    {
        $this->ensureConnected();

        return $this->redis->lRange($key, $start, $stop) ?: [];
    }

    public function lRem(string $key, string $value, int $count): int
    {
        $this->ensureConnected();

        return (int) $this->redis->lRem($key, $value, $count);
    }

    public function zAdd(string $key, float $score, string $member): int
    {
        $this->ensureConnected();

        return (int) $this->redis->zAdd($key, $score, $member);
    }

    public function zRem(string $key, string $member): int
    {
        $this->ensureConnected();

        return (int) $this->redis->zRem($key, $member);
    }

    public function zRangeByScore(string $key, string $min, string $max): array
    {
        $this->ensureConnected();

        return $this->redis->zRangeByScore($key, $min, $max) ?: [];
    }

    public function zRange(string $key, int $start, int $stop): array
    {
        $this->ensureConnected();

        return $this->redis->zRange($key, $start, $stop) ?: [];
    }

    public function zCard(string $key): int
    {
        $this->ensureConnected();

        return (int) $this->redis->zCard($key);
    }

    public function hSet(string $key, string $field, string $value): int
    {
        $this->ensureConnected();

        return (int) $this->redis->hSet($key, $field, $value);
    }

    public function hGet(string $key, string $field): ?string
    {
        $this->ensureConnected();
        $result = $this->redis->hGet($key, $field);

        return $result === false ? null : (string) $result;
    }

    public function hDel(string $key, string $field): int
    {
        $this->ensureConnected();

        return (int) $this->redis->hDel($key, $field);
    }

    public function hLen(string $key): int
    {
        $this->ensureConnected();

        return (int) $this->redis->hLen($key);
    }

    public function del(array $keys): int
    {
        $this->ensureConnected();

        if (empty($keys)) {
            return 0;
        }

        return (int) $this->redis->del($keys);
    }

    public function keys(string $pattern): array
    {
        $this->ensureConnected();

        return $this->redis->keys($pattern) ?: [];
    }

    public function getClientType(): string
    {
        return 'phpredis';
    }

    protected function ensureConnected(): void
    {
        if (! $this->isConnected()) {
            $this->connect();
        }
    }
}

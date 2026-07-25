<?php

declare(strict_types=1);

namespace WPQueue\Queue\Redis;

if (! defined('ABSPATH')) {
    exit;
}

/**
 * Predis library adapter.
 *
 * Works with Predis library bundled with redis-cache plugin
 * or installed via Composer.
 */
class PredisClient implements RedisClientInterface
{
    /** @var \Predis\Client|null */
    protected $redis = null;

    protected bool $connected = false;

    protected string $prefix = '';

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
    ) {
        $this->prefix = $config['prefix'];
    }

    public function connect(): void
    {
        if ($this->connected && $this->redis !== null) {
            return;
        }

        // Load Predis from redis-cache plugin if available
        if (! class_exists('\Predis\Client')) {
            if (defined('WP_REDIS_PLUGIN_PATH') && file_exists(WP_REDIS_PLUGIN_PATH.'/dependencies/predis/predis/autoload.php')) {
                require_once WP_REDIS_PLUGIN_PATH.'/dependencies/predis/predis/autoload.php';
            }
        }

        if (! class_exists('\Predis\Client')) {
            throw new \RuntimeException('Predis library is not available.');
        }

        try {
            $parameters = [
                'scheme' => $this->config['scheme'],
                'host' => $this->config['host'],
                'port' => $this->config['port'],
                'database' => $this->config['database'],
                'timeout' => $this->config['timeout'],
                'read_write_timeout' => $this->config['read_timeout'],
            ];

            // Unix socket
            if ($this->config['scheme'] === 'unix' && $this->config['path']) {
                $parameters['path'] = $this->config['path'];
                unset($parameters['host'], $parameters['port']);
            }

            // Password
            if ($this->config['password']) {
                $password = $this->config['password'];

                if (is_array($password) && count($password) === 2) {
                    $parameters['username'] = $password[0];
                    $parameters['password'] = $password[1];
                } elseif (is_string($password) && $password !== '') {
                    $parameters['password'] = $password;
                }
            }

            $this->redis = new \Predis\Client($parameters);
            $this->redis->connect();
            $this->connected = true;
        } catch (\Throwable $e) {
            $this->connected = false;
            throw new \RuntimeException(esc_html('Predis connection failed: '.$e->getMessage()), 0, $e);
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
            $response = $this->redis->ping();

            return $response->getPayload() === 'PONG';
        } catch (\Throwable) {
            return false;
        }
    }

    public function close(): void
    {
        if ($this->redis !== null && $this->connected) {
            $this->redis->disconnect();
            $this->connected = false;
        }
    }

    public function rPush(string $key, string $value): int
    {
        $this->ensureConnected();

        return (int) $this->redis->rpush($this->prefixKey($key), [$value]);
    }

    public function lPop(string $key): ?string
    {
        $this->ensureConnected();
        $result = $this->redis->lpop($this->prefixKey($key));

        return $result === null ? null : (string) $result;
    }

    public function lLen(string $key): int
    {
        $this->ensureConnected();

        return (int) $this->redis->llen($this->prefixKey($key));
    }

    public function lRange(string $key, int $start, int $stop): array
    {
        $this->ensureConnected();

        return $this->redis->lrange($this->prefixKey($key), $start, $stop) ?: [];
    }

    public function lRem(string $key, string $value, int $count): int
    {
        $this->ensureConnected();

        return (int) $this->redis->lrem($this->prefixKey($key), $count, $value);
    }

    public function zAdd(string $key, float $score, string $member): int
    {
        $this->ensureConnected();

        return (int) $this->redis->zadd($this->prefixKey($key), [$member => $score]);
    }

    public function zRem(string $key, string $member): int
    {
        $this->ensureConnected();

        return (int) $this->redis->zrem($this->prefixKey($key), $member);
    }

    public function zRangeByScore(string $key, string $min, string $max): array
    {
        $this->ensureConnected();

        return $this->redis->zrangebyscore($this->prefixKey($key), $min, $max) ?: [];
    }

    public function zRange(string $key, int $start, int $stop): array
    {
        $this->ensureConnected();

        return $this->redis->zrange($this->prefixKey($key), $start, $stop) ?: [];
    }

    public function zCard(string $key): int
    {
        $this->ensureConnected();

        return (int) $this->redis->zcard($this->prefixKey($key));
    }

    public function hSet(string $key, string $field, string $value): int
    {
        $this->ensureConnected();

        return (int) $this->redis->hset($this->prefixKey($key), $field, $value);
    }

    public function hGet(string $key, string $field): ?string
    {
        $this->ensureConnected();
        $result = $this->redis->hget($this->prefixKey($key), $field);

        return $result === null ? null : (string) $result;
    }

    public function hDel(string $key, string $field): int
    {
        $this->ensureConnected();

        return (int) $this->redis->hdel($this->prefixKey($key), [$field]);
    }

    public function hLen(string $key): int
    {
        $this->ensureConnected();

        return (int) $this->redis->hlen($this->prefixKey($key));
    }

    public function del(array $keys): int
    {
        $this->ensureConnected();

        if (empty($keys)) {
            return 0;
        }

        $prefixedKeys = array_map(fn ($key) => $this->prefixKey($key), $keys);

        return (int) $this->redis->del($prefixedKeys);
    }

    public function keys(string $pattern): array
    {
        $this->ensureConnected();
        $keys = $this->redis->keys($this->prefixKey($pattern)) ?: [];

        // Remove prefix from returned keys
        $prefixLen = strlen($this->prefix);

        return array_map(fn ($key) => substr($key, $prefixLen), $keys);
    }

    public function getClientType(): string
    {
        return 'predis';
    }

    protected function prefixKey(string $key): string
    {
        return $this->prefix.$key;
    }

    protected function ensureConnected(): void
    {
        if (! $this->isConnected()) {
            $this->connect();
        }
    }
}

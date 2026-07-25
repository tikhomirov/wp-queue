<?php

declare(strict_types=1);

namespace WPQueue\Queue;

use WPQueue\Contracts\JobInterface;
use WPQueue\Contracts\QueueInterface;
use WPQueue\Queue\Redis\RedisClientFactory;
use WPQueue\Queue\Redis\RedisClientInterface;

if (! defined('ABSPATH')) {
    exit;
}

/**
 * Redis-based queue implementation.
 *
 * Compatible with redis-cache plugin constants:
 * - WP_REDIS_HOST (default: 127.0.0.1)
 * - WP_REDIS_PORT (default: 6379)
 * - WP_REDIS_PASSWORD (optional)
 * - WP_REDIS_DATABASE (default: 0)
 * - WP_REDIS_PREFIX (default: wp_queue_)
 * - WP_REDIS_SCHEME (tcp/unix/tls)
 * - WP_REDIS_PATH (for unix socket)
 * - WP_REDIS_TIMEOUT (default: 1)
 * - WP_REDIS_READ_TIMEOUT (default: 1)
 * - WP_REDIS_CLIENT (phpredis/predis)
 *
 * Supports both phpredis extension and Predis library (via redis-cache plugin).
 *
 * @see https://github.com/rhubarbgroup/redis-cache
 */
class RedisQueue implements QueueInterface
{
    protected const PREFIX = 'wp_queue:';

    protected ?RedisClientInterface $client = null;

    /**
     * @var array{
     *     host: string,
     *     port: int,
     *     password: string|array|null,
     *     database: int,
     *     prefix: string,
     *     scheme: string,
     *     path: string|null,
     *     timeout: float,
     *     read_timeout: float
     * }
     */
    protected array $config;

    /**
     * @param  array<string, mixed>  $config  Optional config override
     */
    public function __construct(array $config = [])
    {
        $this->config = $this->resolveConfig($config);
        $this->client = RedisClientFactory::create($this->config);
    }

    /**
     * Resolve configuration from WP constants and overrides.
     *
     * @param  array<string, mixed>  $config
     * @return array{host: string, port: int, password: string|array|null, database: int, prefix: string, scheme: string, path: string|null, timeout: float, read_timeout: float}
     */
    protected function resolveConfig(array $config): array
    {
        return [
            'host' => $config['host'] ?? (defined('WP_REDIS_HOST') ? WP_REDIS_HOST : '127.0.0.1'),
            'port' => (int) ($config['port'] ?? (defined('WP_REDIS_PORT') ? WP_REDIS_PORT : 6379)),
            'password' => $config['password'] ?? (defined('WP_REDIS_PASSWORD') ? WP_REDIS_PASSWORD : null),
            'database' => (int) ($config['database'] ?? (defined('WP_REDIS_DATABASE') ? WP_REDIS_DATABASE : 0)),
            'prefix' => $config['prefix'] ?? $this->resolvePrefix(),
            'scheme' => $config['scheme'] ?? (defined('WP_REDIS_SCHEME') ? WP_REDIS_SCHEME : 'tcp'),
            'path' => $config['path'] ?? (defined('WP_REDIS_PATH') ? WP_REDIS_PATH : null),
            'timeout' => (float) ($config['timeout'] ?? (defined('WP_REDIS_TIMEOUT') ? WP_REDIS_TIMEOUT : 1)),
            'read_timeout' => (float) ($config['read_timeout'] ?? (defined('WP_REDIS_READ_TIMEOUT') ? WP_REDIS_READ_TIMEOUT : 1)),
        ];
    }

    /**
     * Resolve prefix from WP constants.
     */
    protected function resolvePrefix(): string
    {
        if (defined('WP_REDIS_PREFIX')) {
            return WP_REDIS_PREFIX.self::PREFIX;
        }

        if (defined('WP_CACHE_KEY_SALT')) {
            return WP_CACHE_KEY_SALT.self::PREFIX;
        }

        return self::PREFIX;
    }

    /**
     * Get Redis client.
     *
     * @throws \RuntimeException If no Redis client available or connection fails
     */
    protected function connection(): RedisClientInterface
    {
        if ($this->client === null) {
            $this->client = RedisClientFactory::create($this->config);
        }

        if (! $this->client->isConnected()) {
            $this->client->connect();
        }

        return $this->client;
    }

    /**
     * Check if Redis is available.
     */
    public static function isAvailable(): bool
    {
        // Check if any Redis client is available
        if (! RedisClientFactory::isAvailable()) {
            return false;
        }

        // Check if Redis is disabled
        if (defined('WP_REDIS_DISABLED') && WP_REDIS_DISABLED) {
            return false;
        }

        // Try to connect
        try {
            $queue = new self();

            return $queue->connection()->ping();
        } catch (\Throwable) {
            return false;
        }
    }

    /**
     * Get Redis connection info for debugging.
     *
     * @return array<string, mixed>
     */
    public function getConnectionInfo(): array
    {
        return [
            'host' => $this->config['host'],
            'port' => $this->config['port'],
            'database' => $this->config['database'],
            'prefix' => $this->config['prefix'],
            'scheme' => $this->config['scheme'],
            'connected' => $this->client?->isConnected() ?? false,
            'client_type' => $this->client?->getClientType() ?? 'none',
            'phpredis' => extension_loaded('redis') ? phpversion('redis') : 'not installed',
            'predis' => RedisClientFactory::isPredisAvailable() ? 'available' : 'not installed',
        ];
    }

    public function push(JobInterface $job): string
    {
        $queue = $job->getQueue();
        $availableAt = time() + $job->getDelay();

        $data = [
            'id' => $job->getId(),
            'payload' => serialize($job),
            'available_at' => $availableAt,
            'reserved_at' => null,
            'attempts' => $job->getAttempts(),
            'created_at' => time(),
        ];

        $jsonData = json_encode($data);

        if ($job->getDelay() > 0) {
            // Use sorted set for delayed jobs
            $this->connection()->zAdd($this->delayedKey($queue), (float) $availableAt, $jsonData);
        } else {
            // Use list for immediate jobs
            $this->connection()->rPush($this->queueKey($queue), $jsonData);
        }

        return $job->getId();
    }

    public function later(int $delay, JobInterface $job): string
    {
        $job->delay($delay);

        return $this->push($job);
    }

    public function pop(string $queue = 'default'): ?JobInterface
    {
        // First, migrate delayed jobs that are ready
        $this->migrateDelayedJobs($queue);

        // Pop from queue
        $data = $this->connection()->lPop($this->queueKey($queue));

        if ($data === null) {
            return null;
        }

        $payload = json_decode($data, true);

        if (! is_array($payload) || ! isset($payload['payload'])) {
            return null;
        }

        // Mark as reserved
        $payload['reserved_at'] = time();
        $this->connection()->hSet(
            $this->reservedKey($queue),
            $payload['id'],
            json_encode($payload),
        );

        $job = unserialize($payload['payload']);

        return $job instanceof JobInterface ? $job : null;
    }

    /**
     * Migrate delayed jobs that are ready to be processed.
     */
    protected function migrateDelayedJobs(string $queue): void
    {
        $now = time();
        $delayedKey = $this->delayedKey($queue);
        $queueKey = $this->queueKey($queue);

        // Get all delayed jobs that are ready
        $jobs = $this->connection()->zRangeByScore($delayedKey, '-inf', (string) $now);

        foreach ($jobs as $job) {
            // Remove from delayed set
            $this->connection()->zRem($delayedKey, $job);

            // Add to main queue
            $this->connection()->rPush($queueKey, $job);
        }
    }

    public function delete(string $jobId): bool
    {
        $queues = $this->getAllQueues();

        foreach ($queues as $queue) {
            // Check reserved jobs
            if ($this->connection()->hDel($this->reservedKey($queue), $jobId) > 0) {
                return true;
            }

            // Check main queue (slower, but necessary)
            $jobs = $this->connection()->lRange($this->queueKey($queue), 0, -1);
            foreach ($jobs as $index => $data) {
                $payload = json_decode($data, true);
                if (isset($payload['id']) && $payload['id'] === $jobId) {
                    $this->connection()->lRem($this->queueKey($queue), $data, 1);

                    return true;
                }
            }

            // Check delayed jobs
            $delayed = $this->connection()->zRange($this->delayedKey($queue), 0, -1);
            foreach ($delayed as $data) {
                $payload = json_decode($data, true);
                if (isset($payload['id']) && $payload['id'] === $jobId) {
                    $this->connection()->zRem($this->delayedKey($queue), $data);

                    return true;
                }
            }
        }

        return false;
    }

    public function release(JobInterface $job, int $delay = 0): void
    {
        $queue = $job->getQueue();

        // Remove from reserved
        $this->connection()->hDel($this->reservedKey($queue), $job->getId());

        // Re-add to queue
        $job->delay($delay);
        $this->push($job);
    }

    public function size(string $queue = 'default'): int
    {
        $mainSize = (int) $this->connection()->lLen($this->queueKey($queue));
        $delayedSize = (int) $this->connection()->zCard($this->delayedKey($queue));
        $reservedSize = (int) $this->connection()->hLen($this->reservedKey($queue));

        return $mainSize + $delayedSize + $reservedSize;
    }

    public function clear(string $queue = 'default'): int
    {
        $count = $this->size($queue);

        $this->connection()->del([
            $this->queueKey($queue),
            $this->delayedKey($queue),
            $this->reservedKey($queue),
        ]);

        return $count;
    }

    public function isEmpty(string $queue = 'default'): bool
    {
        return $this->size($queue) === 0;
    }

    /**
     * Get all queue names.
     *
     * @return string[]
     */
    protected function getAllQueues(): array
    {
        $prefix = $this->config['prefix'].'jobs:';
        $keys = $this->connection()->keys('jobs:*');

        $queues = [];
        foreach ($keys as $key) {
            // Remove prefix and suffix
            $name = str_replace(['jobs:', ':delayed', ':reserved'], '', $key);
            if ($name && ! in_array($name, $queues, true)) {
                $queues[] = $name;
            }
        }

        return $queues ?: ['default'];
    }

    protected function queueKey(string $queue): string
    {
        return 'jobs:'.$queue;
    }

    protected function delayedKey(string $queue): string
    {
        return 'jobs:'.$queue.':delayed';
    }

    protected function reservedKey(string $queue): string
    {
        return 'jobs:'.$queue.':reserved';
    }

    /**
     * Get queue statistics.
     *
     * @return array{pending: int, delayed: int, reserved: int, total: int}
     */
    public function stats(string $queue = 'default'): array
    {
        return [
            'pending' => (int) $this->connection()->lLen($this->queueKey($queue)),
            'delayed' => (int) $this->connection()->zCard($this->delayedKey($queue)),
            'reserved' => (int) $this->connection()->hLen($this->reservedKey($queue)),
            'total' => $this->size($queue),
        ];
    }

    /**
     * Close Redis connection.
     */
    public function disconnect(): void
    {
        $this->client?->close();
    }

    public function __destruct()
    {
        $this->disconnect();
    }
}

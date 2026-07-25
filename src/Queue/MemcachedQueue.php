<?php

declare(strict_types=1);

namespace WPQueue\Queue;

use WPQueue\Contracts\JobInterface;
use WPQueue\Contracts\QueueInterface;

if (! defined('ABSPATH')) {
    exit;
}

/**
 * Memcached-based queue implementation.
 *
 * Configuration constants:
 * - WP_MEMCACHED_HOST (default: 127.0.0.1)
 * - WP_MEMCACHED_PORT (default: 11211)
 * - WP_MEMCACHED_PREFIX (default: wp_queue_)
 *
 * Or use WordPress object cache if Memcached is configured there.
 */
class MemcachedQueue implements QueueInterface
{
    protected const PREFIX = 'wp_queue:';

    protected const QUEUE_LIST_KEY = 'wp_queue:queues';

    protected ?\Memcached $memcached = null;

    protected bool $connected = false;

    /**
     * @var array{host: string, port: int, prefix: string}
     */
    protected array $config;

    /**
     * @param  array<string, mixed>  $config  Optional config override
     */
    public function __construct(array $config = [])
    {
        $this->config = $this->resolveConfig($config);
    }

    /**
     * Resolve configuration.
     *
     * @param  array<string, mixed>  $config
     * @return array{host: string, port: int, prefix: string}
     */
    protected function resolveConfig(array $config): array
    {
        return [
            'host' => $config['host'] ?? (defined('WP_MEMCACHED_HOST') ? WP_MEMCACHED_HOST : '127.0.0.1'),
            'port' => (int) ($config['port'] ?? (defined('WP_MEMCACHED_PORT') ? WP_MEMCACHED_PORT : 11211)),
            'prefix' => $config['prefix'] ?? (defined('WP_MEMCACHED_PREFIX') ? WP_MEMCACHED_PREFIX.self::PREFIX : self::PREFIX),
        ];
    }

    /**
     * Get Memcached connection.
     *
     * @throws \RuntimeException If Memcached extension not available or connection fails
     */
    protected function connection(): \Memcached
    {
        if ($this->memcached !== null && $this->connected) {
            return $this->memcached;
        }

        if (! extension_loaded('memcached')) {
            throw new \RuntimeException('Memcached extension is not installed.');
        }

        $this->memcached = new \Memcached('wp_queue');

        // Only add server if not already added
        if (count($this->memcached->getServerList()) === 0) {
            $this->memcached->addServer($this->config['host'], $this->config['port']);
        }

        // Set options
        $this->memcached->setOption(\Memcached::OPT_PREFIX_KEY, $this->config['prefix']);
        $this->memcached->setOption(\Memcached::OPT_SERIALIZER, \Memcached::SERIALIZER_PHP);

        // Check connection
        $stats = $this->memcached->getStats();
        $serverKey = $this->config['host'].':'.$this->config['port'];

        if (empty($stats) || ! isset($stats[$serverKey])) {
            throw new \RuntimeException('Failed to connect to Memcached server.');
        }

        $this->connected = true;

        return $this->memcached;
    }

    /**
     * Check if Memcached is available.
     */
    public static function isAvailable(): bool
    {
        if (! extension_loaded('memcached')) {
            return false;
        }

        try {
            $queue = new self();
            $queue->connection();

            return true;
        } catch (\Throwable) {
            return false;
        }
    }

    /**
     * Get connection info for debugging.
     *
     * @return array<string, mixed>
     */
    public function getConnectionInfo(): array
    {
        return [
            'host' => $this->config['host'],
            'port' => $this->config['port'],
            'prefix' => $this->config['prefix'],
            'connected' => $this->connected,
            'extension' => extension_loaded('memcached') ? phpversion('memcached') : 'not installed',
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

        // Get current queue
        $jobs = $this->getQueueData($queue);
        $jobs[$job->getId()] = $data;

        // Save queue
        $this->saveQueueData($queue, $jobs);

        // Track queue name
        $this->trackQueue($queue);

        return $job->getId();
    }

    public function later(int $delay, JobInterface $job): string
    {
        $job->delay($delay);

        return $this->push($job);
    }

    public function pop(string $queue = 'default'): ?JobInterface
    {
        $jobs = $this->getQueueData($queue);

        if (empty($jobs)) {
            return null;
        }

        $now = time();
        $staleThreshold = 300;

        foreach ($jobs as $id => $data) {
            $isStale = $data['reserved_at'] !== null && ($now - $data['reserved_at']) > $staleThreshold;

            if ($data['available_at'] <= $now && ($data['reserved_at'] === null || $isStale)) {
                // Mark as reserved
                $jobs[$id]['reserved_at'] = $now;
                $this->saveQueueData($queue, $jobs);

                $job = unserialize($data['payload']);
                if ($job instanceof JobInterface) {
                    return $job;
                }
            }
        }

        return null;
    }

    public function delete(string $jobId): bool
    {
        foreach ($this->getAllQueues() as $queue) {
            $jobs = $this->getQueueData($queue);

            if (isset($jobs[$jobId])) {
                unset($jobs[$jobId]);
                $this->saveQueueData($queue, $jobs);

                return true;
            }
        }

        return false;
    }

    public function release(JobInterface $job, int $delay = 0): void
    {
        $queue = $job->getQueue();
        $jobs = $this->getQueueData($queue);

        if (isset($jobs[$job->getId()])) {
            $jobs[$job->getId()]['reserved_at'] = null;
            $jobs[$job->getId()]['available_at'] = time() + $delay;
            $jobs[$job->getId()]['payload'] = serialize($job);
            $jobs[$job->getId()]['attempts'] = $job->getAttempts();
            $this->saveQueueData($queue, $jobs);
        }
    }

    public function size(string $queue = 'default'): int
    {
        return count($this->getQueueData($queue));
    }

    public function clear(string $queue = 'default'): int
    {
        $count = $this->size($queue);
        $this->connection()->delete($this->queueKey($queue));

        return $count;
    }

    public function isEmpty(string $queue = 'default'): bool
    {
        return $this->size($queue) === 0;
    }

    /**
     * Get queue data.
     *
     * @return array<string, array{id: string, payload: string, available_at: int, reserved_at: int|null, attempts: int, created_at: int}>
     */
    protected function getQueueData(string $queue): array
    {
        $data = $this->connection()->get($this->queueKey($queue));

        return is_array($data) ? $data : [];
    }

    /**
     * Save queue data.
     *
     * @param  array<string, array{id: string, payload: string, available_at: int, reserved_at: int|null, attempts: int, created_at: int}>  $jobs
     */
    protected function saveQueueData(string $queue, array $jobs): void
    {
        if (empty($jobs)) {
            $this->connection()->delete($this->queueKey($queue));
        } else {
            // TTL: 7 days
            $this->connection()->set($this->queueKey($queue), $jobs, 604800);
        }
    }

    /**
     * Track queue name.
     */
    protected function trackQueue(string $queue): void
    {
        $queues = $this->connection()->get(self::QUEUE_LIST_KEY);
        $queues = is_array($queues) ? $queues : [];

        if (! in_array($queue, $queues, true)) {
            $queues[] = $queue;
            $this->connection()->set(self::QUEUE_LIST_KEY, $queues, 0);
        }
    }

    /**
     * Get all queue names.
     *
     * @return string[]
     */
    protected function getAllQueues(): array
    {
        $queues = $this->connection()->get(self::QUEUE_LIST_KEY);

        return is_array($queues) && ! empty($queues) ? $queues : ['default'];
    }

    protected function queueKey(string $queue): string
    {
        return 'jobs:'.$queue;
    }

    /**
     * Get queue statistics.
     *
     * @return array{pending: int, reserved: int, total: int}
     */
    public function stats(string $queue = 'default'): array
    {
        $jobs = $this->getQueueData($queue);
        $pending = 0;
        $reserved = 0;

        foreach ($jobs as $job) {
            if ($job['reserved_at'] !== null) {
                $reserved++;
            } else {
                $pending++;
            }
        }

        return [
            'pending' => $pending,
            'reserved' => $reserved,
            'total' => count($jobs),
        ];
    }

    /**
     * Close connection.
     */
    public function disconnect(): void
    {
        if ($this->memcached !== null) {
            $this->memcached->quit();
            $this->connected = false;
        }
    }
}

<?php

declare(strict_types=1);

namespace WPQueue;

use InvalidArgumentException;
use WPQueue\Contracts\QueueInterface;
use WPQueue\Queue\DatabaseQueue;
use WPQueue\Queue\MemcachedQueue;
use WPQueue\Queue\RedisQueue;
use WPQueue\Queue\SyncQueue;

if (! defined('ABSPATH')) {
    exit;
}

/**
 * Queue Manager - manages queue connections and drivers.
 *
 * Supported drivers:
 * - database: Uses wp_options (default)
 * - sync: Synchronous execution (no queue)
 * - redis: Redis server (requires phpredis extension)
 * - memcached: Memcached server (requires memcached extension)
 * - auto: Automatically selects best available driver
 *
 * Configuration via wp-config.php constants:
 *
 * Redis (compatible with redis-cache plugin):
 * - WP_REDIS_HOST, WP_REDIS_PORT, WP_REDIS_PASSWORD, WP_REDIS_DATABASE
 * - WP_REDIS_PREFIX, WP_REDIS_SCHEME, WP_REDIS_PATH
 *
 * Memcached:
 * - WP_MEMCACHED_HOST, WP_MEMCACHED_PORT, WP_MEMCACHED_PREFIX
 *
 * Queue driver selection:
 * - WP_QUEUE_DRIVER: 'database', 'sync', 'redis', 'memcached', 'auto'
 */
class QueueManager
{
    /**
     * Driver status constants.
     */
    public const STATUS_READY = 'ready';

    public const STATUS_NO_EXTENSION = 'no_extension';

    public const STATUS_NO_SERVER = 'no_server';

    public const STATUS_UNAVAILABLE = 'unavailable';

    /**
     * @var array<string, QueueInterface>
     */
    protected array $connections = [];

    /**
     * @var array<string, callable>
     */
    protected array $customCreators = [];

    protected ?string $defaultDriver = null;

    /**
     * Cached driver status info.
     *
     * @var array<string, array{status: string, extension: bool, server: bool, message: string}>|null
     */
    protected ?array $driverStatusCache = null;

    public function connection(?string $name = null): QueueInterface
    {
        $name ??= $this->getDefaultDriver();

        return $this->connections[$name] ??= $this->resolve($name);
    }

    /**
     * Get the default driver name.
     *
     * Priority:
     * 1. Explicitly set driver via setDefaultDriver()
     * 2. WP_QUEUE_DRIVER constant (with availability check)
     * 3. 'database' as fallback
     *
     * IMPORTANT: If configured driver is not available, falls back to 'database'
     * to prevent fatal errors.
     */
    public function getDefaultDriver(): string
    {
        if ($this->defaultDriver !== null) {
            // Even explicitly set driver must be available
            if ($this->isDriverReady($this->defaultDriver)) {
                return $this->defaultDriver;
            }

            return 'database';
        }

        // Check WP_QUEUE_DRIVER constant
        if (defined('WP_QUEUE_DRIVER')) {
            $driver = WP_QUEUE_DRIVER;

            if ($driver === 'auto') {
                return $this->detectBestDriver();
            }

            // Validate that configured driver is actually ready
            if ($this->isDriverReady($driver)) {
                return $driver;
            }

            return 'database';
        }

        return 'database';
    }

    /**
     * Get the configured driver name (without fallback).
     *
     * Use this to show what user configured, even if it's not available.
     */
    public function getConfiguredDriver(): string
    {
        if ($this->defaultDriver !== null) {
            return $this->defaultDriver;
        }

        if (defined('WP_QUEUE_DRIVER')) {
            return WP_QUEUE_DRIVER;
        }

        return 'database';
    }

    public function setDefaultDriver(string $driver): void
    {
        $this->defaultDriver = $driver;
    }

    /**
     * Detect the best available driver.
     *
     * Priority: redis > memcached > database
     */
    public function detectBestDriver(): string
    {
        if ($this->isDriverReady('redis')) {
            return 'redis';
        }

        if ($this->isDriverReady('memcached')) {
            return 'memcached';
        }

        return 'database';
    }

    /**
     * Check if a driver is fully ready to use.
     *
     * This checks both extension availability AND server connectivity.
     */
    public function isDriverReady(string $driver): bool
    {
        $status = $this->getDriverStatus($driver);

        return $status['status'] === self::STATUS_READY;
    }

    /**
     * Get detailed driver status.
     *
     * @return array{status: string, extension: bool, server: bool, message: string}
     */
    public function getDriverStatus(string $driver): array
    {
        // Return cached status if available
        if (isset($this->driverStatusCache[$driver])) {
            return $this->driverStatusCache[$driver];
        }

        $status = match ($driver) {
            'database', 'sync' => [
                'status' => self::STATUS_READY,
                'extension' => true,
                'server' => true,
                'message' => $driver === 'database'
                    ? __('Uses wp_options table', 'wp-queue')
                    : __('Synchronous execution (no queue)', 'wp-queue'),
            ],
            'redis' => $this->getRedisStatus(),
            'memcached' => $this->getMemcachedStatus(),
            default => isset($this->customCreators[$driver])
                ? [
                    'status' => self::STATUS_READY,
                    'extension' => true,
                    'server' => true,
                    'message' => __('Custom driver', 'wp-queue'),
                ]
                : [
                    'status' => self::STATUS_UNAVAILABLE,
                    'extension' => false,
                    'server' => false,
                    'message' => __('Unknown driver', 'wp-queue'),
                ],
        };

        $this->driverStatusCache[$driver] = $status;

        return $status;
    }

    /**
     * Get Redis driver status with detailed checks.
     *
     * Checks for Redis availability in this order:
     * 1. PHP extension "redis" (phpredis)
     * 2. Redis Object Cache plugin (uses Predis library)
     * 3. Predis library directly
     *
     * @return array{status: string, extension: bool, server: bool, message: string}
     */
    protected function getRedisStatus(): array
    {
        $host = defined('WP_REDIS_HOST') ? WP_REDIS_HOST : '127.0.0.1';
        $port = defined('WP_REDIS_PORT') ? WP_REDIS_PORT : 6379;

        // Check if Redis is disabled
        if (defined('WP_REDIS_DISABLED') && WP_REDIS_DISABLED) {
            return [
                'status' => self::STATUS_UNAVAILABLE,
                'extension' => false,
                'server' => false,
                'message' => __('Redis is disabled via WP_REDIS_DISABLED', 'wp-queue'),
            ];
        }

        // Method 1: Check PHP extension (phpredis)
        if (extension_loaded('redis')) {
            return $this->checkRedisViaPhpRedis($host, $port);
        }

        // Method 2: Check Redis Object Cache plugin
        if ($this->isRedisObjectCachePluginAvailable()) {
            return $this->checkRedisViaPlugin($host, $port);
        }

        // Method 3: Check Predis library directly
        if ($this->isPredisAvailable()) {
            return $this->checkRedisViaPredis($host, $port);
        }

        // No Redis client available
        return [
            'status' => self::STATUS_NO_EXTENSION,
            'extension' => false,
            'server' => false,
            'message' => __('PHP extension "redis" (phpredis) is not installed', 'wp-queue'),
        ];
    }

    /**
     * Check Redis connection via phpredis extension.
     *
     * @return array{status: string, extension: bool, server: bool, message: string}
     */
    protected function checkRedisViaPhpRedis(string $host, int $port): array
    {
        try {
            $queue = new RedisQueue();
            $reflection = new \ReflectionMethod($queue, 'connection');
            $reflection->setAccessible(true);
            $redis = $reflection->invoke($queue);
            $redis->ping();

            return [
                'status' => self::STATUS_READY,
                'extension' => true,
                'server' => true,
                // translators: 1: Redis host, 2: Redis port.
                'message' => sprintf(__('Connected to Redis at %1$s:%2$d (phpredis)', 'wp-queue'), $host, $port),
            ];
        } catch (\Throwable $e) {
            return [
                'status' => self::STATUS_NO_SERVER,
                'extension' => true,
                'server' => false,
                // translators: 1: Redis host, 2: Redis port, 3: Error message.
                'message' => sprintf(
                    __('Cannot connect to Redis at %1$s:%2$d - %3$s', 'wp-queue'),
                    $host,
                    $port,
                    $e->getMessage(),
                ),
            ];
        }
    }

    /**
     * Check if Redis Object Cache plugin is available and connected.
     */
    protected function isRedisObjectCachePluginAvailable(): bool
    {
        // Check if plugin function exists
        if (! function_exists('redis_object_cache')) {
            return false;
        }

        try {
            $plugin = redis_object_cache();

            // Check if plugin has get_redis_status method and returns true
            if (method_exists($plugin, 'get_redis_status')) {
                return $plugin->get_redis_status() === true;
            }

            return false;
        } catch (\Throwable) {
            return false;
        }
    }

    /**
     * Check Redis connection via Redis Object Cache plugin.
     *
     * @return array{status: string, extension: bool, server: bool, message: string}
     */
    protected function checkRedisViaPlugin(string $host, int $port): array
    {
        try {
            $plugin = redis_object_cache();

            // Plugin is connected, try to verify
            if (method_exists($plugin, 'check_redis_connection')) {
                $result = $plugin->check_redis_connection();

                if ($result === true) {
                    return [
                        'status' => self::STATUS_READY,
                        'extension' => true,
                        'server' => true,
                        // translators: 1: Redis host, 2: Redis port.
                        'message' => sprintf(__('Connected to Redis at %1$s:%2$d (via redis-cache plugin)', 'wp-queue'), $host, $port),
                    ];
                }

                // Connection failed with error message
                return [
                    'status' => self::STATUS_NO_SERVER,
                    'extension' => true,
                    'server' => false,
                    'message' => is_string($result) ? $result : __('Redis connection failed', 'wp-queue'),
                ];
            }

            // Fallback: plugin exists but no check method
            return [
                'status' => self::STATUS_READY,
                'extension' => true,
                'server' => true,
                // translators: 1: Redis host, 2: Redis port.
                'message' => sprintf(__('Connected to Redis at %1$s:%2$d (via redis-cache plugin)', 'wp-queue'), $host, $port),
            ];
        } catch (\Throwable $e) {
            return [
                'status' => self::STATUS_NO_SERVER,
                'extension' => true,
                'server' => false,
                // translators: 1: Redis host, 2: Redis port, 3: Error message.
                'message' => sprintf(
                    __('Cannot connect to Redis at %1$s:%2$d - %3$s', 'wp-queue'),
                    $host,
                    $port,
                    $e->getMessage(),
                ),
            ];
        }
    }

    /**
     * Check if Predis library is available.
     */
    protected function isPredisAvailable(): bool
    {
        // Check if Predis is loaded via redis-cache plugin
        if (defined('WP_REDIS_PLUGIN_PATH') && file_exists(WP_REDIS_PLUGIN_PATH.'/dependencies/predis/predis/autoload.php')) {
            return true;
        }

        // Check if Predis is loaded via Composer
        return class_exists('\Predis\Client');
    }

    /**
     * Check Redis connection via Predis library.
     *
     * @return array{status: string, extension: bool, server: bool, message: string}
     */
    protected function checkRedisViaPredis(string $host, int $port): array
    {
        try {
            // Load Predis from redis-cache plugin if available
            if (defined('WP_REDIS_PLUGIN_PATH') && file_exists(WP_REDIS_PLUGIN_PATH.'/dependencies/predis/predis/autoload.php')) {
                require_once WP_REDIS_PLUGIN_PATH.'/dependencies/predis/predis/autoload.php';
            }

            if (! class_exists('\Predis\Client')) {
                return [
                    'status' => self::STATUS_NO_EXTENSION,
                    'extension' => false,
                    'server' => false,
                    'message' => __('Predis library is not available', 'wp-queue'),
                ];
            }

            $parameters = [
                'scheme' => defined('WP_REDIS_SCHEME') ? WP_REDIS_SCHEME : 'tcp',
                'host' => $host,
                'port' => $port,
                'database' => defined('WP_REDIS_DATABASE') ? (int) WP_REDIS_DATABASE : 0,
                'timeout' => 1,
                'read_write_timeout' => 1,
            ];

            if (defined('WP_REDIS_PASSWORD') && WP_REDIS_PASSWORD !== '') {
                $parameters['password'] = WP_REDIS_PASSWORD;
            }

            $client = new \Predis\Client($parameters);
            $client->ping();

            return [
                'status' => self::STATUS_READY,
                'extension' => true,
                'server' => true,
                // translators: 1: Redis host, 2: Redis port.
                'message' => sprintf(__('Connected to Redis at %1$s:%2$d (Predis)', 'wp-queue'), $host, $port),
            ];
        } catch (\Throwable $e) {
            return [
                'status' => self::STATUS_NO_SERVER,
                'extension' => true,
                'server' => false,
                // translators: 1: Redis host, 2: Redis port, 3: Error message.
                'message' => sprintf(
                    __('Cannot connect to Redis at %1$s:%2$d - %3$s', 'wp-queue'),
                    $host,
                    $port,
                    $e->getMessage(),
                ),
            ];
        }
    }

    /**
     * Get Memcached driver status with detailed checks.
     *
     * @return array{status: string, extension: bool, server: bool, message: string}
     */
    protected function getMemcachedStatus(): array
    {
        // Step 1: Check PHP extension
        if (! extension_loaded('memcached')) {
            return [
                'status' => self::STATUS_NO_EXTENSION,
                'extension' => false,
                'server' => false,
                'message' => __('PHP extension "memcached" is not installed', 'wp-queue'),
            ];
        }

        // Step 2: Check server connectivity
        try {
            $queue = new MemcachedQueue();
            // Use reflection to call protected connection() method for testing
            $reflection = new \ReflectionMethod($queue, 'connection');
            $reflection->setAccessible(true);
            $reflection->invoke($queue);

            $host = defined('WP_MEMCACHED_HOST') ? WP_MEMCACHED_HOST : '127.0.0.1';
            $port = defined('WP_MEMCACHED_PORT') ? WP_MEMCACHED_PORT : 11211;

            return [
                'status' => self::STATUS_READY,
                'extension' => true,
                'server' => true,
                // translators: 1: Memcached host, 2: Memcached port.
                'message' => sprintf(__('Connected to Memcached at %1$s:%2$d', 'wp-queue'), $host, $port),
            ];
        } catch (\Throwable $e) {
            $host = defined('WP_MEMCACHED_HOST') ? WP_MEMCACHED_HOST : '127.0.0.1';
            $port = defined('WP_MEMCACHED_PORT') ? WP_MEMCACHED_PORT : 11211;

            return [
                'status' => self::STATUS_NO_SERVER,
                'extension' => true,
                'server' => false,
                // translators: 1: Memcached host, 2: Memcached port, 3: Error message.
                'message' => sprintf(
                    __('Cannot connect to Memcached at %1$s:%2$d - %3$s', 'wp-queue'),
                    $host,
                    $port,
                    $e->getMessage(),
                ),
            ];
        }
    }

    /**
     * Get all available drivers with detailed status.
     *
     * @return array<string, array{status: string, extension: bool, server: bool, message: string, available: bool}>
     */
    public function getAvailableDrivers(): array
    {
        $drivers = [];

        foreach (['database', 'sync', 'redis', 'memcached'] as $driver) {
            $status = $this->getDriverStatus($driver);
            $drivers[$driver] = array_merge($status, [
                'available' => $status['status'] === self::STATUS_READY,
                // Legacy compatibility
                'info' => $status['message'],
            ]);
        }

        // Add custom drivers
        foreach (array_keys($this->customCreators) as $name) {
            $drivers[$name] = [
                'status' => self::STATUS_READY,
                'extension' => true,
                'server' => true,
                'message' => __('Custom driver', 'wp-queue'),
                'available' => true,
                'info' => __('Custom driver', 'wp-queue'),
            ];
        }

        return $drivers;
    }

    /**
     * Check if a driver is available (legacy method).
     *
     * @deprecated Use isDriverReady() for accurate status
     */
    public function isDriverAvailable(string $driver): bool
    {
        return $this->isDriverReady($driver);
    }

    /**
     * @param  callable(QueueManager): QueueInterface  $callback
     */
    public function extend(string $driver, callable $callback): void
    {
        $this->customCreators[$driver] = $callback;
    }

    /**
     * Discover all queue names from the current driver.
     *
     * @return string[]
     */
    public function discoverQueues(): array
    {
        $driver = $this->getDefaultDriver();
        $queues = ['default'];

        try {
            switch ($driver) {
                case 'redis':
                    $queues = $this->discoverRedisQueues();
                    break;

                case 'memcached':
                    // Memcached doesn't support key scanning, use known queues
                    $queues = $this->discoverKnownQueues();
                    break;

                case 'database':
                default:
                    $queues = $this->discoverDatabaseQueues();
                    break;
            }
        } catch (\Throwable $e) {
            // Silently ignore discovery errors; default queue will be used.
        }

        // Always include default queue
        if (! in_array('default', $queues, true)) {
            array_unshift($queues, 'default');
        }

        return array_unique($queues);
    }

    /**
     * Discover queues from Redis.
     *
     * @return string[]
     */
    protected function discoverRedisQueues(): array
    {
        $queue = $this->connection('redis');

        if (! $queue instanceof RedisQueue) {
            return ['default'];
        }

        // Use reflection to access getAllQueues method
        $reflection = new \ReflectionMethod($queue, 'getAllQueues');
        $reflection->setAccessible(true);

        return $reflection->invoke($queue);
    }

    /**
     * Discover queues from database (wp_options).
     *
     * @return string[]
     */
    protected function discoverDatabaseQueues(): array
    {
        global $wpdb;

        $results = $wpdb->get_col(
            $wpdb->prepare(
                "SELECT option_name FROM {$wpdb->options} WHERE option_name LIKE %s",
                'wp_queue_jobs_%',
            ),
        );

        $queues = ['default'];
        foreach ($results as $optionName) {
            $name = str_replace('wp_queue_jobs_', '', $optionName);
            if ($name && ! in_array($name, $queues, true)) {
                $queues[] = $name;
            }
        }

        return $queues;
    }

    /**
     * Get known queues from scheduled jobs.
     *
     * Used as fallback when driver doesn't support queue discovery.
     *
     * @return string[]
     */
    protected function discoverKnownQueues(): array
    {
        // Known queues used by iiko plugin
        return ['default', 'imports', 'sync', 'cleanup'];
    }

    protected function resolve(string $name): QueueInterface
    {
        if (isset($this->customCreators[$name])) {
            return ($this->customCreators[$name])($this);
        }

        return match ($name) {
            'database' => new DatabaseQueue(),
            'sync' => new SyncQueue(),
            'redis' => new RedisQueue(),
            'memcached' => new MemcachedQueue(),
            'auto' => $this->resolve($this->detectBestDriver()),
            default => throw new InvalidArgumentException(
                // translators: %s: Queue driver name.
                esc_html(sprintf(__('Queue driver "%s" is not supported.', 'wp-queue'), $name)),
            ),
        };
    }
}

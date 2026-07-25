<?php

declare(strict_types=1);

namespace WPQueue\Queue\Redis;

if (! defined('ABSPATH')) {
    exit;
}

/**
 * Factory for creating Redis client adapters.
 *
 * Automatically selects the best available client:
 * 1. phpredis extension (fastest)
 * 2. Predis library via redis-cache plugin
 * 3. Predis library via Composer
 */
class RedisClientFactory
{
    /**
     * Create Redis client based on availability.
     *
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
     *
     * @throws \RuntimeException If no Redis client is available
     */
    public static function create(array $config): RedisClientInterface
    {
        // Check for explicit client preference
        $preferredClient = defined('WP_REDIS_CLIENT') ? strtolower(WP_REDIS_CLIENT) : null;

        // If phpredis is preferred and available
        if ($preferredClient === 'phpredis' && extension_loaded('redis')) {
            return new PhpRedisClient($config);
        }

        // If predis is preferred and available
        if ($preferredClient === 'predis' && self::isPredisAvailable()) {
            return new PredisClient($config);
        }

        // Auto-detect: prefer phpredis for performance
        if (extension_loaded('redis')) {
            return new PhpRedisClient($config);
        }

        // Fallback to Predis
        if (self::isPredisAvailable()) {
            return new PredisClient($config);
        }

        throw new \RuntimeException(
            'No Redis client available. Install phpredis extension or enable redis-cache plugin.',
        );
    }

    /**
     * Check if any Redis client is available.
     */
    public static function isAvailable(): bool
    {
        return extension_loaded('redis') || self::isPredisAvailable();
    }

    /**
     * Check if phpredis extension is available.
     */
    public static function isPhpRedisAvailable(): bool
    {
        return extension_loaded('redis');
    }

    /**
     * Check if Predis library is available.
     */
    public static function isPredisAvailable(): bool
    {
        // Already loaded
        if (class_exists('\Predis\Client')) {
            return true;
        }

        // Available via redis-cache plugin
        if (defined('WP_REDIS_PLUGIN_PATH') && file_exists(WP_REDIS_PLUGIN_PATH.'/dependencies/predis/predis/autoload.php')) {
            return true;
        }

        return false;
    }

    /**
     * Get available client types.
     *
     * @return string[]
     */
    public static function getAvailableClients(): array
    {
        $clients = [];

        if (extension_loaded('redis')) {
            $clients[] = 'phpredis';
        }

        if (self::isPredisAvailable()) {
            $clients[] = 'predis';
        }

        return $clients;
    }
}

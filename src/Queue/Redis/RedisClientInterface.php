<?php

declare(strict_types=1);

namespace WPQueue\Queue\Redis;

if (! defined('ABSPATH')) {
    exit;
}

/**
 * Interface for Redis client adapters.
 *
 * Provides unified API for phpredis and Predis clients.
 */
interface RedisClientInterface
{
    /**
     * Connect to Redis server.
     *
     * @throws \RuntimeException If connection fails
     */
    public function connect(): void;

    /**
     * Check if connected to Redis.
     */
    public function isConnected(): bool;

    /**
     * Ping Redis server.
     *
     * @return bool True if server responds
     */
    public function ping(): bool;

    /**
     * Close connection.
     */
    public function close(): void;

    // List operations

    /**
     * Push value to the end of a list.
     */
    public function rPush(string $key, string $value): int;

    /**
     * Pop value from the beginning of a list.
     */
    public function lPop(string $key): ?string;

    /**
     * Get list length.
     */
    public function lLen(string $key): int;

    /**
     * Get range of list elements.
     *
     * @return string[]
     */
    public function lRange(string $key, int $start, int $stop): array;

    /**
     * Remove elements from list.
     */
    public function lRem(string $key, string $value, int $count): int;

    // Sorted set operations

    /**
     * Add member to sorted set.
     */
    public function zAdd(string $key, float $score, string $member): int;

    /**
     * Remove member from sorted set.
     */
    public function zRem(string $key, string $member): int;

    /**
     * Get members by score range.
     *
     * @return string[]
     */
    public function zRangeByScore(string $key, string $min, string $max): array;

    /**
     * Get range of members.
     *
     * @return string[]
     */
    public function zRange(string $key, int $start, int $stop): array;

    /**
     * Get sorted set cardinality.
     */
    public function zCard(string $key): int;

    // Hash operations

    /**
     * Set hash field.
     */
    public function hSet(string $key, string $field, string $value): int;

    /**
     * Get hash field.
     */
    public function hGet(string $key, string $field): ?string;

    /**
     * Delete hash field.
     */
    public function hDel(string $key, string $field): int;

    /**
     * Get hash length.
     */
    public function hLen(string $key): int;

    // Key operations

    /**
     * Delete keys.
     *
     * @param  string[]  $keys
     */
    public function del(array $keys): int;

    /**
     * Find keys matching pattern.
     *
     * @return string[]
     */
    public function keys(string $pattern): array;

    /**
     * Get client type identifier.
     */
    public function getClientType(): string;
}

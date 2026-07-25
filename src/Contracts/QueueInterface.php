<?php

declare(strict_types=1);

namespace WPQueue\Contracts;

if (! defined('ABSPATH')) {
    exit;
}

interface QueueInterface
{
    /**
     * Push a job onto the queue.
     */
    public function push(JobInterface $job): string;

    /**
     * Push a job onto the queue with delay.
     */
    public function later(int $delay, JobInterface $job): string;

    /**
     * Pop the next job from the queue.
     */
    public function pop(string $queue = 'default'): ?JobInterface;

    /**
     * Delete a job from the queue.
     */
    public function delete(string $jobId): bool;

    /**
     * Release a job back onto the queue.
     */
    public function release(JobInterface $job, int $delay = 0): void;

    /**
     * Get the size of the queue.
     */
    public function size(string $queue = 'default'): int;

    /**
     * Clear all jobs from the queue.
     */
    public function clear(string $queue = 'default'): int;

    /**
     * Check if queue is empty.
     */
    public function isEmpty(string $queue = 'default'): bool;
}

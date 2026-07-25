<?php

declare(strict_types=1);

namespace WPQueue\Queue;

use WPQueue\Contracts\JobInterface;
use WPQueue\Contracts\QueueInterface;

if (! defined('ABSPATH')) {
    exit;
}

/**
 * Synchronous queue - executes jobs immediately.
 */
class SyncQueue implements QueueInterface
{
    public function push(JobInterface $job): string
    {
        $job->incrementAttempts();

        try {
            $job->handle();
        } catch (\Throwable $e) {
            $job->failed($e);
            throw $e;
        }

        return $job->getId();
    }

    public function later(int $delay, JobInterface $job): string
    {
        // Sync queue ignores delay
        return $this->push($job);
    }

    public function pop(string $queue = 'default'): ?JobInterface
    {
        // Sync queue doesn't store jobs
        return null;
    }

    public function delete(string $jobId): bool
    {
        return false;
    }

    public function release(JobInterface $job, int $delay = 0): void
    {
        // Sync queue doesn't support release
    }

    public function size(string $queue = 'default'): int
    {
        return 0;
    }

    public function clear(string $queue = 'default'): int
    {
        return 0;
    }

    public function isEmpty(string $queue = 'default'): bool
    {
        return true;
    }
}

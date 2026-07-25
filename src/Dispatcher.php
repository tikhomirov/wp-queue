<?php

declare(strict_types=1);

namespace WPQueue;

use WPQueue\Contracts\JobInterface;
use WPQueue\Jobs\PendingDispatch;
use WPQueue\Queue\SyncQueue;

if (! defined('ABSPATH')) {
    exit;
}

class Dispatcher
{
    public function __construct(
        protected QueueManager $manager,
    ) {}

    /**
     * Dispatch a job to the queue.
     */
    public function dispatch(JobInterface $job): PendingDispatch
    {
        return new PendingDispatch($job, $this->manager);
    }

    /**
     * Dispatch a job synchronously (bypass queue).
     */
    public function dispatchSync(JobInterface $job): void
    {
        (new SyncQueue())->push($job);
    }

    /**
     * Dispatch a job immediately without waiting.
     */
    public function dispatchNow(JobInterface $job): void
    {
        $this->dispatchSync($job);
    }

    /**
     * Chain multiple jobs.
     *
     * @param  JobInterface[]  $jobs
     */
    public function chain(array $jobs): PendingChain
    {
        return new PendingChain($jobs, $this->manager);
    }

    /**
     * Batch multiple jobs.
     *
     * @param  JobInterface[]  $jobs
     */
    public function batch(array $jobs): PendingBatch
    {
        return new PendingBatch($jobs, $this->manager);
    }
}

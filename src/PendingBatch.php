<?php

declare(strict_types=1);

namespace WPQueue;

use WPQueue\Contracts\JobInterface;
use WPQueue\Loopback\LoopbackDispatcher;

if (! defined('ABSPATH')) {
    exit;
}

class PendingBatch
{
    protected string $queue = 'default';

    /**
     * @param  JobInterface[]  $jobs
     */
    public function __construct(
        protected array $jobs,
        protected QueueManager $manager,
    ) {}

    public function onQueue(string $queue): static
    {
        $this->queue = $queue;

        return $this;
    }

    public function dispatch(): string
    {
        $batchId = bin2hex(random_bytes(16));
        $connection = $this->manager->connection();

        foreach ($this->jobs as $job) {
            $job->onQueue($this->queue);
            $connection->push($job);
        }

        LoopbackDispatcher::spawn($this->queue);

        return $batchId;
    }
}

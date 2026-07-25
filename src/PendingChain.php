<?php

declare(strict_types=1);

namespace WPQueue;

use WPQueue\Contracts\JobInterface;
use WPQueue\Jobs\ChainedJob;
use WPQueue\Loopback\LoopbackDispatcher;

if (! defined('ABSPATH')) {
    exit;
}

if (! defined('ABSPATH')) {
    exit;
}

class PendingChain
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

    public function dispatch(): void
    {
        if (empty($this->jobs)) {
            return;
        }

        // First job starts the chain
        $firstJob = array_shift($this->jobs);

        // Wrap in ChainedJob with remaining jobs
        $chainedJob = new ChainedJob($firstJob, $this->jobs);
        $chainedJob->onQueue($this->queue);

        $this->manager->connection()->push($chainedJob);

        LoopbackDispatcher::spawn($this->queue);
    }
}

<?php

declare(strict_types=1);

namespace WPQueue\Jobs;

use WPQueue\Contracts\JobInterface;
use WPQueue\WPQueue;

if (! defined('ABSPATH')) {
    exit;
}

class ChainedJob extends Job
{
    /**
     * @param  JobInterface[]  $remainingJobs
     */
    public function __construct(
        protected JobInterface $currentJob,
        protected array $remainingJobs = [],
    ) {
        parent::__construct();
    }

    public function handle(): void
    {
        // Execute current job
        $this->currentJob->handle();

        // If there are remaining jobs, dispatch the next one
        if (! empty($this->remainingJobs)) {
            $nextJob = array_shift($this->remainingJobs);
            $chainedJob = new self($nextJob, $this->remainingJobs);
            $chainedJob->onQueue($this->getQueue());

            WPQueue::dispatch($chainedJob);
        }
    }

    public function failed(\Throwable $e): void
    {
        $this->currentJob->failed($e);
    }
}

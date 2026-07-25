<?php

declare(strict_types=1);

namespace WPQueue;

use Throwable;
use WPQueue\Contracts\JobInterface;
use WPQueue\Events\JobFailed;
use WPQueue\Events\JobProcessed;
use WPQueue\Events\JobProcessing;
use WPQueue\Events\JobRetrying;
use WPQueue\Storage\LogStorage;

if (! defined('ABSPATH')) {
    exit;
}

class Worker
{
    protected int $startTime;

    protected int $jobsProcessed = 0;

    protected int $maxJobs = 0;

    protected int $maxTime = 0;

    protected int $memoryLimit;

    protected bool $useBackoff = true;

    protected ?LogStorage $logger = null;

    public function __construct(
        protected QueueManager $manager,
    ) {
        $this->startTime = time();
        $this->memoryLimit = $this->getDefaultMemoryLimit();
    }

    /**
     * Get default memory limit from WordPress configuration.
     * Uses WP_MAX_MEMORY_LIMIT if available, otherwise falls back to WP_MEMORY_LIMIT.
     */
    protected function getDefaultMemoryLimit(): int
    {
        // If WP_MAX_MEMORY_LIMIT is defined, use it (WordPress admin memory limit)
        if (defined('WP_MAX_MEMORY_LIMIT')) {
            $limit = WP_MAX_MEMORY_LIMIT;
        } elseif (defined('WP_MEMORY_LIMIT')) {
            // Fall back to WP_MEMORY_LIMIT
            $limit = WP_MEMORY_LIMIT;
        } else {
            // Last resort: use 256MB
            $limit = '256M';
        }

        // Convert to bytes and then to MB
        if (function_exists('wp_convert_hr_to_bytes')) {
            $bytes = wp_convert_hr_to_bytes($limit);

            return (int) ($bytes / 1024 / 1024);
        }

        // Fallback conversion if wp_convert_hr_to_bytes is not available
        return $this->convertToMB($limit);
    }

    /**
     * Convert human-readable size to MB.
     * Fallback if wp_convert_hr_to_bytes is not available.
     */
    protected function convertToMB(string $value): int
    {
        $value = strtoupper(trim($value));
        $multiplier = 1;

        if (str_ends_with($value, 'G')) {
            $multiplier = 1024 * 1024 * 1024;
            $value = substr($value, 0, -1);
        } elseif (str_ends_with($value, 'M')) {
            $multiplier = 1024 * 1024;
            $value = substr($value, 0, -1);
        } elseif (str_ends_with($value, 'K')) {
            $multiplier = 1024;
            $value = substr($value, 0, -1);
        }

        $bytes = (int) $value * $multiplier;

        return (int) ($bytes / 1024 / 1024);
    }

    /**
     * Run the worker loop.
     */
    public function daemon(string $queue = 'default', int $sleep = 3): void
    {
        while (! $this->shouldStop()) {
            if (! $this->runNextJob($queue)) {
                sleep($sleep);
            }
        }
    }

    /**
     * Process the next job on the queue.
     */
    public function runNextJob(string $queue = 'default'): bool
    {
        // Check if queue is paused
        if (WPQueue::isPaused($queue)) {
            error_log("WP Queue: Queue '{$queue}' is paused");

            return false;
        }

        // Check if we should stop before processing
        if ($this->shouldStop()) {
            $this->logStopReason();

            return false;
        }

        $connection = $this->manager->connection();
        $job = $connection->pop($queue);

        if ($job === null) {
            return false;
        }

        error_log("WP Queue: Processing job from queue '{$queue}': ".get_class($job));

        return $this->process($job, $queue);
    }

    /**
     * Process a job.
     */
    public function process(JobInterface $job, string $queue): bool
    {
        try {
            $this->raiseBeforeJobEvent($job, $queue);

            $job->incrementAttempts();
            $job->handle();

            $this->raiseAfterJobEvent($job, $queue);
            $this->manager->connection()->delete($job->getId());

            $this->jobsProcessed++;
            $this->log('completed', $job);

            // Check memory after job completion
            $this->checkMemoryAfterJob($job, $queue);

            return true;
        } catch (Throwable $e) {
            return $this->handleJobException($job, $queue, $e);
        }
    }

    /**
     * Check memory usage after job completion and release job if memory is critical.
     */
    protected function checkMemoryAfterJob(JobInterface $job, string $queue): void
    {
        $usage = memory_get_usage(true) / 1024 / 1024;
        $threshold = $this->memoryLimit * 0.85; // 85% threshold

        if ($usage >= $threshold) {
            $percent = ($usage / $this->memoryLimit) * 100;
            error_log(sprintf(
                'WP Queue: Memory usage critical after job completion: %.1fMB/%.1fMB (%.1f%%). Worker will stop after this job.',
                $usage,
                $this->memoryLimit,
                $percent,
            ));

            // Force stop after this job
            $this->maxJobs = $this->jobsProcessed;
        }
    }

    /**
     * Handle an exception that occurred while the job was running.
     */
    protected function handleJobException(JobInterface $job, string $queue, Throwable $e): bool
    {
        $this->log('failed', $job, $e->getMessage());

        if ($job->getAttempts() < $job->getMaxAttempts()) {
            $this->raiseRetryingEvent($job, $queue, $e);
            $this->manager->connection()->release($job, $this->calculateBackoff($job));

            return true;
        }

        try {
            $job->failed($e);
        } catch (Throwable) {
            // Ignore exceptions in failed handler
        }

        $this->raiseFailedJobEvent($job, $queue, $e);
        $this->manager->connection()->delete($job->getId());

        return false;
    }

    /**
     * Calculate exponential backoff delay.
     */
    protected function calculateBackoff(JobInterface $job): int
    {
        if (! $this->useBackoff) {
            return 0;
        }

        $attempts = $job->getAttempts();

        return min(2 ** $attempts, 3600); // Max 1 hour
    }

    /**
     * Enable or disable exponential backoff for retries.
     */
    public function setUseBackoff(bool $useBackoff): void
    {
        $this->useBackoff = $useBackoff;
    }

    /**
     * Check if we should stop the worker.
     */
    public function shouldStop(): bool
    {
        if ($this->maxJobs > 0 && $this->jobsProcessed >= $this->maxJobs) {
            return true;
        }

        if ($this->maxTime > 0 && (time() - $this->startTime) >= $this->maxTime) {
            return true;
        }

        if ($this->memoryExceeded()) {
            return true;
        }

        return false;
    }

    /**
     * Log the reason why worker is stopping.
     */
    protected function logStopReason(): void
    {
        if ($this->maxJobs > 0 && $this->jobsProcessed >= $this->maxJobs) {
            error_log("WP Queue: Stopping worker - max jobs reached ({$this->jobsProcessed}/{$this->maxJobs})");

            return;
        }

        if ($this->maxTime > 0 && (time() - $this->startTime) >= $this->maxTime) {
            $elapsed = time() - $this->startTime;
            error_log("WP Queue: Stopping worker - max time exceeded ({$elapsed}s/{$this->maxTime}s)");

            return;
        }

        if ($this->memoryExceeded()) {
            $usage = memory_get_usage(true) / 1024 / 1024;
            error_log("WP Queue: Stopping worker - memory limit exceeded ({$usage}MB/{$this->memoryLimit}MB). Jobs processed: {$this->jobsProcessed}");

            return;
        }
    }

    /**
     * Check if memory limit is exceeded.
     */
    public function memoryExceeded(): bool
    {
        $usage = memory_get_usage(true) / 1024 / 1024;

        return $usage >= $this->memoryLimit;
    }

    public function setMaxJobs(int $jobs): void
    {
        $this->maxJobs = $jobs;
    }

    public function setMaxTime(int $seconds): void
    {
        $this->maxTime = $seconds;
        $this->startTime = time();
    }

    public function setMemoryLimit(int $megabytes): void
    {
        $this->memoryLimit = $megabytes;
    }

    public function getMaxJobs(): int
    {
        return $this->maxJobs;
    }

    public function getMaxTime(): int
    {
        return $this->maxTime;
    }

    /**
     * Reset worker state for testing or reuse.
     * Clears counters and limits to initial values.
     */
    public function reset(): void
    {
        $this->jobsProcessed = 0;
        $this->maxJobs = 0;
        $this->maxTime = 0;
        $this->useBackoff = true;
        $this->startTime = time();
    }

    public function getMemoryLimit(): int
    {
        return $this->memoryLimit;
    }

    public function getJobsProcessed(): int
    {
        return $this->jobsProcessed;
    }

    public function setLogger(LogStorage $logger): void
    {
        $this->logger = $logger;
    }

    protected function log(string $status, JobInterface $job, ?string $message = null): void
    {
        if ($this->logger === null) {
            return;
        }

        $this->logger->log($status, $job, $message);
    }

    protected function raiseBeforeJobEvent(JobInterface $job, string $queue): void
    {
        if (function_exists('do_action')) {
            do_action('wp_queue_job_processing', new JobProcessing($job, $queue));
        }
    }

    protected function raiseAfterJobEvent(JobInterface $job, string $queue): void
    {
        if (function_exists('do_action')) {
            do_action('wp_queue_job_processed', new JobProcessed($job, $queue));
        }
    }

    protected function raiseFailedJobEvent(JobInterface $job, string $queue, Throwable $e): void
    {
        if (function_exists('do_action')) {
            do_action('wp_queue_job_failed', new JobFailed($job, $queue, $e));
        }
    }

    protected function raiseRetryingEvent(JobInterface $job, string $queue, Throwable $e): void
    {
        if (function_exists('do_action')) {
            do_action('wp_queue_job_retrying', new JobRetrying($job, $queue, $job->getAttempts(), $e));
        }
    }
}

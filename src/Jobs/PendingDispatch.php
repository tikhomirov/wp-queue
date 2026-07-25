<?php

declare(strict_types=1);

namespace WPQueue\Jobs;

use WPQueue\Contracts\JobInterface;
use WPQueue\Loopback\LoopbackDispatcher;
use WPQueue\QueueManager;

if (! defined('ABSPATH')) {
    exit;
}

class PendingDispatch
{
    protected bool $shouldDispatch = true;

    protected static bool $spawnRegistered = false;

    protected static bool $spawnQueued = false;

    public function __construct(
        protected JobInterface $job,
        protected QueueManager $manager,
    ) {}

    public function __destruct()
    {
        if ($this->shouldDispatch) {
            $this->send();
        }
    }

    /**
     * Set the queue name.
     */
    public function onQueue(string $queue): static
    {
        $this->job->onQueue($queue);

        return $this;
    }

    /**
     * Set the delay in seconds.
     */
    public function delay(int $seconds): static
    {
        $this->job->delay($seconds);

        return $this;
    }

    /**
     * Cancel the dispatch.
     */
    public function cancel(): void
    {
        $this->shouldDispatch = false;
    }

    /**
     * Get the underlying job.
     */
    public function getJob(): JobInterface
    {
        return $this->job;
    }

    /**
     * Actually send the job to the queue.
     */
    protected function send(): void
    {
        $queue = $this->manager->connection();
        $queue->push($this->job);

        $queueName = $this->job->getQueue() ?: 'default';
        LoopbackDispatcher::spawn($queueName);
        self::scheduleImmediateProcessing($queueName);
    }

    protected static function scheduleImmediateProcessing(string $queue): void
    {
        if (! function_exists('wp_schedule_single_event') || ! function_exists('spawn_cron')) {
            return;
        }

        if (! self::$spawnRegistered) {
            self::$spawnRegistered = true;

            register_shutdown_function(static function (): void {
                if (! self::$spawnQueued) {
                    return;
                }

                self::$spawnQueued = false;

                try {
                    spawn_cron();
                } catch (\Throwable $e) {
                    error_log('WP Queue: spawn_cron failed: '.$e->getMessage());
                }
            });
        }

        if (wp_next_scheduled('wp_queue_process', [$queue]) === false) {
            wp_schedule_single_event(time(), 'wp_queue_process', [$queue]);
        }

        self::$spawnQueued = true;
    }
}

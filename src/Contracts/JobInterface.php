<?php

declare(strict_types=1);

namespace WPQueue\Contracts;

if (! defined('ABSPATH')) {
    exit;
}

interface JobInterface
{
    /**
     * Execute the job.
     */
    public function handle(): void;

    /**
     * Get unique job identifier.
     */
    public function getId(): string;

    /**
     * Get queue name.
     */
    public function getQueue(): string;

    /**
     * Set queue name.
     */
    public function onQueue(string $queue): static;

    /**
     * Get number of attempts.
     */
    public function getAttempts(): int;

    /**
     * Increment attempts counter.
     */
    public function incrementAttempts(): void;

    /**
     * Get max attempts.
     */
    public function getMaxAttempts(): int;

    /**
     * Set max attempts.
     */
    public function setMaxAttempts(int $attempts): static;

    /**
     * Get timeout in seconds.
     */
    public function getTimeout(): int;

    /**
     * Set timeout in seconds.
     */
    public function setTimeout(int $seconds): static;

    /**
     * Get delay in seconds.
     */
    public function getDelay(): int;

    /**
     * Set delay in seconds.
     */
    public function delay(int $seconds): static;

    /**
     * Get created timestamp.
     */
    public function getCreatedAt(): int;

    /**
     * Convert to array for storage.
     *
     * @return array<string, mixed>
     */
    public function toArray(): array;

    /**
     * Handle job failure.
     */
    public function failed(\Throwable $e): void;
}

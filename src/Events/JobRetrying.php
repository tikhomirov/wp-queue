<?php

declare(strict_types=1);

namespace WPQueue\Events;

use Throwable;
use WPQueue\Contracts\JobInterface;

if (! defined('ABSPATH')) {
    exit;
}

final readonly class JobRetrying
{
    public function __construct(
        public JobInterface $job,
        public string $queue,
        public int $attempt,
        public Throwable $exception,
    ) {}
}

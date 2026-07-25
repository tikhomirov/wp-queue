<?php

declare(strict_types=1);

namespace WPQueue\Events;

use WPQueue\Contracts\JobInterface;

if (! defined('ABSPATH')) {
    exit;
}

final readonly class JobProcessed
{
    public function __construct(
        public JobInterface $job,
        public string $queue,
    ) {}
}

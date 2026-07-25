<?php

declare(strict_types=1);

namespace WPQueue\Attributes;

use Attribute;

if (! defined('ABSPATH')) {
    exit;
}

#[Attribute(Attribute::TARGET_CLASS)]
final readonly class Retries
{
    public function __construct(
        public int $times = 3,
    ) {}
}

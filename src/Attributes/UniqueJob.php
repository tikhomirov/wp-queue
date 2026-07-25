<?php

declare(strict_types=1);

namespace WPQueue\Attributes;

use Attribute;

if (! defined('ABSPATH')) {
    exit;
}

#[Attribute(Attribute::TARGET_CLASS)]
final readonly class UniqueJob
{
    /**
     * @param  string|null  $key  Unique key for the job (null = use job class name)
     */
    public function __construct(
        public ?string $key = null,
    ) {}
}

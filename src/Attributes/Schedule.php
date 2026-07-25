<?php

declare(strict_types=1);

namespace WPQueue\Attributes;

use Attribute;

if (! defined('ABSPATH')) {
    exit;
}

#[Attribute(Attribute::TARGET_CLASS)]
final readonly class Schedule
{
    /**
     * @param  string  $interval  Cron interval (hourly, daily, 5min, etc.)
     * @param  string|null  $setting  Option name to read interval from
     */
    public function __construct(
        public string $interval,
        public ?string $setting = null,
    ) {}
}

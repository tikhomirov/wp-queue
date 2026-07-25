<?php

declare(strict_types=1);

namespace WPQueue\Contracts;

if (! defined('ABSPATH')) {
    exit;
}

/**
 * Marker interface for queueable jobs.
 */
interface ShouldQueue {}

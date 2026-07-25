<?php

declare(strict_types=1);

namespace WPQueue\CLI;

use WP_CLI;
use WP_CLI\Utils;
use WPQueue\Admin\SystemStatus;
use WPQueue\WPQueue;

/**
 * Manage WP Queue jobs and cron events.
 *
 * ## EXAMPLES
 *
 *     # Show queue status
 *     $ wp queue status
 *
 *     # Process jobs from default queue
 *     $ wp queue work
 *
 *     # List all cron events
 *     $ wp queue cron list
 */
class QueueCommand
{
    /**
     * Show queue status.
     *
     * ## OPTIONS
     *
     * [--format=<format>]
     * : Output format.
     * ---
     * default: table
     * options:
     *   - table
     *   - json
     *   - csv
     *   - yaml
     * ---
     *
     * ## EXAMPLES
     *
     *     wp queue status
     *     wp queue status --format=json
     *
     * @when after_wp_load
     */
    public function status(array $args, array $assocArgs): void
    {
        global $wpdb;

        $queues = [];
        $results = $wpdb->get_col(
            $wpdb->prepare(
                "SELECT option_name FROM {$wpdb->options} WHERE option_name LIKE %s",
                'wp_queue_jobs_%',
            ),
        );

        foreach ($results as $optionName) {
            $queueName = str_replace('wp_queue_jobs_', '', $optionName);
            $jobs = get_site_option($optionName, []);

            $queues[] = [
                'queue' => $queueName,
                'jobs' => count($jobs),
                'status' => WPQueue::isPaused($queueName) ? 'paused' : (WPQueue::isProcessing($queueName) ? 'processing' : 'idle'),
            ];
        }

        if (empty($queues)) {
            $queues[] = [
                'queue' => 'default',
                'jobs' => 0,
                'status' => 'idle',
            ];
        }

        $format = $assocArgs['format'] ?? 'table';
        Utils\format_items($format, $queues, ['queue', 'jobs', 'status']);
    }

    /**
     * Process jobs from a queue.
     *
     * ## OPTIONS
     *
     * [<queue>]
     * : Queue name to process.
     * ---
     * default: default
     * ---
     *
     * [--limit=<limit>]
     * : Maximum number of jobs to process.
     * ---
     * default: 100
     * ---
     *
     * [--memory=<memory>]
     * : Memory limit in MB.
     * ---
     * default: 256
     * ---
     *
     * ## EXAMPLES
     *
     *     wp queue work
     *     wp queue work emails --limit=50
     *     wp queue work imports --memory=512
     *
     * @when after_wp_load
     */
    public function work(array $args, array $assocArgs): void
    {
        $queue = $args[0] ?? 'default';
        $limit = (int) ($assocArgs['limit'] ?? 100);

        // Get default memory limit from WordPress
        $defaultMemory = $this->getDefaultMemoryLimit();
        $memory = (int) ($assocArgs['memory'] ?? $defaultMemory);

        WP_CLI::log("Processing jobs from queue: {$queue} (memory limit: {$memory}MB, WordPress default: {$defaultMemory}MB)");

        $worker = WPQueue::worker();
        $worker->setMemoryLimit($memory);

        $processed = 0;

        while ($processed < $limit) {
            if (! $worker->runNextJob($queue)) {
                break;
            }
            $processed++;
            WP_CLI::log("Processed job #{$processed}");
        }

        WP_CLI::success("Processed {$processed} jobs from queue: {$queue}");
    }

    /**
     * Clear all jobs from a queue.
     *
     * ## OPTIONS
     *
     * [<queue>]
     * : Queue name to clear.
     * ---
     * default: default
     * ---
     *
     * [--yes]
     * : Skip confirmation.
     *
     * ## EXAMPLES
     *
     *     wp queue clear
     *     wp queue clear emails --yes
     *
     * @when after_wp_load
     */
    public function clear(array $args, array $assocArgs): void
    {
        $queue = $args[0] ?? 'default';

        WP_CLI::confirm("Are you sure you want to clear all jobs from queue '{$queue}'?", $assocArgs);

        $cleared = WPQueue::clear($queue);
        WP_CLI::success("Cleared {$cleared} jobs from queue: {$queue}");
    }

    /**
     * Pause a queue.
     *
     * ## OPTIONS
     *
     * [<queue>]
     * : Queue name to pause.
     * ---
     * default: default
     * ---
     *
     * ## EXAMPLES
     *
     *     wp queue pause
     *     wp queue pause emails
     *
     * @when after_wp_load
     */
    public function pause(array $args, array $assocArgs): void
    {
        $queue = $args[0] ?? 'default';
        WPQueue::pause($queue);
        WP_CLI::success("Queue '{$queue}' paused");
    }

    /**
     * Resume a paused queue.
     *
     * ## OPTIONS
     *
     * [<queue>]
     * : Queue name to resume.
     * ---
     * default: default
     * ---
     *
     * ## EXAMPLES
     *
     *     wp queue resume
     *     wp queue resume emails
     *
     * @when after_wp_load
     */
    public function resume(array $args, array $assocArgs): void
    {
        $queue = $args[0] ?? 'default';
        WPQueue::resume($queue);
        WP_CLI::success("Queue '{$queue}' resumed");
    }

    /**
     * Show failed jobs.
     *
     * ## OPTIONS
     *
     * [--format=<format>]
     * : Output format.
     * ---
     * default: table
     * options:
     *   - table
     *   - json
     *   - csv
     * ---
     *
     * ## EXAMPLES
     *
     *     wp queue failed
     *     wp queue failed --format=json
     *
     * @when after_wp_load
     */
    public function failed(array $args, array $assocArgs): void
    {
        $logs = WPQueue::logs()->failed();

        if (empty($logs)) {
            WP_CLI::log('No failed jobs found.');

            return;
        }

        $items = array_map(fn ($log) => [
            'id' => $log['job_id'] ?? '-',
            'job' => $log['job_class'],
            'queue' => $log['queue'],
            'error' => substr($log['message'] ?? '', 0, 50),
            'time' => wp_date('Y-m-d H:i:s', $log['timestamp']),
        ], $logs);

        $format = $assocArgs['format'] ?? 'table';
        Utils\format_items($format, $items, ['id', 'job', 'queue', 'error', 'time']);
    }

    /**
     * Show system status.
     *
     * ## OPTIONS
     *
     * [--format=<format>]
     * : Output format.
     * ---
     * default: table
     * options:
     *   - table
     *   - json
     *   - yaml
     * ---
     *
     * ## EXAMPLES
     *
     *     wp queue system
     *     wp queue system --format=json
     *
     * @when after_wp_load
     */
    public function system(array $args, array $assocArgs): void
    {
        $status = new SystemStatus();
        $report = $status->getFullReport();
        $health = $status->getHealthStatus();

        $format = $assocArgs['format'] ?? 'table';

        if ($format === 'json') {
            WP_CLI::log(wp_json_encode(['report' => $report, 'health' => $health], JSON_PRETTY_PRINT));

            return;
        }

        $items = [
            ['key' => 'PHP Version', 'value' => $report['php_version']],
            ['key' => 'WordPress Version', 'value' => $report['wp_version']],
            ['key' => 'Memory Limit', 'value' => $report['memory_limit_formatted']],
            ['key' => 'Memory Usage', 'value' => $report['current_memory_formatted'].' ('.$report['memory_percent'].'%)'],
            ['key' => 'Max Execution Time', 'value' => $report['max_execution_time'].'s'],
            ['key' => 'WP-Cron Disabled', 'value' => $report['wp_cron_disabled'] ? 'Yes' : 'No'],
            ['key' => 'Loopback Status', 'value' => $report['loopback']['status']],
            ['key' => 'Timezone', 'value' => $report['timezone']],
            ['key' => 'Health Status', 'value' => $health['status']],
        ];

        Utils\format_items('table', $items, ['key', 'value']);

        if (! empty($health['issues'])) {
            WP_CLI::warning('Issues detected:');
            foreach ($health['issues'] as $issue) {
                WP_CLI::log("  - {$issue}");
            }
        }
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
}

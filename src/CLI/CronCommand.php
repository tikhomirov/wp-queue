<?php

declare(strict_types=1);

namespace WPQueue\CLI;

use WP_CLI;
use WP_CLI\Utils;
use WPQueue\Admin\CronMonitor;

if (! defined('ABSPATH')) {
    exit;
}

/**
 * Manage WP-Cron events.
 *
 * ## EXAMPLES
 *
 *     # List all cron events
 *     $ wp queue cron list
 *
 *     # Run a cron event now
 *     $ wp queue cron run wp_version_check
 *
 *     # Pause a cron event
 *     $ wp queue cron pause my_custom_hook
 */
class CronCommand
{
    protected CronMonitor $monitor;

    public function __construct()
    {
        $this->monitor = new CronMonitor();
    }

    /**
     * List all cron events.
     *
     * ## OPTIONS
     *
     * [--source=<source>]
     * : Filter by source (wordpress, woocommerce, wp-queue, plugin).
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
     *   - count
     * ---
     *
     * ## EXAMPLES
     *
     *     wp queue cron list
     *     wp queue cron list --source=wordpress
     *     wp queue cron list --format=json
     *
     * @subcommand list
     *
     * @when after_wp_load
     */
    public function list_(array $args, array $assocArgs): void
    {
        $events = $this->monitor->getAllEvents();

        if (isset($assocArgs['source'])) {
            $source = $assocArgs['source'];
            $events = array_filter($events, fn ($e) => $e['source'] === $source);
        }

        if (empty($events)) {
            WP_CLI::log('No cron events found.');

            return;
        }

        $items = array_map(fn ($event) => [
            'hook' => $event['hook'],
            'next_run' => $event['is_overdue'] ? 'OVERDUE' : $event['next_run'],
            'schedule' => $event['schedule'] ?: 'single',
            'source' => $event['source'],
        ], $events);

        $format = $assocArgs['format'] ?? 'table';
        Utils\format_items($format, $items, ['hook', 'next_run', 'schedule', 'source']);
    }

    /**
     * Run a cron event immediately.
     *
     * ## OPTIONS
     *
     * <hook>
     * : The hook name to run.
     *
     * ## EXAMPLES
     *
     *     wp queue cron run wp_version_check
     *     wp queue cron run my_custom_hook
     *
     * @when after_wp_load
     */
    public function run(array $args, array $assocArgs): void
    {
        $hook = $args[0];

        WP_CLI::log("Running cron event: {$hook}");

        $this->monitor->runNow($hook);

        WP_CLI::success("Cron event '{$hook}' executed");
    }

    /**
     * Delete a cron event.
     *
     * ## OPTIONS
     *
     * <hook>
     * : The hook name to delete.
     *
     * [--yes]
     * : Skip confirmation.
     *
     * ## EXAMPLES
     *
     *     wp queue cron delete my_custom_hook
     *     wp queue cron delete my_custom_hook --yes
     *
     * @when after_wp_load
     */
    public function delete(array $args, array $assocArgs): void
    {
        $hook = $args[0];

        WP_CLI::confirm("Are you sure you want to delete all instances of '{$hook}'?", $assocArgs);

        $cleared = $this->monitor->clearHook($hook);
        WP_CLI::success("Deleted cron event: {$hook}");
    }

    /**
     * Pause a cron event.
     *
     * ## OPTIONS
     *
     * <hook>
     * : The hook name to pause.
     *
     * ## EXAMPLES
     *
     *     wp queue cron pause my_custom_hook
     *
     * @when after_wp_load
     */
    public function pause(array $args, array $assocArgs): void
    {
        $hook = $args[0];
        $event = $this->monitor->getEvent($hook);

        if (! $event) {
            WP_CLI::error("Cron event '{$hook}' not found");
        }

        $result = $this->monitor->pause($hook, $event['timestamp'], $event['args']);

        if ($result) {
            WP_CLI::success("Cron event '{$hook}' paused");
        } else {
            WP_CLI::error("Failed to pause cron event '{$hook}'");
        }
    }

    /**
     * Resume a paused cron event.
     *
     * ## OPTIONS
     *
     * <hook>
     * : The hook name to resume.
     *
     * ## EXAMPLES
     *
     *     wp queue cron resume my_custom_hook
     *
     * @when after_wp_load
     */
    public function resume(array $args, array $assocArgs): void
    {
        $hook = $args[0];

        $result = $this->monitor->resume($hook);

        if ($result) {
            WP_CLI::success("Cron event '{$hook}' resumed");
        } else {
            WP_CLI::error("Cron event '{$hook}' is not paused or not found");
        }
    }

    /**
     * List paused cron events.
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
     *     wp queue cron paused
     *     wp queue cron paused --format=json
     *
     * @when after_wp_load
     */
    public function paused(array $args, array $assocArgs): void
    {
        $paused = $this->monitor->getPaused();

        if (empty($paused)) {
            WP_CLI::log('No paused cron events.');

            return;
        }

        $items = array_map(fn ($event) => [
            'hook' => $event['hook'],
            'schedule' => $event['schedule'] ?: 'single',
            'paused_at' => wp_date('Y-m-d H:i:s', $event['paused_at']),
        ], $paused);

        $format = $assocArgs['format'] ?? 'table';
        Utils\format_items($format, $items, ['hook', 'schedule', 'paused_at']);
    }

    /**
     * Edit/reschedule a cron event.
     *
     * ## OPTIONS
     *
     * <hook>
     * : The hook name to edit.
     *
     * --schedule=<schedule>
     * : New schedule (hourly, twicedaily, daily, weekly, single).
     *
     * ## EXAMPLES
     *
     *     wp queue cron edit my_hook --schedule=daily
     *     wp queue cron edit my_hook --schedule=hourly
     *
     * @when after_wp_load
     */
    public function edit(array $args, array $assocArgs): void
    {
        $hook = $args[0];
        $newSchedule = $assocArgs['schedule'] ?? null;

        if (! $newSchedule) {
            WP_CLI::error('--schedule parameter is required');
        }

        $event = $this->monitor->getEvent($hook);

        if (! $event) {
            WP_CLI::error("Cron event '{$hook}' not found");
        }

        $result = $this->monitor->reschedule($hook, $event['timestamp'], $event['args'], $newSchedule);

        if ($result) {
            WP_CLI::success("Cron event '{$hook}' rescheduled to '{$newSchedule}'");
        } else {
            WP_CLI::error("Failed to reschedule cron event '{$hook}'");
        }
    }

    /**
     * Show cron schedules.
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
     *     wp queue cron schedules
     *
     * @when after_wp_load
     */
    public function schedules(array $args, array $assocArgs): void
    {
        $schedules = $this->monitor->getSchedules();

        $items = [];
        foreach ($schedules as $name => $schedule) {
            $items[] = [
                'name' => $name,
                'interval' => $schedule['interval'],
                'display' => $schedule['display'],
            ];
        }

        usort($items, fn ($a, $b) => $a['interval'] <=> $b['interval']);

        $format = $assocArgs['format'] ?? 'table';
        Utils\format_items($format, $items, ['name', 'interval', 'display']);
    }
}

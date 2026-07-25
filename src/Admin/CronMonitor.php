<?php

declare(strict_types=1);

namespace WPQueue\Admin;

if (! defined('ABSPATH')) {
    exit;
}

class CronMonitor
{
    /**
     * Core WordPress cron hooks.
     */
    protected const WP_CORE_HOOKS = [
        'wp_scheduled_delete',
        'wp_scheduled_auto_draft_delete',
        'wp_update_plugins',
        'wp_update_themes',
        'wp_version_check',
        'wp_privacy_delete_old_export_files',
        'wp_site_health_scheduled_check',
        'wp_https_detection',
        'wp_update_user_counts',
        'delete_expired_transients',
        'recovery_mode_clean_expired_keys',
    ];

    /**
     * WooCommerce hook prefixes.
     */
    protected const WC_PREFIXES = [
        'woocommerce_',
        'wc_',
        'action_scheduler_',
    ];

    /**
     * Get all scheduled cron events.
     *
     * @return array<int, array{hook: string, timestamp: int, schedule: string|false, interval: int|null, args: array, next_run: string, is_overdue: bool}>
     */
    public function getAllEvents(): array
    {
        $crons = _get_cron_array();

        if (empty($crons)) {
            return [];
        }

        $events = [];
        $now = time();

        foreach ($crons as $timestamp => $cronhooks) {
            foreach ($cronhooks as $hook => $keys) {
                foreach ($keys as $key => $data) {
                    $events[] = [
                        'hook' => $hook,
                        'timestamp' => (int) $timestamp,
                        'schedule' => $data['schedule'] ?? false,
                        'interval' => $data['interval'] ?? null,
                        'args' => $data['args'] ?? [],
                        'sig' => $key,
                        'next_run' => $this->formatNextRun((int) $timestamp),
                        'is_overdue' => $timestamp < $now,
                        'source' => $this->identifySource($hook),
                    ];
                }
            }
        }

        // Sort by timestamp
        usort($events, fn ($a, $b) => $a['timestamp'] <=> $b['timestamp']);

        return $events;
    }

    /**
     * Get events filtered by hook prefix.
     *
     * @return array<int, array>
     */
    public function getEventsByPrefix(string $prefix): array
    {
        return array_values(array_filter(
            $this->getAllEvents(),
            fn ($event) => str_starts_with($event['hook'], $prefix),
        ));
    }

    /**
     * Get a single event by hook name.
     */
    public function getEvent(string $hook): ?array
    {
        $events = $this->getAllEvents();

        foreach ($events as $event) {
            if ($event['hook'] === $hook) {
                return $event;
            }
        }

        return null;
    }

    /**
     * Unschedule a specific event.
     */
    public function unschedule(string $hook, int $timestamp, array $args = []): bool
    {
        return wp_unschedule_event($timestamp, $hook, $args) !== false;
    }

    /**
     * Clear all scheduled instances of a hook.
     */
    public function clearHook(string $hook): int
    {
        return wp_clear_scheduled_hook($hook);
    }

    /**
     * Clear all hooks matching a prefix.
     */
    public function clearByPrefix(string $prefix): int
    {
        $events = $this->getEventsByPrefix($prefix);
        $cleared = 0;

        $hooks = array_unique(array_column($events, 'hook'));

        foreach ($hooks as $hook) {
            wp_clear_scheduled_hook($hook);
            $cleared++;
        }

        return $cleared;
    }

    /**
     * Run a hook immediately.
     */
    public function runNow(string $hook, array $args = []): void
    {
        if (empty($args)) {
            do_action($hook);
        } else {
            do_action($hook, ...$args);
        }
    }

    /**
     * Get all registered cron schedules.
     *
     * @return array<string, array{interval: int, display: string}>
     */
    public function getSchedules(): array
    {
        return wp_get_schedules();
    }

    /**
     * Check if hook is a WordPress core hook.
     */
    public function isWordPressCore(string $hook): bool
    {
        if (in_array($hook, self::WP_CORE_HOOKS, true)) {
            return true;
        }

        // Additional patterns for core hooks
        return str_starts_with($hook, 'wp_') && ! str_starts_with($hook, 'wp_queue');
    }

    /**
     * Check if hook is a WooCommerce hook.
     */
    public function isWooCommerce(string $hook): bool
    {
        foreach (self::WC_PREFIXES as $prefix) {
            if (str_starts_with($hook, $prefix)) {
                return true;
            }
        }

        return false;
    }

    /**
     * Identify the source/owner of a hook.
     */
    public function identifySource(string $hook): string
    {
        if (str_starts_with($hook, 'wp_queue')) {
            return 'wp-queue';
        }

        if ($this->isWordPressCore($hook)) {
            return 'wordpress';
        }

        if ($this->isWooCommerce($hook)) {
            return 'woocommerce';
        }

        // Try to identify by common prefixes
        if (str_starts_with($hook, 'iiko_')) {
            return 'woo2iiko';
        }

        return 'plugin';
    }

    /**
     * Format next run time for display.
     */
    protected function formatNextRun(int $timestamp): string
    {
        $now = time();

        if ($timestamp <= $now) {
            return __('Overdue', 'wp-queue');
        }

        return human_time_diff($now, $timestamp);
    }

    /**
     * Get statistics about cron events.
     *
     * @return array{total: int, overdue: int, by_source: array<string, int>, by_schedule: array<string, int>}
     */
    public function getStats(): array
    {
        $events = $this->getAllEvents();
        $now = time();

        $stats = [
            'total' => count($events),
            'overdue' => 0,
            'paused' => $this->getPausedCount(),
            'by_source' => [],
            'by_schedule' => [],
        ];

        foreach ($events as $event) {
            if ($event['timestamp'] < $now) {
                $stats['overdue']++;
            }

            $source = $event['source'];
            $stats['by_source'][$source] = ($stats['by_source'][$source] ?? 0) + 1;

            $schedule = $event['schedule'] ?: 'single';
            $stats['by_schedule'][$schedule] = ($stats['by_schedule'][$schedule] ?? 0) + 1;
        }

        return $stats;
    }

    /**
     * Pause a cron event (store it and remove from schedule).
     */
    public function pause(string $hook, int $timestamp, array $args = []): bool
    {
        $event = $this->getEventByTimestamp($hook, $timestamp, $args);

        if (! $event) {
            return false;
        }

        // Store paused event
        $paused = get_option('wp_queue_paused_crons', []);
        $key = md5($hook.serialize($args));

        $paused[$key] = [
            'hook' => $hook,
            'args' => $args,
            'schedule' => $event['schedule'],
            'interval' => $event['interval'],
            'paused_at' => time(),
        ];

        update_option('wp_queue_paused_crons', $paused);

        // Remove from cron
        wp_clear_scheduled_hook($hook, $args);

        return true;
    }

    /**
     * Resume a paused cron event.
     */
    public function resume(string $hook, array $args = []): bool
    {
        $paused = get_option('wp_queue_paused_crons', []);
        $key = md5($hook.serialize($args));

        if (! isset($paused[$key])) {
            return false;
        }

        $event = $paused[$key];

        // Re-schedule the event
        if ($event['schedule'] && $event['interval']) {
            wp_schedule_event(time(), $event['schedule'], $hook, $args);
        } else {
            wp_schedule_single_event(time() + 60, $hook, $args);
        }

        // Remove from paused
        unset($paused[$key]);
        update_option('wp_queue_paused_crons', $paused);

        return true;
    }

    /**
     * Get all paused cron events.
     *
     * @return array<string, array>
     */
    public function getPaused(): array
    {
        return get_option('wp_queue_paused_crons', []);
    }

    /**
     * Get count of paused events.
     */
    public function getPausedCount(): int
    {
        return count($this->getPaused());
    }

    /**
     * Check if a hook is paused.
     */
    public function isPaused(string $hook, array $args = []): bool
    {
        $paused = $this->getPaused();
        $key = md5($hook.serialize($args));

        return isset($paused[$key]);
    }

    /**
     * Edit/reschedule a cron event.
     */
    public function reschedule(string $hook, int $oldTimestamp, array $args, string $newSchedule, ?int $newTimestamp = null): bool
    {
        // Remove old event
        wp_unschedule_event($oldTimestamp, $hook, $args);

        // Schedule new event
        $schedules = wp_get_schedules();

        if ($newSchedule === 'single' || ! isset($schedules[$newSchedule])) {
            // Single event
            $timestamp = $newTimestamp ?? time() + 60;

            return wp_schedule_single_event($timestamp, $hook, $args) !== false;
        }

        // Recurring event
        $timestamp = $newTimestamp ?? time();

        return wp_schedule_event($timestamp, $newSchedule, $hook, $args) !== false;
    }

    /**
     * Get event by hook, timestamp and args.
     */
    public function getEventByTimestamp(string $hook, int $timestamp, array $args = []): ?array
    {
        $crons = _get_cron_array();

        if (! isset($crons[$timestamp][$hook])) {
            return null;
        }

        $sig = md5(serialize($args));

        if (! isset($crons[$timestamp][$hook][$sig])) {
            return null;
        }

        $data = $crons[$timestamp][$hook][$sig];

        return [
            'hook' => $hook,
            'timestamp' => $timestamp,
            'schedule' => $data['schedule'] ?? false,
            'interval' => $data['interval'] ?? null,
            'args' => $data['args'] ?? [],
        ];
    }
}

<?php

declare(strict_types=1);

namespace WPQueue\Loopback;

use WPQueue\Runtime\RuntimeMode;
use WPQueue\WPQueue;

if (! defined('ABSPATH')) {
    exit;
}

/**
 * Spawns non-blocking loopback requests to trigger immediate queue processing.
 *
 * Modeled after deliciousbrains/wp-background-processing: after a job is pushed
 * we fire a short async POST to admin-ajax.php so the queue starts working
 * straight away instead of waiting for the next WP-Cron tick.
 */
final class LoopbackDispatcher
{
    /**
     * Per-request flag so we only spawn one loopback per queue.
     */
    private static array $spawned = [];

    /**
     * Spawn a loopback request for the given queue.
     */
    public static function spawn(string $queue = 'default'): void
    {
        if (! self::isEnabled()) {
            return;
        }

        if (! function_exists('wp_remote_post')) {
            // WordPress is not fully loaded (e.g. unit tests). Skip loopback.
            return;
        }

        $queue = self::sanitizeQueue($queue);

        if (isset(self::$spawned[$queue])) {
            return;
        }

        self::$spawned[$queue] = true;

        if (WPQueue::isProcessing($queue)) {
            return;
        }

        $url = self::buildUrl($queue);
        $args = self::buildPostArgs();

        $response = wp_remote_post(esc_url_raw($url), $args);

        if (is_wp_error($response)) {
            error_log(sprintf('WP Queue: loopback spawn failed for queue %s: %s', $queue, $response->get_error_message()));
        } elseif (is_array($response) && ($response['response']['code'] ?? 0) >= 400) {
            error_log(sprintf(
                'WP Queue: loopback spawn returned HTTP %s for queue %s',
                $response['response']['code'] ?? 'unknown',
                $queue,
            ));
        }
    }

    /**
     * Build the loopback URL with nonce.
     */
    public static function buildUrl(string $queue): string
    {
        $args = [
            'action' => 'wp_queue_loopback',
            'queue' => self::sanitizeQueue($queue),
            'nonce' => wp_create_nonce(self::nonceAction($queue)),
        ];

        $url = add_query_arg($args, admin_url('admin-ajax.php'));

        /**
         * Filters the loopback URL used to trigger immediate queue processing.
         *
         * @param  string  $url  The loopback URL.
         * @param  string  $queue  Queue name.
         */
        return apply_filters('wp_queue_loopback_url', $url, $queue);
    }

    /**
     * Build the loopback POST arguments.
     */
    public static function buildPostArgs(): array
    {
        $args = [
            'timeout' => 0.01,
            'blocking' => false,
            'sslverify' => apply_filters('https_local_ssl_verify', false),
            'cookies' => $_COOKIE,
            'headers' => [
                'Referer' => home_url(),
            ],
        ];

        /**
         * Filters the POST arguments used for the loopback request.
         *
         * @param  array  $args
         */
        return apply_filters('wp_queue_loopback_post_args', $args);
    }

    /**
     * Nonce verification action name.
     */
    public static function nonceAction(string $queue): string
    {
        return 'wp_queue_loopback_'.$queue;
    }

    /**
     * Sanitize queue name.
     */
    public static function sanitizeQueue(string $queue): string
    {
        return preg_replace('/[^a-z0-9_-]/', '', $queue) ?: 'default';
    }

    /**
     * Whether loopback dispatch is enabled.
     *
     * Disable by defining WP_QUEUE_LOOPBACK as false in wp-config.php.
     * Also disabled automatically when the runtime mode is set to daemon,
     * because a daemon process is already polling the queue.
     */
    public static function isEnabled(): bool
    {
        if (defined('WP_QUEUE_LOOPBACK')) {
            return (bool) WP_QUEUE_LOOPBACK;
        }

        if (! RuntimeMode::useLoopback()) {
            return false;
        }

        /**
         * Filters whether loopback dispatch is enabled.
         *
         * @param  bool  $enabled  Default true.
         */
        return apply_filters('wp_queue_loopback_enabled', true);
    }
}

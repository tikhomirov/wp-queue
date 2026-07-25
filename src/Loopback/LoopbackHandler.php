<?php

declare(strict_types=1);

namespace WPQueue\Loopback;

use WPQueue\WPQueue;

if (! defined('ABSPATH')) {
    exit;
}

/**
 * Handles loopback requests that trigger immediate queue processing.
 *
 * Registered on admin-ajax.php (authenticated and unauthenticated) so a
 * non-blocking wp_remote_post() from LoopbackDispatcher can start the worker
 * without waiting for WP-Cron.
 */
final class LoopbackHandler
{
    public function __construct()
    {
        add_action('wp_ajax_wp_queue_loopback', [$this, 'handle']);
        add_action('wp_ajax_nopriv_wp_queue_loopback', [$this, 'handle']);
    }

    /**
     * Handle the loopback request.
     */
    public function handle(): void
    {
        // Don't lock up other requests while processing.
        session_write_close();

        $queue = LoopbackDispatcher::sanitizeQueue(wp_unslash($_REQUEST['queue'] ?? 'default'));

        if (! $this->verifyNonce($queue)) {
            wp_die('Unauthorized', 'Unauthorized', ['response' => 403]);
        }

        if (WPQueue::isProcessing($queue)) {
            wp_die('Already processing', 'Already processing', ['response' => 200]);
        }

        // Trigger the same handler the cron job uses.
        do_action('wp_queue_process', $queue);

        wp_die('OK', 'OK', ['response' => 200]);
    }

    /**
     * Verify the loopback nonce.
     */
    protected function verifyNonce(string $queue): bool
    {
        $nonce = wp_unslash($_REQUEST['nonce'] ?? '');

        if (empty($nonce)) {
            return false;
        }

        return wp_verify_nonce(sanitize_text_field($nonce), LoopbackDispatcher::nonceAction($queue)) !== false;
    }
}

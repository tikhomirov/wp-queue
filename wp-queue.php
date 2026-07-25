<?php

declare(strict_types=1);

/**
 * Plugin Name: WP Queue
 * Plugin URI: https://github.com/rwsite/wp-queue
 * Description: Background job processing and WP-Cron management for WordPress. Schedule tasks, manage queues, and monitor cron events.
 * Version: 1.2.0
 * Author: Aleksei Tikhomirov
 * Author URI: https://rwsite.ru
 * License: GPL-2.0-or-later
 * Text Domain: wp-queue
 * Domain Path: /languages/
 * Requires PHP: 8.3
 * Requires at least: 6.0
 */
if (! defined('ABSPATH')) {
    exit;
}

// Prevent loading multiple copies of the plugin
if (defined('WP_QUEUE_VERSION')) {
    _doing_it_wrong(__FUNCTION__, 'WP Queue: Multiple copies of the plugin detected. Please deactivate duplicates.', '1.0.0');

    return;
}

define('WP_QUEUE_VERSION', '1.2.0');
define('WP_QUEUE_FILE', __FILE__);
define('WP_QUEUE_PATH', plugin_dir_path(__FILE__));
define('WP_QUEUE_URL', plugin_dir_url(__FILE__));

// Autoload: Composer (dev) or custom PSR-4 (production)
if (file_exists(WP_QUEUE_PATH.'vendor/autoload.php')) {
    require_once WP_QUEUE_PATH.'vendor/autoload.php';
} else {
    spl_autoload_register(static function (string $class): void {
        $prefix = 'WPQueue\\';
        $baseDir = WP_QUEUE_PATH.'src/';

        $len = strlen($prefix);
        if (strncmp($prefix, $class, $len) !== 0) {
            return;
        }

        $relativeClass = substr($class, $len);
        $file = $baseDir.str_replace('\\', '/', $relativeClass).'.php';

        if (file_exists($file)) {
            require_once $file;
        }
    });
}

// Load text domain at after_setup_theme (WordPress 6.7+ requires this or later)
add_action('after_setup_theme', static function (): void {
    load_plugin_textdomain(
        'wp-queue',
        false,
        dirname(plugin_basename(__FILE__)).'/languages/',
    );
}, 1);

// Bootstrap after text domain is loaded
add_action('after_setup_theme', static function (): void {
    \WPQueue\WPQueue::boot();
}, 2);

// Activation
register_activation_hook(__FILE__, static function (): void {
    \WPQueue\WPQueue::activate();
});

// Deactivation
register_deactivation_hook(__FILE__, static function (): void {
    \WPQueue\WPQueue::deactivate();
});

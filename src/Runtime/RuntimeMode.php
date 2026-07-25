<?php

declare(strict_types=1);

namespace WPQueue\Runtime;

final class RuntimeMode
{
    public const MODE_CRON_LOOPBACK = 'cron_loopback';

    public const MODE_DAEMON = 'daemon';

    public const MODE_AUTO = 'auto';

    public static function get(): string
    {
        if (defined('WP_QUEUE_RUNTIME_MODE')) {
            $mode = (string) WP_QUEUE_RUNTIME_MODE;
            if (self::isValid($mode)) {
                return $mode;
            }
        }

        return apply_filters('wp_queue_runtime_mode', self::MODE_CRON_LOOPBACK);
    }

    public static function useLoopback(): bool
    {
        return self::resolveMode() === self::MODE_CRON_LOOPBACK;
    }

    /**
     * Whether WP-Cron scheduling should be used.
     */
    public static function useCron(): bool
    {
        return self::resolveMode() !== self::MODE_DAEMON;
    }

    /**
     * Resolve the effective runtime mode.
     */
    public static function resolveMode(): string
    {
        $mode = self::get();

        if ($mode === self::MODE_AUTO) {
            $mode = self::resolveAuto();
        }

        return $mode;
    }

    public static function isDaemonProcess(): bool
    {
        if (defined('WP_QUEUE_DAEMON') && WP_QUEUE_DAEMON) {
            return true;
        }
        if (getenv('WP_QUEUE_DAEMON') === '1') {
            return true;
        }

        return false;
    }

    public static function resolveAuto(): string
    {
        return self::isDaemonProcess() ? self::MODE_DAEMON : self::MODE_CRON_LOOPBACK;
    }

    public static function isValid(string $mode): bool
    {
        return in_array($mode, [self::MODE_CRON_LOOPBACK, self::MODE_DAEMON, self::MODE_AUTO], true);
    }
}

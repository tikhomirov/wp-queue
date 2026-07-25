<?php

declare(strict_types=1);

namespace WPQueue\Admin;

use WP_Error;
use WP_REST_Request;
use WP_REST_Response;
use WPQueue\WPQueue;

if (! defined('ABSPATH')) {
    exit;
}

class RestApi
{
    public function __construct()
    {
        add_action('rest_api_init', [$this, 'registerRoutes']);
    }

    public function registerRoutes(): void
    {
        $namespace = 'wp-queue/v1';

        register_rest_route($namespace, '/queues', [
            'methods' => 'GET',
            'callback' => [$this, 'getQueues'],
            'permission_callback' => [$this, 'checkPermission'],
        ]);

        register_rest_route($namespace, '/queues/(?P<queue>[a-z0-9_-]+)', [
            'methods' => 'GET',
            'callback' => [$this, 'getQueue'],
            'permission_callback' => [$this, 'checkPermission'],
        ]);

        register_rest_route($namespace, '/queues/(?P<queue>[a-z0-9_-]+)/jobs', [
            'methods' => 'GET',
            'callback' => [$this, 'getQueueJobs'],
            'permission_callback' => [$this, 'checkPermission'],
        ]);

        register_rest_route($namespace, '/queues/(?P<queue>[a-z0-9_-]+)/process', [
            'methods' => 'POST',
            'callback' => [$this, 'processQueue'],
            'permission_callback' => [$this, 'checkPermission'],
        ]);

        register_rest_route($namespace, '/queues/(?P<queue>[a-z0-9_-]+)/pause', [
            'methods' => 'POST',
            'callback' => [$this, 'pauseQueue'],
            'permission_callback' => [$this, 'checkPermission'],
        ]);

        register_rest_route($namespace, '/queues/(?P<queue>[a-z0-9_-]+)/resume', [
            'methods' => 'POST',
            'callback' => [$this, 'resumeQueue'],
            'permission_callback' => [$this, 'checkPermission'],
        ]);

        register_rest_route($namespace, '/queues/(?P<queue>[a-z0-9_-]+)/clear', [
            'methods' => 'POST',
            'callback' => [$this, 'clearQueue'],
            'permission_callback' => [$this, 'checkPermission'],
        ]);

        register_rest_route($namespace, '/jobs/(?P<job>[^/]+)/run', [
            'methods' => 'POST',
            'callback' => [$this, 'runJob'],
            'permission_callback' => [$this, 'checkPermission'],
        ]);

        register_rest_route($namespace, '/jobs/(?P<job>[^/]+)', [
            'methods' => 'GET',
            'callback' => [$this, 'getJob'],
            'permission_callback' => [$this, 'checkPermission'],
        ]);

        register_rest_route($namespace, '/jobs/(?P<job>[^/]+)/delete', [
            'methods' => 'POST',
            'callback' => [$this, 'deleteJob'],
            'permission_callback' => [$this, 'checkPermission'],
        ]);

        register_rest_route($namespace, '/stats', [
            'methods' => 'GET',
            'callback' => [$this, 'getStats'],
            'permission_callback' => [$this, 'checkPermission'],
        ]);

        register_rest_route($namespace, '/logs', [
            'methods' => 'GET',
            'callback' => [$this, 'getLogs'],
            'permission_callback' => [$this, 'checkPermission'],
        ]);

        register_rest_route($namespace, '/logs/clear', [
            'methods' => 'POST',
            'callback' => [$this, 'clearLogs'],
            'permission_callback' => [$this, 'checkPermission'],
        ]);

        register_rest_route($namespace, '/queues/clear-all', [
            'methods' => 'POST',
            'callback' => [$this, 'clearAllQueues'],
            'permission_callback' => [$this, 'checkPermission'],
        ]);

        register_rest_route($namespace, '/metrics', [
            'methods' => 'GET',
            'callback' => [$this, 'getMetrics'],
            'permission_callback' => [$this, 'checkPermission'],
        ]);

        // Cron endpoints
        register_rest_route($namespace, '/cron', [
            'methods' => 'GET',
            'callback' => [$this, 'getCronEvents'],
            'permission_callback' => [$this, 'checkPermission'],
        ]);

        register_rest_route($namespace, '/cron/run', [
            'methods' => 'POST',
            'callback' => [$this, 'runCronEvent'],
            'permission_callback' => [$this, 'checkPermission'],
        ]);

        register_rest_route($namespace, '/cron/delete', [
            'methods' => 'POST',
            'callback' => [$this, 'deleteCronEvent'],
            'permission_callback' => [$this, 'checkPermission'],
        ]);

        register_rest_route($namespace, '/cron/pause', [
            'methods' => 'POST',
            'callback' => [$this, 'pauseCronEvent'],
            'permission_callback' => [$this, 'checkPermission'],
        ]);

        register_rest_route($namespace, '/cron/resume', [
            'methods' => 'POST',
            'callback' => [$this, 'resumeCronEvent'],
            'permission_callback' => [$this, 'checkPermission'],
        ]);

        register_rest_route($namespace, '/cron/edit', [
            'methods' => 'POST',
            'callback' => [$this, 'editCronEvent'],
            'permission_callback' => [$this, 'checkPermission'],
        ]);

        register_rest_route($namespace, '/cron/paused', [
            'methods' => 'GET',
            'callback' => [$this, 'getPausedCronEvents'],
            'permission_callback' => [$this, 'checkPermission'],
        ]);

        register_rest_route($namespace, '/system', [
            'methods' => 'GET',
            'callback' => [$this, 'getSystemStatus'],
            'permission_callback' => [$this, 'checkPermission'],
        ]);
    }

    public function checkPermission(): bool
    {
        return current_user_can('manage_options');
    }

    public function getQueues(): WP_REST_Response
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

            $queues[$queueName] = [
                'size' => count($jobs),
                'paused' => WPQueue::isPaused($queueName),
                'processing' => WPQueue::isProcessing($queueName),
            ];
        }

        if (! isset($queues['default'])) {
            $queues['default'] = [
                'size' => 0,
                'paused' => WPQueue::isPaused('default'),
                'processing' => WPQueue::isProcessing('default'),
            ];
        }

        return new WP_REST_Response($queues);
    }

    public function getQueue(WP_REST_Request $request): WP_REST_Response|WP_Error
    {
        $queueName = $request->get_param('queue');
        $optionName = 'wp_queue_jobs_'.$queueName;

        // Проверяем существование очереди
        $jobs = get_site_option($optionName, null);

        // Очередь существует если есть задачи или это очередь default
        if ($jobs === null && $queueName !== 'default') {
            return new WP_Error('queue_not_found', 'Queue not found', ['status' => 404]);
        }

        $jobs = $jobs ?? [];

        return new WP_REST_Response([
            'name' => $queueName,
            'size' => count($jobs),
            'status' => WPQueue::isPaused($queueName) ? 'paused' : 'active',
            'processing' => WPQueue::isProcessing($queueName),
        ]);
    }

    public function getQueueJobs(WP_REST_Request $request): WP_REST_Response
    {
        $queueName = $request->get_param('queue');
        $jobs = get_site_option('wp_queue_jobs_'.$queueName, []);

        $result = [];
        foreach ($jobs as $index => $job) {
            // Поддержка как объектов, так и массивов
            if (is_object($job)) {
                $result[] = [
                    'id' => $index,
                    'class' => get_class($job),
                    'attempts' => $job->attempts ?? 0,
                    'available_at' => $job->availableAt ?? time(),
                ];
            } else {
                $result[] = [
                    'id' => $index,
                    'class' => $job['class'] ?? 'Unknown',
                    'attempts' => $job['attempts'] ?? 0,
                    'available_at' => $job['available_at'] ?? time(),
                ];
            }
        }

        return new WP_REST_Response($result);
    }

    public function processQueue(WP_REST_Request $request): WP_REST_Response
    {
        $queueName = $request->get_param('queue');
        $maxJobs = (int) ($request->get_param('max_jobs') ?? 10);

        $worker = WPQueue::worker();
        $worker->setMaxJobs($maxJobs);

        $processed = 0;
        while ($processed < $maxJobs && $worker->runNextJob($queueName)) {
            $processed++;
        }

        return new WP_REST_Response([
            'success' => true,
            'processed' => $processed,
        ]);
    }

    public function getStats(): WP_REST_Response
    {
        global $wpdb;

        $queues = [];
        $totalJobs = 0;

        $results = $wpdb->get_col(
            $wpdb->prepare(
                "SELECT option_name FROM {$wpdb->options} WHERE option_name LIKE %s",
                'wp_queue_jobs_%',
            ),
        );

        foreach ($results as $optionName) {
            $jobs = get_site_option($optionName, []);
            $totalJobs += count($jobs);
        }

        // Получаем статистику из логов
        $logs = WPQueue::logs();
        $metrics = $logs->metrics();

        return new WP_REST_Response([
            'total_queues' => count($results) ?: 1,
            'total_jobs' => $totalJobs,
            'total_processed' => $metrics['total'] ?? 0,
            'total_failed' => $metrics['failed'] ?? 0,
            'total_completed' => $metrics['completed'] ?? 0,
        ]);
    }

    public function pauseQueue(WP_REST_Request $request): WP_REST_Response
    {
        $queue = $request->get_param('queue');
        WPQueue::pause($queue);

        return new WP_REST_Response(['success' => true, 'message' => 'Queue paused']);
    }

    public function resumeQueue(WP_REST_Request $request): WP_REST_Response
    {
        $queue = $request->get_param('queue');
        WPQueue::resume($queue);

        return new WP_REST_Response(['success' => true, 'message' => 'Queue resumed']);
    }

    public function clearQueue(WP_REST_Request $request): WP_REST_Response
    {
        $queue = $request->get_param('queue');
        $cleared = WPQueue::clear($queue);

        return new WP_REST_Response(['success' => true, 'cleared' => $cleared]);
    }

    public function runJob(WP_REST_Request $request): WP_REST_Response|WP_Error
    {
        $jobClass = urldecode($request->get_param('job'));

        if (! class_exists($jobClass) || ! is_subclass_of($jobClass, \WPQueue\Jobs\Job::class)) {
            return new WP_Error('invalid_job', 'Job class not found', ['status' => 404]);
        }

        try {
            $job = new $jobClass();
            WPQueue::dispatch($job);

            return new WP_REST_Response(['success' => true, 'message' => 'Job dispatched']);
        } catch (\Throwable $e) {
            return new WP_Error('dispatch_failed', $e->getMessage(), ['status' => 500]);
        }
    }

    public function getJob(WP_REST_Request $request): WP_REST_Response|WP_Error
    {
        $jobId = urldecode($request->get_param('job'));
        $queueName = $request->get_param('queue') ?? 'default';

        $jobs = get_site_option('wp_queue_jobs_'.$queueName, []);

        if (! isset($jobs[$jobId])) {
            return new WP_Error('job_not_found', 'Job not found', ['status' => 404]);
        }

        $data = $jobs[$jobId];
        $result = [
            'id' => $jobId,
            'queue' => $queueName,
            'attempts' => $data['attempts'] ?? 0,
            'available_at' => $data['available_at'] ?? 0,
            'reserved_at' => $data['reserved_at'] ?? null,
            'class' => 'Unknown',
            'payload' => null,
        ];

        // Попробуем десериализовать payload
        if (isset($data['payload'])) {
            try {
                $job = @unserialize($data['payload']);
                if (is_object($job)) {
                    $result['class'] = get_class($job);
                    if (method_exists($job, 'toArray')) {
                        $result['payload'] = $job->toArray();
                    }
                }
            } catch (\Throwable $e) {
                // Игнорируем ошибки десериализации
            }
        }

        return new WP_REST_Response($result);
    }

    public function deleteJob(WP_REST_Request $request): WP_REST_Response|WP_Error
    {
        $jobId = urldecode($request->get_param('job'));
        $queueName = $request->get_param('queue') ?? 'default';

        $jobs = get_site_option('wp_queue_jobs_'.$queueName, []);

        if (! isset($jobs[$jobId])) {
            return new WP_Error('job_not_found', 'Job not found', ['status' => 404]);
        }

        unset($jobs[$jobId]);

        if (empty($jobs)) {
            delete_site_option('wp_queue_jobs_'.$queueName);
        } else {
            update_site_option('wp_queue_jobs_'.$queueName, $jobs);
        }

        return new WP_REST_Response(['success' => true, 'message' => 'Job deleted']);
    }

    public function getLogs(WP_REST_Request $request): WP_REST_Response
    {
        $filter = $request->get_param('filter') ?? $request->get_param('status') ?? 'all';
        $limit = (int) ($request->get_param('limit') ?? 100);

        $logs = match ($filter) {
            'failed' => WPQueue::logs()->failed(),
            'completed' => WPQueue::logs()->completed(),
            default => WPQueue::logs()->recent($limit),
        };

        return new WP_REST_Response($logs);
    }

    public function clearLogs(WP_REST_Request $request): WP_REST_Response
    {
        $all = $request->get_param('all');

        if ($all) {
            $cleared = WPQueue::logs()->clear();
        } else {
            $days = (int) ($request->get_param('days') ?? 7);
            $cleared = WPQueue::logs()->clearOld($days);
        }

        return new WP_REST_Response(['success' => true, 'cleared' => $cleared]);
    }

    public function clearAllQueues(): WP_REST_Response
    {
        global $wpdb;

        // Находим все опции с джобами
        $results = $wpdb->get_col(
            "SELECT option_name FROM {$wpdb->options} WHERE option_name LIKE 'wp_queue_jobs_%'",
        );

        $cleared = 0;
        foreach ($results as $optionName) {
            $queueName = str_replace('wp_queue_jobs_', '', $optionName);
            WPQueue::clear($queueName);
            $cleared++;
        }

        return new WP_REST_Response(['success' => true, 'cleared' => $cleared]);
    }

    public function getMetrics(): WP_REST_Response
    {
        return new WP_REST_Response(WPQueue::logs()->metrics());
    }

    public function getCronEvents(): WP_REST_Response
    {
        $monitor = new CronMonitor();

        return new WP_REST_Response([
            'events' => $monitor->getAllEvents(),
            'stats' => $monitor->getStats(),
            'schedules' => $monitor->getSchedules(),
        ]);
    }

    public function runCronEvent(WP_REST_Request $request): WP_REST_Response
    {
        $hook = $request->get_param('hook');
        $args = $request->get_param('args') ?? [];

        if (is_string($args)) {
            $args = json_decode($args, true) ?? [];
        }

        $monitor = new CronMonitor();
        $monitor->runNow($hook, $args);

        return new WP_REST_Response(['success' => true, 'message' => 'Cron event executed']);
    }

    public function deleteCronEvent(WP_REST_Request $request): WP_REST_Response
    {
        $hook = $request->get_param('hook');
        $timestamp = (int) $request->get_param('timestamp');
        $args = $request->get_param('args') ?? [];

        if (is_string($args)) {
            $args = json_decode($args, true) ?? [];
        }

        $monitor = new CronMonitor();
        $result = $monitor->unschedule($hook, $timestamp, $args);

        return new WP_REST_Response(['success' => $result]);
    }

    public function getSystemStatus(): WP_REST_Response
    {
        $status = new SystemStatus();

        return new WP_REST_Response([
            'report' => $status->getFullReport(),
            'health' => $status->getHealthStatus(),
        ]);
    }

    public function pauseCronEvent(WP_REST_Request $request): WP_REST_Response
    {
        $hook = $request->get_param('hook');
        $timestamp = (int) $request->get_param('timestamp');
        $args = $this->parseArgs($request->get_param('args'));

        $monitor = new CronMonitor();
        $result = $monitor->pause($hook, $timestamp, $args);

        return new WP_REST_Response([
            'success' => $result,
            'message' => $result ? __('Cron event paused', 'wp-queue') : __('Failed to pause event', 'wp-queue'),
        ]);
    }

    public function resumeCronEvent(WP_REST_Request $request): WP_REST_Response
    {
        $hook = $request->get_param('hook');
        $args = $this->parseArgs($request->get_param('args'));

        $monitor = new CronMonitor();
        $result = $monitor->resume($hook, $args);

        return new WP_REST_Response([
            'success' => $result,
            'message' => $result ? __('Cron event resumed', 'wp-queue') : __('Failed to resume event', 'wp-queue'),
        ]);
    }

    public function editCronEvent(WP_REST_Request $request): WP_REST_Response
    {
        $hook = $request->get_param('hook');
        $timestamp = (int) $request->get_param('timestamp');
        $args = $this->parseArgs($request->get_param('args'));
        $newSchedule = $request->get_param('schedule');
        $newTimestamp = $request->get_param('new_timestamp') ? (int) $request->get_param('new_timestamp') : null;

        $monitor = new CronMonitor();
        $result = $monitor->reschedule($hook, $timestamp, $args, $newSchedule, $newTimestamp);

        return new WP_REST_Response([
            'success' => $result,
            'message' => $result ? __('Cron event updated', 'wp-queue') : __('Failed to update event', 'wp-queue'),
        ]);
    }

    public function getPausedCronEvents(): WP_REST_Response
    {
        $monitor = new CronMonitor();

        return new WP_REST_Response($monitor->getPaused());
    }

    protected function parseArgs(mixed $args): array
    {
        if (is_string($args)) {
            return json_decode($args, true) ?? [];
        }

        return is_array($args) ? $args : [];
    }
}

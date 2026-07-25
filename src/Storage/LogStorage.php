<?php

declare(strict_types=1);

namespace WPQueue\Storage;

use WPQueue\Contracts\JobInterface;
use WPQueue\WPQueue;

if (! defined('ABSPATH')) {
    exit;
}

class LogStorage
{
    /**
     * Log a job event.
     */
    public function log(string $status, JobInterface $job, ?string $message = null): void
    {
        global $wpdb;

        $table = $this->getTableName();

        $data = [
            'job_id' => (string) $job->getId(),
            'job_class' => $job::class,
            'queue' => (string) $job->getQueue(),
            'status' => $status,
            'message' => $message,
            'attempts' => (int) $job->getAttempts(),
            'created_at' => gmdate('Y-m-d H:i:s'),
        ];

        $formats = ['%s', '%s', '%s', '%s', '%s', '%d', '%s'];

        try {
            $result = $wpdb->insert($table, $data, $formats);

            if ($result === false && $this->isMissingTableError($wpdb->last_error)) {
                WPQueue::install();
                $wpdb->insert($this->getTableName(), $data, $formats);
            }
        } catch (\Throwable $e) {
            if ($this->isMissingTableError($e->getMessage())) {
                WPQueue::install();
                $wpdb->insert($this->getTableName(), $data, $formats);
            }
        }
    }

    /**
     * Get all logs.
     *
     * @return array<int, array{id: string, job_id: string, job_class: string, queue: string, status: string, message: ?string, attempts: int, timestamp: int}>
     */
    public function all(): array
    {
        global $wpdb;

        $table = $this->getTableName();

        $sql = $wpdb->prepare('SELECT * FROM %i ORDER BY created_at ASC', $table);
        $rows = $this->getResults($sql);

        if ($this->isMissingTableError($wpdb->last_error)) {
            WPQueue::install();
            $rows = $this->getResults($sql);
        }

        return array_map(static function (array $row): array {
            return [
                'id' => (string) ($row['id'] ?? ''),
                'job_id' => (string) ($row['job_id'] ?? ''),
                'job_class' => (string) ($row['job_class'] ?? ''),
                'queue' => (string) ($row['queue'] ?? ''),
                'status' => (string) ($row['status'] ?? ''),
                'message' => $row['message'] ?? null,
                'attempts' => isset($row['attempts']) ? (int) $row['attempts'] : 0,
                'timestamp' => isset($row['created_at']) ? strtotime((string) $row['created_at']) : time(),
            ];
        }, $rows);
    }

    /**
     * Get recent logs.
     *
     * @return array<int, array{id: string, job_id: string, job_class: string, queue: string, status: string, message: ?string, attempts: int, timestamp: int}>
     */
    public function recent(int $limit = 100): array
    {
        $logs = $this->all();

        return array_slice(array_reverse($logs), 0, $limit);
    }

    /**
     * Get logs for a specific job class.
     *
     * @param  class-string  $jobClass
     * @return array<int, array{id: string, job_id: string, job_class: string, queue: string, status: string, message: ?string, attempts: int, timestamp: int}>
     */
    public function forJob(string $jobClass): array
    {
        return array_filter($this->all(), fn ($log) => $log['job_class'] === $jobClass);
    }

    /**
     * Get failed logs.
     *
     * @return array<int, array{id: string, job_id: string, job_class: string, queue: string, status: string, message: ?string, attempts: int, timestamp: int}>
     */
    public function failed(): array
    {
        global $wpdb;

        $table = $this->getTableName();

        $sql = $wpdb->prepare('SELECT * FROM %i WHERE status = %s ORDER BY created_at DESC', $table, 'failed');
        $rows = $this->getResults($sql);

        if ($this->isMissingTableError($wpdb->last_error)) {
            WPQueue::install();
            $rows = $this->getResults($sql);
        }

        return array_map(static function (array $row): array {
            return [
                'id' => (string) ($row['id'] ?? ''),
                'job_id' => (string) ($row['job_id'] ?? ''),
                'job_class' => (string) ($row['job_class'] ?? ''),
                'queue' => (string) ($row['queue'] ?? ''),
                'status' => (string) ($row['status'] ?? ''),
                'message' => $row['message'] ?? null,
                'attempts' => isset($row['attempts']) ? (int) $row['attempts'] : 0,
                'timestamp' => isset($row['created_at']) ? strtotime((string) $row['created_at']) : time(),
            ];
        }, $rows);
    }

    /**
     * Get completed logs.
     *
     * @return array<int, array{id: string, job_id: string, job_class: string, queue: string, status: string, message: ?string, attempts: int, timestamp: int}>
     */
    public function completed(): array
    {
        global $wpdb;

        $table = $this->getTableName();

        $sql = $wpdb->prepare('SELECT * FROM %i WHERE status = %s ORDER BY created_at DESC', $table, 'completed');
        $rows = $this->getResults($sql);

        if ($this->isMissingTableError($wpdb->last_error)) {
            WPQueue::install();
            $rows = $this->getResults($sql);
        }

        return array_map(static function (array $row): array {
            return [
                'id' => (string) ($row['id'] ?? ''),
                'job_id' => (string) ($row['job_id'] ?? ''),
                'job_class' => (string) ($row['job_class'] ?? ''),
                'queue' => (string) ($row['queue'] ?? ''),
                'status' => (string) ($row['status'] ?? ''),
                'message' => $row['message'] ?? null,
                'attempts' => isset($row['attempts']) ? (int) $row['attempts'] : 0,
                'timestamp' => isset($row['created_at']) ? strtotime((string) $row['created_at']) : time(),
            ];
        }, $rows);
    }

    /**
     * Clear old logs.
     */
    public function clearOld(int $daysOld = 7): int
    {
        global $wpdb;

        $table = $this->getTableName();
        $cutoff = gmdate('Y-m-d H:i:s', time() - ($daysOld * DAY_IN_SECONDS));

        $sql = $wpdb->prepare('DELETE FROM %i WHERE created_at < %s', $table, $cutoff);
        $this->executeQuery($sql);
        $deleted = $wpdb->rows_affected;

        if ($this->isMissingTableError($wpdb->last_error)) {
            WPQueue::install();
            $this->executeQuery($sql);
            $deleted = $wpdb->rows_affected;
        }

        return (int) $deleted;
    }

    /**
     * Clear all logs.
     */
    public function clear(): void
    {
        global $wpdb;

        $table = $this->getTableName();
        $sql = $wpdb->prepare('TRUNCATE TABLE %i', $table);
        $this->executeQuery($sql);

        if ($this->isMissingTableError($wpdb->last_error)) {
            WPQueue::install();
            $this->executeQuery($sql);
        }
    }

    /**
     * Get metrics.
     *
     * @return array{total: int, completed: int, failed: int, by_queue: array<string, int>, by_job: array<string, int>}
     */
    public function metrics(): array
    {
        global $wpdb;

        $table = $this->getTableName();

        $sql = $wpdb->prepare('SELECT queue, job_class, status FROM %i', $table);
        $rows = $this->getResults($sql);

        if ($this->isMissingTableError($wpdb->last_error)) {
            WPQueue::install();
            $rows = $this->getResults($sql);
        }

        $metrics = [
            'total' => count($rows),
            'completed' => 0,
            'failed' => 0,
            'by_queue' => [],
            'by_job' => [],
        ];

        foreach ($rows as $row) {
            $status = (string) ($row['status'] ?? '');
            $queue = (string) ($row['queue'] ?? '');
            $jobClass = (string) ($row['job_class'] ?? '');

            if ($status === 'completed') {
                $metrics['completed']++;
            } elseif ($status === 'failed') {
                $metrics['failed']++;
            }

            if ($queue !== '') {
                $metrics['by_queue'][$queue] = ($metrics['by_queue'][$queue] ?? 0) + 1;
            }

            if ($jobClass !== '') {
                $metrics['by_job'][$jobClass] = ($metrics['by_job'][$jobClass] ?? 0) + 1;
            }
        }

        return $metrics;
    }

    /**
     * Execute a read query against the logs table.
     *
     * @return array<int, array<string, mixed>>
     */
    protected function getResults(string $sql): array
    {
        global $wpdb;

        // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared,WordPress.DB.DirectDatabaseQuery.NoCaching
        return $wpdb->get_results($sql, ARRAY_A) ?: [];
    }

    /**
     * Execute a write query against the logs table.
     */
    protected function executeQuery(string $sql): void
    {
        global $wpdb;

        // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared,WordPress.DB.DirectDatabaseQuery.NoCaching
        $wpdb->query($sql);
    }

    protected function getTableName(): string
    {
        global $wpdb;

        return $wpdb->prefix.'queue_logs';
    }

    protected function isMissingTableError(?string $message): bool
    {
        if (! $message) {
            return false;
        }

        $message = strtolower($message);

        return str_contains($message, 'no such table')
            || str_contains($message, 'doesn\'t exist')
            || str_contains($message, 'does not exist');
    }
}

<?php

declare(strict_types=1);

namespace WPQueue\Admin;

use WPQueue\Loopback\LoopbackDispatcher;
use WPQueue\Runtime\RuntimeMode;
use WPQueue\WPQueue;

/**
 * Admin Page with Rank Math style UI
 * 3 main tabs: Queues, Scheduler, System
 */
class AdminPage
{
    /** @var array<string, array<string, string>> */
    private array $tabs = [];

    /** @var array<string, array<string, array{title: string, icon: string}>> */
    private array $sections = [];

    private const JOBS_PER_PAGE = 20;

    private const LOGS_PER_PAGE = 50;

    public function __construct()
    {
        add_action('init', [$this, 'initTabs'], 5);
        add_action('admin_menu', [$this, 'addMenuPage']);
        add_action('admin_enqueue_scripts', [$this, 'enqueueAssets']);
    }

    public function initTabs(): void
    {
        $this->tabs = [
            'queues' => ['title' => __('Queues', 'wp-queue'), 'icon' => 'dashicons-database'],
            'scheduler' => ['title' => __('Scheduler', 'wp-queue'), 'icon' => 'dashicons-clock'],
            'system' => ['title' => __('System', 'wp-queue'), 'icon' => 'dashicons-admin-tools'],
        ];

        $this->sections = [
            'queues' => [
                'overview' => ['title' => __('Overview', 'wp-queue'), 'icon' => 'dashicons-chart-bar'],
                'jobs' => ['title' => __('Jobs in Queue', 'wp-queue'), 'icon' => 'dashicons-list-view'],
                'history' => ['title' => __('History', 'wp-queue'), 'icon' => 'dashicons-backup'],
                'failed' => ['title' => __('Failed', 'wp-queue'), 'icon' => 'dashicons-dismiss'],
                'drivers' => ['title' => __('Drivers', 'wp-queue'), 'icon' => 'dashicons-admin-generic'],
            ],
            'scheduler' => [
                'overview' => ['title' => __('Overview', 'wp-queue'), 'icon' => 'dashicons-chart-bar'],
                'events' => ['title' => __('Cron Events', 'wp-queue'), 'icon' => 'dashicons-calendar-alt'],
                'scheduled' => ['title' => __('Scheduled', 'wp-queue'), 'icon' => 'dashicons-clock'],
                'paused' => ['title' => __('Paused', 'wp-queue'), 'icon' => 'dashicons-controls-pause'],
            ],
            'system' => [
                'status' => ['title' => __('System Status', 'wp-queue'), 'icon' => 'dashicons-heart'],
                'daemon' => ['title' => __('Daemon worker', 'wp-queue'), 'icon' => 'dashicons-admin-generic'],
                'tools' => ['title' => __('Tools', 'wp-queue'), 'icon' => 'dashicons-admin-tools'],
                'help' => ['title' => __('Help', 'wp-queue'), 'icon' => 'dashicons-editor-help'],
            ],
        ];
    }

    public function addMenuPage(): void
    {
        add_menu_page(
            __('WP Queue', 'wp-queue'),
            __('WP Queue', 'wp-queue'),
            'manage_options',
            'wp-queue',
            [$this, 'renderPage'],
            'dashicons-database',
            80,
        );
    }

    public function addHelpTabs(): void
    {
        $screen = get_current_screen();

        if (! $screen) {
            return;
        }

        $screen->add_help_tab([
            'id' => 'wp-queue-about',
            'title' => __('About WP Queue', 'wp-queue'),
            'content' => $this->getHelpTabAbout(),
        ]);

        $screen->add_help_tab([
            'id' => 'wp-queue-usage',
            'title' => __('Usage', 'wp-queue'),
            'content' => $this->getHelpTabUsage(),
        ]);

        $screen->add_help_tab([
            'id' => 'wp-queue-cli',
            'title' => __('WP-CLI', 'wp-queue'),
            'content' => $this->getHelpTabCli(),
        ]);

        $screen->set_help_sidebar($this->getHelpSidebar());
    }

    protected function getHelpTabAbout(): string
    {
        return '<h2>'.__('About WP Queue', 'wp-queue').' '.WP_QUEUE_VERSION.'</h2>'.
            '<p>'.__('WP Queue helps your site run heavy or regular tasks in the background (emails, synchronizations, cleanups) so that pages load faster for visitors.', 'wp-queue').'</p>'.
            '<p>'.__('The plugin itself does not add new features to the frontend. It provides a reliable queue that other plugins or your custom code can use for background work.', 'wp-queue').'</p>'.
            '<h3>'.__('What you can use it for', 'wp-queue').'</h3>'.
            '<ul>'.
            '<li>'.__('Monitor which background jobs are queued and running right now.', 'wp-queue').'</li>'.
            '<li>'.__('Quickly see if any jobs are failing and view recent log entries.', 'wp-queue').'</li>'.
            '<li>'.__('Inspect and manage WP-Cron events for WP Queue and other plugins.', 'wp-queue').'</li>'.
            '<li>'.__('Check basic system requirements that affect queue stability.', 'wp-queue').'</li>'.
            '</ul>'.
            '<h3>'.__('For developers', 'wp-queue').'</h3>'.
            '<p>'.__('Developers can dispatch their own jobs to the queue, choose drivers (Database, Sync, Redis), configure retries and schedules. See the documentation and examples on GitHub for integration details.', 'wp-queue').'</p>';
    }

    protected function getHelpTabUsage(): string
    {
        return '<h2>'.__('Basic Usage', 'wp-queue').'</h2>'.
            '<h3>'.__('For site administrators', 'wp-queue').'</h3>'.
            '<ul>'.
            '<li>'.__('Dashboard – quick overview of queues, number of jobs and recent activity.', 'wp-queue').'</li>'.
            '<li>'.__('Scheduled Jobs – list of recurring background jobs added by your theme or plugins.', 'wp-queue').'</li>'.
            '<li>'.__('Logs – history of completed and failed jobs that helps to diagnose problems.', 'wp-queue').'</li>'.
            '<li>'.__('WP-Cron – list of cron events with the ability to run, pause or delete them.', 'wp-queue').'</li>'.
            '<li>'.__('System – checks PHP version, WP-Cron status and other important settings.', 'wp-queue').'</li>'.
            '</ul>'.
            '<p>'.__('If you installed WP Queue because another plugin requires it, you usually do not need to configure anything here. Use these tabs mainly for monitoring and troubleshooting.', 'wp-queue').'</p>'.
            '<h3>'.__('For developers', 'wp-queue').'</h3>'.
            '<p>'.sprintf(
                /* translators: %s: URL to GitHub repository */
                __('To add your own background jobs, use the WP Queue PHP API from your plugin or theme. You can dispatch jobs to different queues, set delays and retries. Full code examples are available in the <a href="%s" target="_blank">README on GitHub</a>.', 'wp-queue'),
                esc_url('https://github.com/rwsite/wp-queue'),
            ).'</p>';
    }

    protected function getHelpTabCli(): string
    {
        return '<h2>'.__('WP-CLI Commands', 'wp-queue').'</h2>'.
            '<p>'.__('Run', 'wp-queue').' <code>wp help queue</code> '.__('to get a list of available commands.', 'wp-queue').'</p>'.
            '<pre><code># Queue commands
wp queue status              # Show queue status
wp queue work [queue]        # Process jobs from queue
wp queue clear [queue]       # Clear all jobs from queue
wp queue failed              # List failed jobs
wp queue retry [job_id]      # Retry a failed job

# Cron commands
wp queue cron list           # List all cron events
wp queue cron run &lt;hook&gt;     # Run a cron event now
wp queue cron delete &lt;hook&gt;  # Delete a cron event
wp queue cron pause &lt;hook&gt;   # Pause a cron event
wp queue cron resume &lt;hook&gt;  # Resume a paused event

# System commands
wp queue system              # Show system status</code></pre>';
    }

    protected function getHelpSidebar(): string
    {
        return '<p><strong>'.__('For more information:', 'wp-queue').'</strong></p>'.
            '<p><a href="https://github.com/rwsite/wp-queue" target="_blank">'.__('GitHub Repository', 'wp-queue').'</a></p>'.
            '<p><a href="https://github.com/rwsite/wp-queue/issues" target="_blank">'.__('Report Issues', 'wp-queue').'</a></p>'.
            '<p><a href="https://rwsite.ru" target="_blank">'.__('Author Website', 'wp-queue').'</a></p>';
    }

    public function enqueueAssets(string $hook): void
    {
        if ($hook !== 'toplevel_page_wp-queue') {
            return;
        }

        wp_enqueue_style(
            'wp-queue-admin',
            WP_QUEUE_URL.'assets/css/admin.css',
            [],
            WP_QUEUE_VERSION,
        );

        wp_enqueue_script(
            'wp-queue-admin',
            WP_QUEUE_URL.'assets/js/admin.js',
            ['jquery'],
            WP_QUEUE_VERSION,
            true,
        );

        wp_localize_script('wp-queue-admin', 'wpQueue', [
            'ajaxUrl' => admin_url('admin-ajax.php'),
            'restUrl' => rest_url('wp-queue/v1'),
            'nonce' => wp_create_nonce('wp_rest'),
            'i18n' => [
                'confirm_clear' => __('Are you sure you want to clear this queue?', 'wp-queue'),
                'confirm_pause' => __('Pause this queue?', 'wp-queue'),
                'success' => __('Action completed successfully', 'wp-queue'),
                'error' => __('An error occurred', 'wp-queue'),
            ],
        ]);
    }

    public function renderPage(): void
    {
        $tab = sanitize_key($_GET['tab'] ?? 'queues');
        $section = sanitize_key($_GET['section'] ?? '');

        if (! isset($this->tabs[$tab])) {
            $tab = 'queues';
        }

        // Дефолтная секция для каждой вкладки
        if (empty($section) || ! isset($this->sections[$tab][$section])) {
            $section = array_key_first($this->sections[$tab]);
        }

        // Проверка на детальный просмотр очереди
        $queueView = sanitize_key($_GET['queue'] ?? '');
        $jobView = sanitize_key($_GET['job'] ?? '');

        ?>
        <div class="wrap wp-queue-wrap">
            <?php $this->renderHeader($tab, $section, $queueView); ?>
            <?php $this->renderTabs($tab); ?>

            <div class="wp-queue-container">
                <?php $this->renderSidebar($tab, $section); ?>
                <div class="wp-queue-main">
                    <?php
                            if ($queueView && $tab === 'queues') {
                                $this->renderQueueDetail($queueView, $jobView);
                            } else {
                                $this->renderSectionContent($tab, $section);
                            }
        ?>
                </div>
            </div>
        </div>
    <?php
    }

    private function renderHeader(string $tab, string $section, string $queueView = ''): void
    {
        $tabTitle = $this->tabs[$tab]['title'] ?? '';
        $sectionTitle = $this->sections[$tab][$section]['title'] ?? '';
        $breadcrumb = '';

        if ($queueView) {
            $breadcrumb = '/ '.esc_html($tabTitle).' / <a href="'.esc_url(admin_url('admin.php?page=wp-queue&tab=queues&section=overview')).'">'.esc_html__('Overview', 'wp-queue').'</a> / '.esc_html($queueView);
        } else {
            $breadcrumb = '/ '.esc_html($tabTitle).' / '.esc_html($sectionTitle);
        }
        ?>
        <div class="wp-queue-header">
            <div class="wp-queue-header-left">
                <span class="wp-queue-title">WP Queue</span>
                <span class="wp-queue-breadcrumb">
                    <?php echo wp_kses($breadcrumb, ['a' => ['href' => []]]); ?>
                </span>
            </div>
            <div class="wp-queue-header-right">
                <span class="wp-queue-version">v<?php echo esc_html(WP_QUEUE_VERSION); ?></span>
            </div>
        </div>
    <?php
    }

    private function renderTabs(string $currentTab): void
    {
        ?>
        <nav class="wp-queue-tabs">
            <?php foreach ($this->tabs as $tabKey => $tabData) { ?>
                <a href="<?php echo esc_url(admin_url('admin.php?page=wp-queue&tab='.$tabKey)); ?>"
                    class="wp-queue-tab <?php echo $currentTab === $tabKey ? 'active' : ''; ?>">
                    <span class="dashicons <?php echo esc_attr($tabData['icon']); ?>"></span>
                    <?php echo esc_html($tabData['title']); ?>
                </a>
            <?php } ?>
        </nav>
    <?php
    }

    private function renderSidebar(string $tab, string $currentSection): void
    {
        ?>
        <div class="wp-queue-sidebar">
            <?php foreach ($this->sections[$tab] as $sectionKey => $sectionData) { ?>
                <a href="<?php echo esc_url(admin_url('admin.php?page=wp-queue&tab='.$tab.'&section='.$sectionKey)); ?>"
                    class="wp-queue-sidebar-item <?php echo $currentSection === $sectionKey ? 'active' : ''; ?>">
                    <span class="dashicons <?php echo esc_attr($sectionData['icon']); ?>"></span>
                    <?php echo esc_html($sectionData['title']); ?>
                </a>
            <?php } ?>
        </div>
    <?php
    }

    private function renderSectionContent(string $tab, string $section): void
    {
        $method = 'render'.ucfirst($tab).ucfirst($section);
        if (method_exists($this, $method)) {
            $this->$method();
        } else {
            $this->renderPlaceholder($tab, $section);
        }
    }

    private function renderPlaceholder(string $tab, string $section): void
    {
        ?>
        <div class="wp-queue-section-header">
            <h1><?php echo esc_html($this->sections[$tab][$section]['title']); ?></h1>
            <p class="description"><?php echo esc_html__('This section is under development.', 'wp-queue'); ?></p>
        </div>
    <?php
    }

    private function getStatusLabel(string $status): string
    {
        return match ($status) {
            'idle' => __('Idle', 'wp-queue'),
            'pending' => __('Pending', 'wp-queue'),
            'running' => __('Running', 'wp-queue'),
            'paused' => __('Paused', 'wp-queue'),
            'completed' => __('Completed', 'wp-queue'),
            'failed' => __('Failed', 'wp-queue'),
            default => ucfirst($status),
        };
    }

    protected function renderQueuesOverview(): void
    {
        $metrics = WPQueue::logs()->metrics();
        $queues = $this->getQueuesStatus();
        $driver = WPQueue::manager()->getDefaultDriver();
        $filter = sanitize_key($_GET['status'] ?? '');
        ?>
        <div class="wp-queue-content-wrapper">
            <!-- Статистика - кликабельные карточки -->
            <div class="wp-queue-stats">
                <a href="<?php echo esc_url(admin_url('admin.php?page=wp-queue&tab=queues&section=jobs')); ?>" class="stat-card stat-pending <?php echo $filter === 'pending' ? 'active' : ''; ?>">
                    <span class="stat-number"><?php echo esc_html((string) $this->getTotalPending()); ?></span>
                    <span class="stat-label"><?php echo esc_html__('In Queue', 'wp-queue'); ?></span>
                </a>
                <a href="<?php echo esc_url(admin_url('admin.php?page=wp-queue&tab=queues&section=overview&status=running')); ?>" class="stat-card stat-running <?php echo $filter === 'running' ? 'active' : ''; ?>">
                    <span class="stat-number"><?php echo esc_html((string) $this->getRunningCount()); ?></span>
                    <span class="stat-label"><?php echo esc_html__('Running', 'wp-queue'); ?></span>
                </a>
                <a href="<?php echo esc_url(admin_url('admin.php?page=wp-queue&tab=queues&section=history&filter=completed')); ?>" class="stat-card stat-completed <?php echo $filter === 'completed' ? 'active' : ''; ?>">
                    <span class="stat-number"><?php echo esc_html((string) $metrics['completed']); ?></span>
                    <span class="stat-label"><?php echo esc_html__('Completed', 'wp-queue'); ?></span>
                </a>
                <a href="<?php echo esc_url(admin_url('admin.php?page=wp-queue&tab=queues&section=failed')); ?>" class="stat-card stat-failed <?php echo $filter === 'failed' ? 'active' : ''; ?>">
                    <span class="stat-number"><?php echo esc_html((string) $metrics['failed']); ?></span>
                    <span class="stat-label"><?php echo esc_html__('Failed', 'wp-queue'); ?></span>
                </a>
            </div>

            <!-- Информация о драйвере -->
            <div class="wp-queue-driver-info">
                <span class="dashicons dashicons-admin-generic"></span>
                <?php echo esc_html__('Driver:', 'wp-queue'); ?>
                <strong><?php echo esc_html(ucfirst($driver)); ?></strong>
                <a href="<?php echo esc_url(admin_url('admin.php?page=wp-queue&tab=queues&section=drivers')); ?>" class="driver-link">
                    <?php echo esc_html__('Configure', 'wp-queue'); ?>
                </a>
            </div>

            <!-- Очереди как полностью кликабельные карточки -->
            <h2><?php echo esc_html__('Queues', 'wp-queue'); ?></h2>
            <div class="wp-queue-cards">
                <?php foreach ($queues as $name => $data) { ?>
                    <a href="<?php echo esc_url(admin_url('admin.php?page=wp-queue&tab=queues&queue='.urlencode($name))); ?>" class="queue-card queue-card-<?php echo esc_attr($data['status']); ?>">
                        <div class="queue-card-header">
                            <span class="queue-card-title"><?php echo esc_html($name); ?></span>
                            <span class="status-badge status-<?php echo esc_attr($data['status']); ?>">
                                <?php echo esc_html($this->getStatusLabel($data['status'])); ?>
                            </span>
                        </div>
                        <div class="queue-card-body">
                            <div class="queue-card-stat">
                                <span class="queue-stat-number"><?php echo esc_html((string) $data['size']); ?></span>
                                <span class="queue-stat-label"><?php echo esc_html__('jobs', 'wp-queue'); ?></span>
                            </div>
                        </div>
                        <div class="queue-card-footer">
                            <span class="dashicons dashicons-arrow-right-alt2"></span>
                        </div>
                    </a>
                <?php } ?>
            </div>

            <!-- Последняя активность -->
            <h2><?php echo esc_html__('Recent Activity', 'wp-queue'); ?></h2>
            <?php $this->renderRecentLogs(10); ?>
        </div>
    <?php
    }

    /**
     * Детальный просмотр очереди с джобами
     */
    protected function renderQueueDetail(string $queueName, string $jobId = ''): void
    {
        $jobs = $this->getQueueJobs($queueName);
        $page = max(1, (int) ($_GET['paged'] ?? 1));
        $totalJobs = count($jobs);
        $totalPages = max(1, (int) ceil($totalJobs / self::JOBS_PER_PAGE));
        $offset = ($page - 1) * self::JOBS_PER_PAGE;
        $pagedJobs = array_slice($jobs, $offset, self::JOBS_PER_PAGE, true);

        $isPaused = WPQueue::isPaused($queueName);
        $isProcessing = WPQueue::isProcessing($queueName);
        ?>
        <div class="wp-queue-detail">
            <!-- Заголовок с действиями -->
            <div class="queue-detail-header">
                <div class="queue-detail-title">
                    <h1><?php echo esc_html(sprintf(__('Queue: %s', 'wp-queue'), $queueName)); ?></h1>
                    <span class="status-badge status-<?php echo $isPaused ? 'paused' : ($isProcessing ? 'running' : 'idle'); ?>">
                        <?php
                            if ($isPaused) {
                                echo esc_html__('Paused', 'wp-queue');
                            } elseif ($isProcessing) {
                                echo esc_html__('Processing', 'wp-queue');
                            } else {
                                echo esc_html__('Active', 'wp-queue');
                            }
        ?>
                    </span>
                </div>
                <div class="queue-detail-actions">
                    <?php if ($isPaused) { ?>
                        <button class="button button-primary wp-queue-action" data-action="resume" data-queue="<?php echo esc_attr($queueName); ?>">
                            <span class="dashicons dashicons-controls-play"></span>
                            <?php echo esc_html__('Resume', 'wp-queue'); ?>
                        </button>
                    <?php } else { ?>
                        <button class="button wp-queue-action" data-action="pause" data-queue="<?php echo esc_attr($queueName); ?>">
                            <span class="dashicons dashicons-controls-pause"></span>
                            <?php echo esc_html__('Pause', 'wp-queue'); ?>
                        </button>
                    <?php } ?>
                    <button class="button wp-queue-action" data-action="process" data-queue="<?php echo esc_attr($queueName); ?>">
                        <span class="dashicons dashicons-update"></span>
                        <?php echo esc_html__('Process Now', 'wp-queue'); ?>
                    </button>
                    <button class="button wp-queue-action" data-action="clear" data-queue="<?php echo esc_attr($queueName); ?>">
                        <span class="dashicons dashicons-trash"></span>
                        <?php echo esc_html__('Clear', 'wp-queue'); ?>
                    </button>
                </div>
            </div>

            <!-- Статистика очереди -->
            <div class="wp-queue-stats" style="margin-bottom: 20px;">
                <div class="stat-card">
                    <span class="stat-number"><?php echo esc_html((string) $totalJobs); ?></span>
                    <span class="stat-label"><?php echo esc_html__('Total Jobs', 'wp-queue'); ?></span>
                </div>
                <div class="stat-card">
                    <span class="stat-number"><?php echo esc_html((string) $this->countPendingJobs($jobs)); ?></span>
                    <span class="stat-label"><?php echo esc_html__('Pending', 'wp-queue'); ?></span>
                </div>
                <div class="stat-card stat-running">
                    <span class="stat-number"><?php echo esc_html((string) $this->countReservedJobs($jobs)); ?></span>
                    <span class="stat-label"><?php echo esc_html__('Processing', 'wp-queue'); ?></span>
                </div>
            </div>

            <!-- Таблица джобов -->
            <table class="wp-list-table widefat fixed striped">
                <thead>
                    <tr>
                        <th style="width: 80px;"><?php echo esc_html__('ID', 'wp-queue'); ?></th>
                        <th><?php echo esc_html__('Job Class', 'wp-queue'); ?></th>
                        <th style="width: 80px;"><?php echo esc_html__('Attempts', 'wp-queue'); ?></th>
                        <th style="width: 150px;"><?php echo esc_html__('Available From', 'wp-queue'); ?></th>
                        <th style="width: 100px;"><?php echo esc_html__('Status', 'wp-queue'); ?></th>
                        <th style="width: 150px;"><?php echo esc_html__('Actions', 'wp-queue'); ?></th>
                    </tr>
                </thead>
                <tbody>
                    <?php if (empty($pagedJobs)) { ?>
                        <tr>
                            <td colspan="6" class="no-items"><?php echo esc_html__('Queue is empty', 'wp-queue'); ?></td>
                        </tr>
                    <?php } else { ?>
                        <?php foreach ($pagedJobs as $id => $job) { ?>
                            <tr>
                                <td><code title="<?php echo esc_attr($id); ?>"><?php echo esc_html(substr($id, 0, 8)); ?>...</code></td>
                                <td>
                                    <code><?php echo esc_html($job['class'] ?? __('Unknown', 'wp-queue')); ?></code>
                                    <?php if (! empty($job['payload_preview'])) { ?>
                                        <br><small class="description"><?php echo esc_html($job['payload_preview']); ?></small>
                                    <?php } ?>
                                </td>
                                <td><?php echo esc_html((string) ($job['attempts'] ?? 0)); ?></td>
                                <td>
                                    <?php
                    $availableAt = $job['available_at'] ?? 0;
                            if ($availableAt > time()) {
                                echo esc_html(human_time_diff($availableAt, time())).' '.esc_html__('from now', 'wp-queue');
                            } else {
                                echo esc_html__('Now', 'wp-queue');
                            }
                            ?>
                                </td>
                                <td>
                                    <?php
                            $status = 'pending';
                            if (! empty($job['reserved_at'])) {
                                $status = 'running';
                            } elseif ($availableAt > time()) {
                                $status = 'delayed';
                            }
                            ?>
                                    <span class="status-badge status-<?php echo esc_attr($status); ?>">
                                        <?php echo esc_html($this->getStatusLabel($status)); ?>
                                    </span>
                                </td>
                                <td>
                                    <button class="button button-small wp-queue-job-action" data-action="view" data-job="<?php echo esc_attr($id); ?>" data-queue="<?php echo esc_attr($queueName); ?>" title="<?php echo esc_attr__('Details', 'wp-queue'); ?>">
                                        <span class="dashicons dashicons-visibility"></span>
                                    </button>
                                    <button class="button button-small wp-queue-job-action" data-action="delete" data-job="<?php echo esc_attr($id); ?>" data-queue="<?php echo esc_attr($queueName); ?>" title="<?php echo esc_attr__('Delete', 'wp-queue'); ?>">
                                        <span class="dashicons dashicons-trash"></span>
                                    </button>
                                </td>
                            </tr>
                        <?php } ?>
                    <?php } ?>
                </tbody>
            </table>

            <!-- Пагинация -->
            <?php if ($totalPages > 1) { ?>
                <div class="tablenav bottom">
                    <div class="tablenav-pages">
                        <span class="displaying-num">
                            <?php echo esc_html(sprintf(__('%d tasks', 'wp-queue'), $totalJobs)); ?>
                        </span>
                        <span class="pagination-links">
                            <?php
                            $baseUrl = admin_url('admin.php?page=wp-queue&tab=queues&queue='.urlencode($queueName));
                if ($page > 1) {
                    echo '<a class="prev-page button" href="'.esc_url($baseUrl.'&paged='.($page - 1)).'">‹</a>';
                }
                echo '<span class="paging-input">'.esc_html($page).' / '.esc_html((string) $totalPages).'</span>';
                if ($page < $totalPages) {
                    echo '<a class="next-page button" href="'.esc_url($baseUrl.'&paged='.($page + 1)).'">›</a>';
                }
                ?>
                        </span>
                    </div>
                </div>
            <?php } ?>
        </div>

        <!-- Модальное окно для просмотра деталей джоба -->
        <div id="wp-queue-job-modal" class="wp-queue-modal" style="display:none;">
            <div class="wp-queue-modal-content" style="max-width: 700px;">
                <h2><?php echo esc_html__('Job Details', 'wp-queue'); ?></h2>
                <div id="wp-queue-job-details"></div>
                <p class="submit">
                    <button type="button" class="button wp-queue-modal-close"><?php echo esc_html__('Close', 'wp-queue'); ?></button>
                </p>
            </div>
        </div>
    <?php
    }

    protected function renderQueuesJobs(): void
    {
        $scheduler = WPQueue::scheduler();
        $jobs = $scheduler->getJobs();

        ?>
        <div class="wp-queue-jobs">
            <p class="description">
                <?php echo esc_html__(
                    'Displays a table of scheduled recurring tasks (PHP classes) registered in WP Queue: job class, execution interval, queue assignment, and next run time. Allows you to run any task manually.',
                    'wp-queue',
                ); ?>
            </p>
            <table class="wp-list-table widefat fixed striped">
                <thead>
                    <tr>
                        <th><?php echo esc_html__('Job', 'wp-queue'); ?></th>
                        <th><?php echo esc_html__('Schedule', 'wp-queue'); ?></th>
                        <th><?php echo esc_html__('Queue', 'wp-queue'); ?></th>
                        <th><?php echo esc_html__('Next Run', 'wp-queue'); ?></th>
                        <th><?php echo esc_html__('Actions', 'wp-queue'); ?></th>
                    </tr>
                </thead>
                <tbody>
                    <?php if (empty($jobs)) { ?>
                        <tr>
                            <td colspan="5"><?php echo esc_html__('No scheduled jobs found.', 'wp-queue'); ?></td>
                        </tr>
                    <?php } else { ?>
                        <?php foreach ($jobs as $jobClass => $scheduled) { ?>
                            <?php
                            $hook = 'wp_queue_'.strtolower(preg_replace('/(?<!^)[A-Z]/', '_$0', (new \ReflectionClass($jobClass))->getShortName()));
                            $nextRun = wp_next_scheduled($hook);
                            ?>
                            <tr>
                                <td><code><?php echo esc_html($jobClass); ?></code></td>
                                <td><?php echo esc_html($scheduled->getInterval() ?: '-'); ?></td>
                                <td><?php echo esc_html($scheduled->getQueue() ?? 'default'); ?></td>
                                <td>
                                    <?php if ($nextRun) { ?>
                                        <?php echo esc_html(human_time_diff($nextRun, time())); ?>
                                        <?php echo $nextRun > time() ? esc_html__('from now', 'wp-queue') : esc_html__('ago (overdue)', 'wp-queue'); ?>
                                    <?php } else { ?>
                                        -
                                    <?php } ?>
                                </td>
                                <td>
                                    <button class="button button-small wp-queue-run-job" data-job="<?php echo esc_attr($jobClass); ?>">
                                        <?php echo esc_html__('Run Now', 'wp-queue'); ?>
                                    </button>
                                </td>
                            </tr>
                        <?php } ?>
                    <?php } ?>
                </tbody>
            </table>
        </div>
    <?php
    }

    protected function renderDocsIntro(): void
    {
        ?>
        <div class="wp-queue-help">
            <h2><?php echo esc_html__('About WP Queue', 'wp-queue'); ?></h2>
            <p class="description">
                <?php echo esc_html__(
                    'WP Queue is a robust background processing library for WordPress. It allows you to offload time-consuming tasks (like sending emails, syncing data, or generating reports) to be processed asynchronously, ensuring your site remains fast and responsive for visitors.',
                    'wp-queue',
                ); ?>
            </p>

            <hr style="margin: 20px 0; border: 0; border-top: 1px solid #ddd;">

            <h3><?php echo esc_html__('Why is it needed?', 'wp-queue'); ?></h3>
            <p>
                <?php echo esc_html__(
                    'By default, WordPress executes all actions during the page load request. If a plugin needs to send 100 emails or sync 500 products with an external API, the user has to wait until it finishes. This leads to slow page loads or timeouts.',
                    'wp-queue',
                ); ?>
            </p>
            <p>
                <?php echo esc_html__(
                    'WP Queue solves this by pushing these tasks to a "Queue" and processing them later in the background. The user gets an instant response, and the heavy work happens behind the scenes.',
                    'wp-queue',
                ); ?>
            </p>

            <h3><?php echo esc_html__('How Queues Work', 'wp-queue'); ?></h3>
            <ul class="wp-queue-help-list" style="list-style: disc; padding-left: 20px;">
                <li><strong><?php echo esc_html__('Dispatch', 'wp-queue'); ?>:</strong> <?php echo esc_html__('A plugin creates a "Job" (a task) and pushes it to the queue.', 'wp-queue'); ?></li>
                <li><strong><?php echo esc_html__('Storage', 'wp-queue'); ?>:</strong> <?php echo esc_html__('The job is saved in the database (or Redis) waiting to be picked up.', 'wp-queue'); ?></li>
                <li><strong><?php echo esc_html__('Worker', 'wp-queue'); ?>:</strong> <?php echo esc_html__('A background process (Worker) constantly checks the queue for new jobs.', 'wp-queue'); ?></li>
                <li><strong><?php echo esc_html__('Process', 'wp-queue'); ?>:</strong> <?php echo esc_html__('The worker picks up the job and executes it. If it succeeds, it is marked as "Completed". If it fails, it can be retried.', 'wp-queue'); ?></li>
            </ul>

            <h3><?php echo esc_html__('WP-Cron vs System Cron', 'wp-queue'); ?></h3>
            <p>
                <?php echo esc_html__(
                    'To process the queue, WordPress needs a trigger. There are two ways to do this:',
                    'wp-queue',
                ); ?>
            </p>
            <div style="background: #fff; border: 1px solid #ccd0d4; padding: 15px; margin-bottom: 15px;">
                <p><strong>1. <?php echo esc_html__('WP-Cron (Default)', 'wp-queue'); ?></strong></p>
                <p style="margin-top: 5px;">
                    <?php echo esc_html__(
                        'WordPress checks for scheduled tasks on every page load. This works out of the box but has downsides: on low-traffic sites, tasks might not run on time; on high-traffic sites, it can cause performance issues. It is "fake" cron.',
                        'wp-queue',
                    ); ?>
                </p>

                <p style="margin-top: 15px;"><strong>2. <?php echo esc_html__('System Cron (Recommended)', 'wp-queue'); ?></strong></p>
                <p style="margin-top: 5px;">
                    <?php echo esc_html__(
                        'A real cron job configured on your server (Linux) that calls WordPress every minute. This is reliable and precise. Ideally, you should disable default WP-Cron and use System Cron.',
                        'wp-queue',
                    ); ?>
                </p>
                <code style="display: block; background: #f0f0f1; padding: 10px; margin-top: 5px;">*/1 * * * * php /path/to/wordpress/wp-cron.php</code>
            </div>

            <h3><?php echo esc_html__('Redis Support', 'wp-queue'); ?></h3>
            <p>
                <?php echo esc_html__(
                    'By default, WP Queue stores jobs in the WordPress database (wp_options or custom tables). For high-performance sites, you can use Redis. Redis is an in-memory data store that is much faster than a SQL database for queue operations. It reduces load on your database and speeds up job processing.',
                    'wp-queue',
                ); ?>
            </p>

            <hr style="margin: 20px 0; border: 0; border-top: 1px solid #ddd;">

            <h3><?php echo esc_html__('Detailed description of the panel tabs', 'wp-queue'); ?></h3>
            <div class="wp-queue-accordion" style="margin-top: 20px;">
                <details>
                    <summary style="cursor: pointer; font-weight: bold; padding: 10px; background: #f9f9f9; border: 1px solid #ddd; margin-bottom: 5px;">
                        <?php echo esc_html__('Dashboard', 'wp-queue'); ?>
                    </summary>
                    <div style="padding: 15px; border: 1px solid #ddd; border-top: none; margin-bottom: 10px;">
                        <p><?php echo esc_html__('The main tab for monitoring queue status in real-time.', 'wp-queue'); ?></p>
                        <ul style="list-style: disc; padding-left: 20px;">
                            <li><strong><?php echo esc_html__('Queue statistics', 'wp-queue'); ?>:</strong> <?php echo esc_html__('Displays the total number of tasks in different statuses (pending, running, completed, failed).', 'wp-queue'); ?></li>
                            <li><strong><?php echo esc_html__('Queue table', 'wp-queue'); ?>:</strong> <?php echo esc_html__('A list of all active queues with their size, status (idle/pending/running/paused), and control buttons (pause/resume, clear all tasks).', 'wp-queue'); ?></li>
                            <li><strong><?php echo esc_html__('Recent activity', 'wp-queue'); ?>:</strong> <?php echo esc_html__('The last 10 log entries for quick tracking of queue activity.', 'wp-queue'); ?></li>
                        </ul>
                        <p><em><?php echo esc_html__('Use this tab for quick checks of queue performance and identifying stuck processes.', 'wp-queue'); ?></em></p>
                    </div>
                </details>

                <details>
                    <summary style="cursor: pointer; font-weight: bold; padding: 10px; background: #f9f9f9; border: 1px solid #ddd; margin-bottom: 5px;">
                        <?php echo esc_html__('Scheduled Jobs', 'wp-queue'); ?>
                    </summary>
                    <div style="padding: 15px; border: 1px solid #ddd; border-top: none; margin-bottom: 10px;">
                        <p><?php echo esc_html__('Management of scheduled recurring tasks.', 'wp-queue'); ?></p>
                        <ul style="list-style: disc; padding-left: 20px;">
                            <li><strong><?php echo esc_html__('Job list', 'wp-queue'); ?>:</strong> <?php echo esc_html__('Displays all registered PHP job classes with their configuration.', 'wp-queue'); ?></li>
                            <li><strong><?php echo esc_html__('Job information', 'wp-queue'); ?>:</strong> <?php echo esc_html__('Job class, execution interval (e.g., "hourly", "daily"), queue assignment, next run time.', 'wp-queue'); ?></li>
                            <li><strong><?php echo esc_html__('Manual run', 'wp-queue'); ?>:</strong> <?php echo esc_html__('The "Run Now" button allows you to run any job immediately, without waiting for the schedule.', 'wp-queue'); ?></li>
                            <li><strong><?php echo esc_html__('Next run status', 'wp-queue'); ?>:</strong> <?php echo esc_html__('Displays when the job should run next or marks overdue jobs.', 'wp-queue'); ?></li>
                        </ul>
                        <p><em><?php echo esc_html__('Useful for testing jobs and forcing synchronizations.', 'wp-queue'); ?></em></p>
                    </div>
                </details>

                <details>
                    <summary style="cursor: pointer; font-weight: bold; padding: 10px; background: #f9f9f9; border: 1px solid #ddd; margin-bottom: 5px;">
                        <?php echo esc_html__('Logs', 'wp-queue'); ?>
                    </summary>
                    <div style="padding: 15px; border: 1px solid #ddd; border-top: none; margin-bottom: 10px;">
                        <p><?php echo esc_html__('History of all job executions for diagnostics and debugging.', 'wp-queue'); ?></p>
                        <ul style="list-style: disc; padding-left: 20px;">
                            <li><strong><?php echo esc_html__('Status filters', 'wp-queue'); ?>:</strong> <?php echo esc_html__('Buttons to show all entries, only completed, or only failed jobs.', 'wp-queue'); ?></li>
                            <li><strong><?php echo esc_html__('Log table', 'wp-queue'); ?>:</strong> <?php echo esc_html__('Execution time, status (completed/failed/retrying), job class, queue, error message.', 'wp-queue'); ?></li>
                            <li><strong><?php echo esc_html__('Clear logs', 'wp-queue'); ?>:</strong> <?php echo esc_html__('Button to delete old log entries to avoid cluttering the database.', 'wp-queue'); ?></li>
                            <li><strong><?php echo esc_html__('Number of entries', 'wp-queue'); ?>:</strong> <?php echo esc_html__('Displays the last 200 entries by default or all completed/failed.', 'wp-queue'); ?></li>
                        </ul>
                        <p><em><?php echo esc_html__('Check logs when encountering errors in background processes.', 'wp-queue'); ?></em></p>
                    </div>
                </details>

                <details>
                    <summary style="cursor: pointer; font-weight: bold; padding: 10px; background: #f9f9f9; border: 1px solid #ddd; margin-bottom: 5px;">
                        <?php echo esc_html__('WP-Cron', 'wp-queue'); ?>
                    </summary>
                    <div style="padding: 15px; border: 1px solid #ddd; border-top: none; margin-bottom: 10px;">
                        <p><?php echo esc_html__('Management of WordPress system timers that trigger background processes.', 'wp-queue'); ?></p>
                        <ul style="list-style: disc; padding-left: 20px;">
                            <li><strong><?php echo esc_html__('Event statistics', 'wp-queue'); ?>:</strong> <?php echo esc_html__('Total number of events, overdue, grouped by source (WordPress, WooCommerce, WP Queue, plugins).', 'wp-queue'); ?></li>
                            <li><strong><?php echo esc_html__('Event table', 'wp-queue'); ?>:</strong> <?php echo esc_html__('Event hook, next run time, schedule (single/hourly/daily), source, arguments.', 'wp-queue'); ?></li>
                            <li><strong><?php echo esc_html__('Event actions', 'wp-queue'); ?>:</strong> <?php echo esc_html__('Run now, edit schedule, pause, delete. Modal window for changing interval.', 'wp-queue'); ?></li>
                            <li><strong><?php echo esc_html__('Paused events', 'wp-queue'); ?>:</strong> <?php echo esc_html__('A separate table for events that are temporarily disabled.', 'wp-queue'); ?></li>
                            <li><strong><?php echo esc_html__('Registered schedules', 'wp-queue'); ?>:</strong> <?php echo esc_html__('A table of all available intervals (hourly, daily, etc.) with their duration.', 'wp-queue'); ?></li>
                        </ul>
                        <p><em><?php echo esc_html__('Critical for understanding why tasks are not running on time.', 'wp-queue'); ?></em></p>
                    </div>
                </details>

                <details>
                    <summary style="cursor: pointer; font-weight: bold; padding: 10px; background: #f9f9f9; border: 1px solid #ddd; margin-bottom: 5px;">
                        <?php echo esc_html__('System', 'wp-queue'); ?>
                    </summary>
                    <div style="padding: 15px; border: 1px solid #ddd; border-top: none; margin-bottom: 10px;">
                        <p><?php echo esc_html__('Checking system requirements and environment for stable queue operation.', 'wp-queue'); ?></p>
                        <ul style="list-style: disc; padding-left: 20px;">
                            <li><strong><?php echo esc_html__('System health', 'wp-queue'); ?>:</strong> <?php echo esc_html__('Notifications of critical issues (low memory, disabled WP-Cron).', 'wp-queue'); ?></li>
                            <li><strong><?php echo esc_html__('Environment', 'wp-queue'); ?>:</strong> <?php echo esc_html__('PHP and WordPress versions, memory limits (current usage), maximum execution time, timezone.', 'wp-queue'); ?></li>
                            <li><strong><?php echo esc_html__('WP-Cron status', 'wp-queue'); ?>:</strong> <?php echo esc_html__('Checking for disablement (DISABLE_WP_CRON), alternative cron, loopback requests.', 'wp-queue'); ?></li>
                            <li><strong><?php echo esc_html__('Action Scheduler', 'wp-queue'); ?>:</strong> <?php echo esc_html__('If installed, displays version and statistics (pending/running/failed actions).', 'wp-queue'); ?></li>
                            <li><strong><?php echo esc_html__('Server time', 'wp-queue'); ?>:</strong> <?php echo esc_html__('Comparison of server time, WordPress, and GMT offset for diagnosing scheduling issues.', 'wp-queue'); ?></li>
                        </ul>
                        <p><em><?php echo esc_html__('Check this tab when setting up your server or debugging queue issues.', 'wp-queue'); ?></em></p>
                    </div>
                </details>
            </div>

            <h3><?php echo esc_html__('More documentation', 'wp-queue'); ?></h3>
            <p>
                <?php
                printf(
                    /* translators: %s: URL to GitHub repository */
                    esc_html__(
                        'Full documentation and code examples are available on GitHub: %s.',
                        'wp-queue',
                    ),
                    esc_url('https://github.com/rwsite/wp-queue'),
                );
        ?>
            </p>
        </div>
    <?php
    }

    protected function renderQueuesHistory(): void
    {
        $filter = sanitize_key($_GET['filter'] ?? 'all');
        $queueFilter = sanitize_key($_GET['queue_filter'] ?? '');
        $page = max(1, (int) ($_GET['paged'] ?? 1));

        // Получаем все логи
        $allLogs = match ($filter) {
            'failed' => WPQueue::logs()->failed(),
            'completed' => WPQueue::logs()->completed(),
            default => WPQueue::logs()->recent(500),
        };

        // Фильтрация по очереди
        if ($queueFilter) {
            $allLogs = array_filter($allLogs, fn ($log) => ($log['queue'] ?? '') === $queueFilter);
        }

        // Получаем список уникальных очередей
        $queuesInLogs = array_unique(array_column(WPQueue::logs()->recent(500), 'queue'));

        $totalLogs = count($allLogs);
        $totalPages = max(1, (int) ceil($totalLogs / self::LOGS_PER_PAGE));
        $offset = ($page - 1) * self::LOGS_PER_PAGE;
        $logs = array_slice($allLogs, $offset, self::LOGS_PER_PAGE);
        ?>
        <div class="wp-queue-content-wrapper">
            <div class="tablenav top">
                <div class="alignleft actions">
                    <a href="?page=wp-queue&tab=queues&section=history&filter=all" class="button <?php echo $filter === 'all' ? 'button-primary' : ''; ?>">
                        <?php echo esc_html__('All', 'wp-queue'); ?>
                    </a>
                    <a href="?page=wp-queue&tab=queues&section=history&filter=completed" class="button <?php echo $filter === 'completed' ? 'button-primary' : ''; ?>">
                        <?php echo esc_html__('Completed', 'wp-queue'); ?>
                    </a>
                    <a href="?page=wp-queue&tab=queues&section=history&filter=failed" class="button <?php echo $filter === 'failed' ? 'button-primary' : ''; ?>">
                        <?php echo esc_html__('Failed', 'wp-queue'); ?>
                    </a>

                    <?php if (! empty($queuesInLogs)) { ?>
                        <select id="queue-filter" class="wp-queue-filter-select">
                            <option value=""><?php echo esc_html__('All queues', 'wp-queue'); ?></option>
                            <?php foreach ($queuesInLogs as $q) { ?>
                                <option value="<?php echo esc_attr($q); ?>" <?php selected($queueFilter, $q); ?>>
                                    <?php echo esc_html($q); ?>
                                </option>
                            <?php } ?>
                        </select>
                    <?php } ?>
                </div>
                <div class="alignright actions">
                    <button class="button wp-queue-clear-logs" title="<?php echo esc_attr__('Deletes logs older than 7 days', 'wp-queue'); ?>">
                        <span class="dashicons dashicons-trash"></span>
                        <?php echo esc_html__('Clear old', 'wp-queue'); ?>
                    </button>
                    <button class="button button-link-delete wp-queue-clear-all-logs">
                        <span class="dashicons dashicons-dismiss"></span>
                        <?php echo esc_html__('Delete all', 'wp-queue'); ?>
                    </button>
                </div>
            </div>

            <table class="wp-list-table widefat fixed striped">
                <thead>
                    <tr>
                        <th style="width: 150px;"><?php echo esc_html__('Time', 'wp-queue'); ?></th>
                        <th style="width: 100px;"><?php echo esc_html__('Status', 'wp-queue'); ?></th>
                        <th><?php echo esc_html__('Job', 'wp-queue'); ?></th>
                        <th style="width: 100px;"><?php echo esc_html__('Queue', 'wp-queue'); ?></th>
                        <th><?php echo esc_html__('Message', 'wp-queue'); ?></th>
                    </tr>
                </thead>
                <tbody>
                    <?php if (empty($logs)) { ?>
                        <tr>
                            <td colspan="5" class="no-items"><?php echo esc_html__('No logs found', 'wp-queue'); ?></td>
                        </tr>
                    <?php } else { ?>
                        <?php foreach ($logs as $log) { ?>
                            <tr>
                                <td><?php echo esc_html(wp_date('Y-m-d H:i:s', $log['timestamp'])); ?></td>
                                <td>
                                    <span class="status-badge status-<?php echo esc_attr($log['status']); ?>">
                                        <?php echo esc_html($log['status']); ?>
                                    </span>
                                </td>
                                <td><code><?php echo esc_html($log['job_class']); ?></code></td>
                                <td>
                                    <a href="?page=wp-queue&tab=queues&section=history&queue_filter=<?php echo esc_attr($log['queue']); ?>">
                                        <?php echo esc_html($log['queue']); ?>
                                    </a>
                                </td>
                                <td><?php echo esc_html($log['message'] ?? '-'); ?></td>
                            </tr>
                        <?php } ?>
                    <?php } ?>
                </tbody>
            </table>

            <?php if ($totalPages > 1) { ?>
                <div class="tablenav bottom">
                    <div class="tablenav-pages">
                        <span class="displaying-num">
                            <?php echo esc_html(sprintf(__('%d entries', 'wp-queue'), $totalLogs)); ?>
                        </span>
                        <span class="pagination-links">
                            <?php
                                $baseUrl = admin_url('admin.php?page=wp-queue&tab=queues&section=history&filter='.$filter);
                if ($queueFilter) {
                    $baseUrl .= '&queue_filter='.$queueFilter;
                }
                if ($page > 1) {
                    echo '<a class="prev-page button" href="'.esc_url($baseUrl.'&paged='.($page - 1)).'">‹</a>';
                }
                echo '<span class="paging-input">'.esc_html($page).' / '.esc_html((string) $totalPages).'</span>';
                if ($page < $totalPages) {
                    echo '<a class="next-page button" href="'.esc_url($baseUrl.'&paged='.($page + 1)).'">›</a>';
                }
                ?>
                        </span>
                    </div>
                </div>
            <?php } ?>
        </div>
    <?php
    }

    /**
     * Секция с ошибками (failed jobs)
     */
    protected function renderQueuesFailed(): void
    {
        $failedLogs = WPQueue::logs()->failed();
        $page = max(1, (int) ($_GET['paged'] ?? 1));
        $totalLogs = count($failedLogs);
        $totalPages = max(1, (int) ceil($totalLogs / self::LOGS_PER_PAGE));
        $offset = ($page - 1) * self::LOGS_PER_PAGE;
        $logs = array_slice($failedLogs, $offset, self::LOGS_PER_PAGE);
        ?>
        <div class="wp-queue-failed">
            <div class="wp-queue-stats" style="margin-bottom: 20px;">
                <div class="stat-card stat-failed">
                    <span class="stat-number"><?php echo esc_html((string) $totalLogs); ?></span>
                    <span class="stat-label"><?php echo esc_html__('Total errors', 'wp-queue'); ?></span>
                </div>
            </div>

            <table class="wp-list-table widefat fixed striped">
                <thead>
                    <tr>
                        <th style="width: 150px;"><?php echo esc_html__('Time', 'wp-queue'); ?></th>
                        <th><?php echo esc_html__('Job', 'wp-queue'); ?></th>
                        <th style="width: 100px;"><?php echo esc_html__('Queue', 'wp-queue'); ?></th>
                        <th style="width: 80px;"><?php echo esc_html__('Attempts', 'wp-queue'); ?></th>
                        <th><?php echo esc_html__('Error message', 'wp-queue'); ?></th>
                    </tr>
                </thead>
                <tbody>
                    <?php if (empty($logs)) { ?>
                        <tr>
                            <td colspan="5" class="no-items"><?php echo esc_html__('No errors', 'wp-queue'); ?></td>
                        </tr>
                    <?php } else { ?>
                        <?php foreach ($logs as $log) { ?>
                            <tr>
                                <td><?php echo esc_html(wp_date('Y-m-d H:i:s', $log['timestamp'])); ?></td>
                                <td><code><?php echo esc_html($log['job_class']); ?></code></td>
                                <td><?php echo esc_html($log['queue']); ?></td>
                                <td><?php echo esc_html((string) ($log['attempts'] ?? 0)); ?></td>
                                <td>
                                    <span class="error-message" title="<?php echo esc_attr($log['message'] ?? ''); ?>">
                                        <?php echo esc_html(mb_substr($log['message'] ?? '-', 0, 100)); ?>
                                        <?php if (strlen($log['message'] ?? '') > 100) {
                                            echo '...';
                                        } ?>
                                    </span>
                                </td>
                            </tr>
                        <?php } ?>
                    <?php } ?>
                </tbody>
            </table>

            <?php if ($totalPages > 1) { ?>
                <div class="tablenav bottom">
                    <div class="tablenav-pages">
                        <span class="pagination-links">
                            <?php
                            $baseUrl = admin_url('admin.php?page=wp-queue&tab=queues&section=failed');
                if ($page > 1) {
                    echo '<a class="prev-page button" href="'.esc_url($baseUrl.'&paged='.($page - 1)).'">‹</a>';
                }
                echo '<span class="paging-input">'.esc_html($page).' / '.esc_html((string) $totalPages).'</span>';
                if ($page < $totalPages) {
                    echo '<a class="next-page button" href="'.esc_url($baseUrl.'&paged='.($page + 1)).'">›</a>';
                }
                ?>
                        </span>
                    </div>
                </div>
            <?php } ?>
        </div>
    <?php
    }

    /**
     * Секция с информацией о драйверах
     */
    protected function renderQueuesDrivers(): void
    {
        $manager = WPQueue::manager();
        $currentDriver = $manager->getDefaultDriver();
        $configuredDriver = $manager->getConfiguredDriver();
        $drivers = $manager->getAvailableDrivers();

        // Check if there's a fallback situation
        $hasFallback = $configuredDriver !== $currentDriver;
        ?>
        <div class="wp-queue-drivers">
            <?php if ($hasFallback) { ?>
                <div class="notice notice-warning" style="margin: 0 0 20px;">
                    <p>
                        <strong><?php echo esc_html__('⚠️ Warning:', 'wp-queue'); ?></strong>
                        <?php echo esc_html(sprintf(
                            __('Storage backend "%s" is configured in wp-config.php, but not available. Falling back to "%s".', 'wp-queue'),
                            $configuredDriver,
                            $currentDriver,
                        )); ?>
                    </p>
                    <p>
                        <?php echo esc_html($drivers[$configuredDriver]['message'] ?? __('Check driver settings.', 'wp-queue')); ?>
                    </p>
                </div>
            <?php } else { ?>
                <div class="notice notice-info" style="margin: 0 0 20px;">
                    <p>
                        <strong><?php echo esc_html__('Current storage backend:', 'wp-queue'); ?></strong>
                        <?php echo esc_html(ucfirst($currentDriver)); ?>
                    </p>
                </div>
            <?php } ?>

            <table class="wp-list-table widefat fixed striped">
                <thead>
                    <tr>
                        <th style="width: 150px;"><?php echo esc_html__('Storage backend', 'wp-queue'); ?></th>
                        <th style="width: 120px;"><?php echo esc_html__('Status', 'wp-queue'); ?></th>
                        <th><?php echo esc_html__('Description', 'wp-queue'); ?></th>
                        <th style="width: 100px;"><?php echo esc_html__('Active', 'wp-queue'); ?></th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($drivers as $name => $info) {
                        $isActive = ($name === $currentDriver);
                        $isConfigured = ($name === $configuredDriver);
                        $status = $info['status'] ?? 'unknown';
                        ?>
                        <tr>
                            <td>
                                <strong><?php echo esc_html(ucfirst($name)); ?></strong>
                                <?php if ($isConfigured && ! $isActive) { ?>
                                    <br><small style="color: #d63638;"><?php echo esc_html__('(configured)', 'wp-queue'); ?></small>
                                <?php } ?>
                            </td>
                            <td>
                                <?php echo $this->renderDriverStatusBadge($status, $info); ?>
                            </td>
                            <td>
                                <?php echo esc_html($info['message'] ?? $info['info'] ?? ''); ?>
                                <?php if ($status === \WPQueue\QueueManager::STATUS_NO_EXTENSION) { ?>
                                    <br><small class="description">
                                        <?php if ($name === 'redis') { ?>
                                            <?php echo esc_html__('Install: pecl install redis', 'wp-queue'); ?>
                                        <?php } elseif ($name === 'memcached') { ?>
                                            <?php echo esc_html__('Install: pecl install memcached', 'wp-queue'); ?>
                                        <?php } ?>
                                    </small>
                                <?php } ?>
                            </td>
                            <td>
                                <?php if ($isActive) { ?>
                                    <span class="dashicons dashicons-yes-alt" style="color: #00a32a;"></span>
                                <?php } else { ?>
                                    -
                                <?php } ?>
                            </td>
                        </tr>
                    <?php } ?>
                </tbody>
            </table>

            <div class="wp-queue-help" style="margin-top: 20px;">
                <h3><?php echo esc_html__('How to change the storage backend?', 'wp-queue'); ?></h3>
                <p><?php echo esc_html__('Add to wp-config.php:', 'wp-queue'); ?></p>
                <pre><code>define('WP_QUEUE_DRIVER', 'database'); // or 'redis', 'memcached', 'sync', 'auto'</code></pre>

                <h4><?php echo esc_html__('Configure Redis', 'wp-queue'); ?></h4>
                <pre><code>define('WP_REDIS_HOST', '127.0.0.1');
define('WP_REDIS_PORT', 6379);
define('WP_REDIS_PASSWORD', ''); // if required
define('WP_QUEUE_DRIVER', 'redis');</code></pre>

                <h4><?php echo esc_html__('Configure Memcached', 'wp-queue'); ?></h4>
                <pre><code>define('WP_MEMCACHED_HOST', '127.0.0.1');
define('WP_MEMCACHED_PORT', 11211);
define('WP_QUEUE_DRIVER', 'memcached');</code></pre>

                <div class="notice notice-info inline" style="margin-top: 15px;">
                    <p>
                        <strong><?php echo esc_html__('Important:', 'wp-queue'); ?></strong>
                        <?php echo esc_html__('For Redis/Memcached to work, you need the PHP extension installed and a server available. If not, the system will automatically use the "database" driver.', 'wp-queue'); ?>
                    </p>
                </div>
            </div>
        </div>
    <?php
    }

    /**
     * Render driver status badge with appropriate styling.
     *
     * @param  string  $status  Driver status constant
     * @param  array<string, mixed>  $info  Driver info
     */
    protected function renderDriverStatusBadge(string $status, array $info): string
    {
        return match ($status) {
            \WPQueue\QueueManager::STATUS_READY => sprintf(
                '<span class="status-badge status-completed">%s</span>',
                esc_html__('Ready', 'wp-queue'),
            ),
            \WPQueue\QueueManager::STATUS_NO_EXTENSION => sprintf(
                '<span class="status-badge status-failed">%s</span>',
                esc_html__('No extension', 'wp-queue'),
            ),
            \WPQueue\QueueManager::STATUS_NO_SERVER => sprintf(
                '<span class="status-badge status-pending">%s</span>',
                esc_html__('No server', 'wp-queue'),
            ),
            default => sprintf(
                '<span class="status-badge status-failed">%s</span>',
                esc_html__('Unavailable', 'wp-queue'),
            ),
        };
    }

    protected function renderRecentLogs(int $limit): void
    {
        $logs = WPQueue::logs()->recent($limit);
        ?>
        <table class="wp-list-table widefat fixed striped">
            <thead>
                <tr>
                    <th><?php echo esc_html__('Time', 'wp-queue'); ?></th>
                    <th><?php echo esc_html__('Status', 'wp-queue'); ?></th>
                    <th><?php echo esc_html__('Job', 'wp-queue'); ?></th>
                </tr>
            </thead>
            <tbody>
                <?php if (empty($logs)) { ?>
                    <tr>
                        <td colspan="3" class="no-items"><?php echo esc_html__('No activity', 'wp-queue'); ?></td>
                    </tr>
                <?php } else { ?>
                    <?php foreach ($logs as $log) { ?>
                        <tr>
                            <td><?php echo esc_html(wp_date('H:i:s', $log['timestamp'])); ?></td>
                            <td>
                                <?php
                                    $icon = match ($log['status']) {
                                        'completed' => '✓',
                                        'failed' => '✗',
                                        'retrying' => '↻',
                                        default => '⟳',
                                    };
                        ?>
                                <span class="log-icon log-<?php echo esc_attr($log['status']); ?>"><?php echo esc_html($icon); ?></span>
                                <?php echo esc_html($log['status']); ?>
                            </td>
                            <td><code><?php echo esc_html(class_basename($log['job_class'])); ?></code></td>
                        </tr>
                    <?php } ?>
                <?php } ?>
            </tbody>
        </table>
    <?php
    }

    /**
     * @return array<string, array{size: int, status: string}>
     */
    protected function getQueuesStatus(): array
    {
        global $wpdb;

        $queues = ['default' => ['size' => 0, 'status' => 'idle']];

        // Find all queue options
        $results = $wpdb->get_col(
            "SELECT option_name FROM {$wpdb->options} WHERE option_name LIKE 'wp_queue_jobs_%'",
        );

        foreach ($results as $optionName) {
            $queueName = str_replace('wp_queue_jobs_', '', $optionName);
            $jobs = get_site_option($optionName, []);

            $status = 'idle';
            if (WPQueue::isPaused($queueName)) {
                $status = 'paused';
            } elseif (WPQueue::isProcessing($queueName)) {
                $status = 'running';
            } elseif (! empty($jobs)) {
                $status = 'pending';
            }

            $queues[$queueName] = [
                'size' => count($jobs),
                'status' => $status,
            ];
        }

        return $queues;
    }

    protected function getTotalPending(): int
    {
        $total = 0;
        foreach ($this->getQueuesStatus() as $data) {
            $total += $data['size'];
        }

        return $total;
    }

    protected function getRunningCount(): int
    {
        $count = 0;
        foreach ($this->getQueuesStatus() as $data) {
            if ($data['status'] === 'running') {
                $count++;
            }
        }

        return $count;
    }

    /**
     * Получить джобы из очереди с детальной информацией
     *
     * @return array<string, array{class: string, attempts: int, available_at: int, reserved_at: int|null, payload_preview: string}>
     */
    protected function getQueueJobs(string $queueName): array
    {
        $rawJobs = get_site_option('wp_queue_jobs_'.$queueName, []);
        $jobs = [];

        foreach ($rawJobs as $id => $data) {
            $class = __('Unknown', 'wp-queue');
            $payloadPreview = '';

            // Попробуем десериализовать payload для получения класса
            if (isset($data['payload'])) {
                try {
                    $job = @unserialize($data['payload']);
                    if (is_object($job)) {
                        $class = get_class($job);
                        // Получаем превью данных джоба
                        if (method_exists($job, 'toArray')) {
                            $arr = $job->toArray();
                            unset($arr['id'], $arr['queue'], $arr['attempts']);
                            $payloadPreview = substr(wp_json_encode($arr), 0, 100);
                        }
                    }
                } catch (\Throwable $e) {
                    // Игнорируем ошибки десериализации
                }
            }

            $jobs[$id] = [
                'class' => $class,
                'attempts' => $data['attempts'] ?? 0,
                'available_at' => $data['available_at'] ?? 0,
                'reserved_at' => $data['reserved_at'] ?? null,
                'payload_preview' => $payloadPreview,
            ];
        }

        return $jobs;
    }

    /**
     * @param  array<string, array{reserved_at: int|null, available_at: int}>  $jobs
     */
    protected function countPendingJobs(array $jobs): int
    {
        $count = 0;
        $now = time();
        foreach ($jobs as $job) {
            if (empty($job['reserved_at']) && ($job['available_at'] ?? 0) <= $now) {
                $count++;
            }
        }

        return $count;
    }

    /**
     * @param  array<string, array{reserved_at: int|null}>  $jobs
     */
    protected function countReservedJobs(array $jobs): int
    {
        $count = 0;
        foreach ($jobs as $job) {
            if (! empty($job['reserved_at'])) {
                $count++;
            }
        }

        return $count;
    }

    /**
     * Обзор планировщика
     */
    protected function renderSchedulerOverview(): void
    {
        $monitor = new CronMonitor();
        $stats = $monitor->getStats();
        $scheduler = WPQueue::scheduler();
        $jobs = $scheduler->getJobs();
        ?>
        <div class="wp-queue-content-wrapper">
            <!-- Статистика планировщика -->
            <div class="wp-queue-stats">
                <a href="<?php echo esc_url(admin_url('admin.php?page=wp-queue&tab=scheduler&section=events')); ?>" class="stat-card stat-pending">
                    <span class="stat-number"><?php echo esc_html((string) $stats['total']); ?></span>
                    <span class="stat-label"><?php echo esc_html__('Total Events', 'wp-queue'); ?></span>
                </a>
                <a href="<?php echo esc_url(admin_url('admin.php?page=wp-queue&tab=scheduler&section=scheduled')); ?>" class="stat-card stat-running">
                    <span class="stat-number"><?php echo esc_html((string) count($jobs)); ?></span>
                    <span class="stat-label"><?php echo esc_html__('Scheduled', 'wp-queue'); ?></span>
                </a>
                <a href="<?php echo esc_url(admin_url('admin.php?page=wp-queue&tab=scheduler&section=events&filter=overdue')); ?>" class="stat-card stat-failed">
                    <span class="stat-number"><?php echo esc_html((string) $stats['overdue']); ?></span>
                    <span class="stat-label"><?php echo esc_html__('Overdue', 'wp-queue'); ?></span>
                </a>
                <a href="<?php echo esc_url(admin_url('admin.php?page=wp-queue&tab=scheduler&section=paused')); ?>" class="stat-card">
                    <span class="stat-number"><?php echo esc_html((string) count($monitor->getPaused())); ?></span>
                    <span class="stat-label"><?php echo esc_html__('Paused', 'wp-queue'); ?></span>
                </a>
            </div>

            <!-- Информация о cron -->
            <div class="wp-queue-driver-info">
                <span class="dashicons dashicons-clock"></span>
                <?php echo esc_html__('WP-Cron:', 'wp-queue'); ?>
                <?php if (defined('DISABLE_WP_CRON') && constant('DISABLE_WP_CRON')) { ?>
                    <span class="status-badge status-failed"><?php echo esc_html__('Disabled', 'wp-queue'); ?></span>
                    <span class="description"><?php echo esc_html__('Use system cron', 'wp-queue'); ?></span>
                <?php } else { ?>
                    <span class="status-badge status-completed"><?php echo esc_html__('Active', 'wp-queue'); ?></span>
                <?php } ?>
            </div>

            <!-- События по источникам -->
            <h2><?php echo esc_html__('Events by Source', 'wp-queue'); ?></h2>
            <div class="wp-queue-cards">
                <?php foreach ($stats['by_source'] as $source => $count) { ?>
                    <a href="<?php echo esc_url(admin_url('admin.php?page=wp-queue&tab=scheduler&section=events&filter='.urlencode($source))); ?>" class="queue-card">
                        <div class="queue-card-header">
                            <span class="queue-card-title"><?php echo esc_html(ucfirst($source)); ?></span>
                        </div>
                        <div class="queue-card-body">
                            <div class="queue-card-stat">
                                <span class="queue-stat-number"><?php echo esc_html((string) $count); ?></span>
                                <span class="queue-stat-label"><?php echo esc_html__('events', 'wp-queue'); ?></span>
                            </div>
                        </div>
                        <div class="queue-card-footer">
                            <span class="dashicons dashicons-arrow-right-alt2"></span>
                        </div>
                    </a>
                <?php } ?>
            </div>

            <!-- Ближайшие события -->
            <h2><?php echo esc_html__('Upcoming Events', 'wp-queue'); ?></h2>
            <?php
                $upcomingEvents = array_slice($monitor->getAllEvents(), 0, 5);
        if (empty($upcomingEvents)) { ?>
                <p class="description"><?php echo esc_html__('No scheduled events', 'wp-queue'); ?></p>
            <?php } else { ?>
                <table class="wp-list-table widefat fixed striped">
                    <thead>
                        <tr>
                            <th><?php echo esc_html__('Hook', 'wp-queue'); ?></th>
                            <th style="width: 150px;"><?php echo esc_html__('Next Run', 'wp-queue'); ?></th>
                            <th style="width: 100px;"><?php echo esc_html__('Source', 'wp-queue'); ?></th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($upcomingEvents as $event) { ?>
                            <tr>
                                <td><code><?php echo esc_html($event['hook']); ?></code></td>
                                <td><?php echo esc_html($event['next_run']); ?></td>
                                <td><span class="status-badge status-<?php echo esc_attr($event['source']); ?>"><?php echo esc_html(ucfirst($event['source'])); ?></span></td>
                            </tr>
                        <?php } ?>
                    </tbody>
                </table>
            <?php } ?>
        </div>
    <?php
    }

    protected function renderSchedulerEvents(): void
    {
        $monitor = new CronMonitor();
        $filter = sanitize_key($_GET['filter'] ?? 'all');

        $events = match ($filter) {
            'wordpress' => array_filter($monitor->getAllEvents(), fn ($e) => $e['source'] === 'wordpress'),
            'woocommerce' => array_filter($monitor->getAllEvents(), fn ($e) => $e['source'] === 'woocommerce'),
            'wp-queue' => array_filter($monitor->getAllEvents(), fn ($e) => $e['source'] === 'wp-queue'),
            'plugin' => array_filter($monitor->getAllEvents(), fn ($e) => $e['source'] === 'plugin'),
            'overdue' => array_filter($monitor->getAllEvents(), fn ($e) => $e['is_overdue']),
            default => $monitor->getAllEvents(),
        };

        $stats = $monitor->getStats();
        ?>
        <div class="wp-queue-content-wrapper">
            <div class="wp-queue-stats">
                <a href="?page=wp-queue&tab=scheduler&section=events&filter=all" class="stat-card <?php echo $filter === 'all' ? 'active' : ''; ?>">
                    <span class="stat-number"><?php echo esc_html((string) $stats['total']); ?></span>
                    <span class="stat-label"><?php echo esc_html__('Total', 'wp-queue'); ?></span>
                </a>
                <a href="?page=wp-queue&tab=scheduler&section=events&filter=overdue" class="stat-card stat-failed <?php echo $filter === 'overdue' ? 'active' : ''; ?>">
                    <span class="stat-number"><?php echo esc_html((string) $stats['overdue']); ?></span>
                    <span class="stat-label"><?php echo esc_html__('Overdue', 'wp-queue'); ?></span>
                </a>
                <?php foreach ($stats['by_source'] as $source => $count) { ?>
                    <div class="stat-card">
                        <span class="stat-number"><?php echo esc_html((string) $count); ?></span>
                        <span class="stat-label"><?php echo esc_html(ucfirst($source)); ?></span>
                    </div>
                <?php } ?>
            </div>

            <div class="tablenav top">
                <div class="alignleft actions">
                    <a href="?page=wp-queue&tab=scheduler&section=events&filter=all" class="button <?php echo $filter === 'all' ? 'button-primary' : ''; ?>">
                        <?php echo esc_html__('All', 'wp-queue'); ?>
                    </a>
                    <a href="?page=wp-queue&tab=scheduler&section=events&filter=wordpress" class="button <?php echo $filter === 'wordpress' ? 'button-primary' : ''; ?>">
                        <?php echo esc_html__('WordPress', 'wp-queue'); ?>
                    </a>
                    <a href="?page=wp-queue&tab=scheduler&section=events&filter=woocommerce" class="button <?php echo $filter === 'woocommerce' ? 'button-primary' : ''; ?>">
                        <?php echo esc_html__('WooCommerce', 'wp-queue'); ?>
                    </a>
                    <a href="?page=wp-queue&tab=scheduler&section=events&filter=wp-queue" class="button <?php echo $filter === 'wp-queue' ? 'button-primary' : ''; ?>">
                        <?php echo esc_html__('WP Queue', 'wp-queue'); ?>
                    </a>
                    <a href="?page=wp-queue&tab=scheduler&section=events&filter=plugin" class="button <?php echo $filter === 'plugin' ? 'button-primary' : ''; ?>">
                        <?php echo esc_html__('Plugins', 'wp-queue'); ?>
                    </a>
                </div>
            </div>

            <table class="wp-list-table widefat fixed striped">
                <thead>
                    <tr>
                        <th><?php echo esc_html__('Hook', 'wp-queue'); ?></th>
                        <th style="width: 120px;"><?php echo esc_html__('Next Run', 'wp-queue'); ?></th>
                        <th style="width: 100px;"><?php echo esc_html__('Schedule', 'wp-queue'); ?></th>
                        <th style="width: 100px;"><?php echo esc_html__('Source', 'wp-queue'); ?></th>
                        <th style="width: 150px;"><?php echo esc_html__('Actions', 'wp-queue'); ?></th>
                    </tr>
                </thead>
                <tbody>
                    <?php if (empty($events)) { ?>
                        <tr>
                            <td colspan="5"><?php echo esc_html__('No cron events found.', 'wp-queue'); ?></td>
                        </tr>
                    <?php } else { ?>
                        <?php foreach ($events as $event) { ?>
                            <tr class="<?php echo $event['is_overdue'] ? 'wp-queue-overdue' : ''; ?>">
                                <td>
                                    <code><?php echo esc_html($event['hook']); ?></code>
                                    <?php if (! empty($event['args'])) { ?>
                                        <br><small><?php echo esc_html(wp_json_encode($event['args'])); ?></small>
                                    <?php } ?>
                                </td>
                                <td>
                                    <?php if ($event['is_overdue']) { ?>
                                        <span class="status-badge status-failed"><?php echo esc_html__('Overdue', 'wp-queue'); ?></span>
                                    <?php } else { ?>
                                        <?php echo esc_html($event['next_run']); ?>
                                    <?php } ?>
                                </td>
                                <td><?php echo esc_html($event['schedule'] ?: __('Single', 'wp-queue')); ?></td>
                                <td>
                                    <span class="status-badge status-<?php echo esc_attr($event['source']); ?>">
                                        <?php echo esc_html(ucfirst($event['source'])); ?>
                                    </span>
                                </td>
                                <td class="wp-queue-cron-actions">
                                    <button class="button button-small wp-queue-cron-run"
                                        data-hook="<?php echo esc_attr($event['hook']); ?>"
                                        data-args="<?php echo esc_attr(wp_json_encode($event['args'])); ?>"
                                        title="<?php echo esc_attr__('Run Now', 'wp-queue'); ?>">
                                        <?php echo esc_html__('Run', 'wp-queue'); ?>
                                    </button>
                                    <button class="button button-small wp-queue-cron-edit"
                                        data-hook="<?php echo esc_attr($event['hook']); ?>"
                                        data-timestamp="<?php echo esc_attr((string) $event['timestamp']); ?>"
                                        data-args="<?php echo esc_attr(wp_json_encode($event['args'])); ?>"
                                        data-schedule="<?php echo esc_attr($event['schedule'] ?: 'single'); ?>"
                                        title="<?php echo esc_attr__('Edit Schedule', 'wp-queue'); ?>">
                                        <?php echo esc_html__('Edit', 'wp-queue'); ?>
                                    </button>
                                    <button class="button button-small wp-queue-cron-pause"
                                        data-hook="<?php echo esc_attr($event['hook']); ?>"
                                        data-timestamp="<?php echo esc_attr((string) $event['timestamp']); ?>"
                                        data-args="<?php echo esc_attr(wp_json_encode($event['args'])); ?>"
                                        title="<?php echo esc_attr__('Pause', 'wp-queue'); ?>">
                                        <?php echo esc_html__('Pause', 'wp-queue'); ?>
                                    </button>
                                    <button class="button button-small wp-queue-cron-delete"
                                        data-hook="<?php echo esc_attr($event['hook']); ?>"
                                        data-timestamp="<?php echo esc_attr((string) $event['timestamp']); ?>"
                                        data-args="<?php echo esc_attr(wp_json_encode($event['args'])); ?>"
                                        title="<?php echo esc_attr__('Delete', 'wp-queue'); ?>">
                                        <?php echo esc_html__('Delete', 'wp-queue'); ?>
                                    </button>
                                </td>
                            </tr>
                        <?php } ?>
                    <?php } ?>
                </tbody>
            </table>

            <?php $paused = $monitor->getPaused(); ?>
            <?php if (! empty($paused)) { ?>
                <h2><?php echo esc_html__('Paused Events', 'wp-queue'); ?></h2>
                <table class="wp-list-table widefat fixed striped">
                    <thead>
                        <tr>
                            <th><?php echo esc_html__('Hook', 'wp-queue'); ?></th>
                            <th><?php echo esc_html__('Schedule', 'wp-queue'); ?></th>
                            <th><?php echo esc_html__('Paused At', 'wp-queue'); ?></th>
                            <th style="width: 100px;"><?php echo esc_html__('Actions', 'wp-queue'); ?></th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($paused as $event) { ?>
                            <tr>
                                <td><code><?php echo esc_html($event['hook']); ?></code></td>
                                <td><?php echo esc_html($event['schedule'] ?: __('Single', 'wp-queue')); ?></td>
                                <td><?php echo esc_html(wp_date('Y-m-d H:i:s', $event['paused_at'])); ?></td>
                                <td>
                                    <button class="button button-small wp-queue-cron-resume"
                                        data-hook="<?php echo esc_attr($event['hook']); ?>"
                                        data-args="<?php echo esc_attr(wp_json_encode($event['args'])); ?>">
                                        <?php echo esc_html__('Resume', 'wp-queue'); ?>
                                    </button>
                                </td>
                            </tr>
                        <?php } ?>
                    </tbody>
                </table>
            <?php } ?>

            <h2><?php echo esc_html__('Registered Schedules', 'wp-queue'); ?></h2>
            <table class="wp-list-table widefat fixed striped">
                <thead>
                    <tr>
                        <th><?php echo esc_html__('Name', 'wp-queue'); ?></th>
                        <th><?php echo esc_html__('Interval', 'wp-queue'); ?></th>
                        <th><?php echo esc_html__('Display', 'wp-queue'); ?></th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($monitor->getSchedules() as $name => $schedule) { ?>
                        <tr>
                            <td><code><?php echo esc_html($name); ?></code></td>
                            <td><?php echo esc_html(human_time_diff(0, $schedule['interval'])); ?></td>
                            <td><?php echo esc_html($schedule['display']); ?></td>
                        </tr>
                    <?php } ?>
                </tbody>
            </table>

            <!-- Edit Modal -->
            <div id="wp-queue-edit-modal" class="wp-queue-modal" style="display:none;">
                <div class="wp-queue-modal-content">
                    <h2><?php echo esc_html__('Edit Cron Event', 'wp-queue'); ?></h2>
                    <form id="wp-queue-edit-form">
                        <input type="hidden" name="hook" id="edit-hook">
                        <input type="hidden" name="timestamp" id="edit-timestamp">
                        <input type="hidden" name="args" id="edit-args">

                        <p>
                            <label for="edit-schedule"><strong><?php echo esc_html__('Schedule', 'wp-queue'); ?></strong></label><br>
                            <select name="schedule" id="edit-schedule" class="regular-text">
                                <option value="single"><?php echo esc_html__('Single (run once)', 'wp-queue'); ?></option>
                                <?php foreach ($monitor->getSchedules() as $name => $schedule) { ?>
                                    <option value="<?php echo esc_attr($name); ?>">
                                        <?php echo esc_html($schedule['display']); ?> (<?php echo esc_html($name); ?>)
                                    </option>
                                <?php } ?>
                            </select>
                        </p>

                        <p class="submit">
                            <button type="submit" class="button button-primary"><?php echo esc_html__('Save Changes', 'wp-queue'); ?></button>
                            <button type="button" class="button wp-queue-modal-close"><?php echo esc_html__('Cancel', 'wp-queue'); ?></button>
                        </p>
                    </form>
                </div>
            </div>
        </div>
    <?php
    }

    protected function renderDiagnosticsEnvironment(): void
    {
        $status = new SystemStatus();
        $report = $status->getFullReport();
        $health = $status->getHealthStatus();

        ?>
        <div class="wp-queue-system">
            <p class="description">
                <?php echo esc_html__(
                    'Displays environment information (PHP and WordPress versions, memory limits, execution time, timezone), WP-Cron status (disabled, alternative cron, loopback checks). If Action Scheduler is installed - its statistics. Server and WordPress time.',
                    'wp-queue',
                ); ?>
            </p>
            <?php if ($health['status'] !== 'healthy') { ?>
                <div class="notice notice-warning">
                    <p><strong><?php echo esc_html__('System Issues Detected:', 'wp-queue'); ?></strong></p>
                    <ul>
                        <?php foreach ($health['issues'] as $issue) { ?>
                            <li><?php echo esc_html($issue); ?></li>
                        <?php } ?>
                    </ul>
                </div>
            <?php } else { ?>
                <div class="notice notice-success">
                    <p><?php echo esc_html__('All systems are healthy.', 'wp-queue'); ?></p>
                </div>
            <?php } ?>

            <h2><?php echo esc_html__('Environment', 'wp-queue'); ?></h2>
            <table class="wp-list-table widefat fixed striped">
                <tbody>
                    <tr>
                        <th scope="row"><?php echo esc_html__('PHP Version', 'wp-queue'); ?></th>
                        <td><?php echo esc_html($report['php_version']); ?></td>
                    </tr>
                    <tr>
                        <th scope="row"><?php echo esc_html__('WordPress Version', 'wp-queue'); ?></th>
                        <td><?php echo esc_html($report['wp_version']); ?></td>
                    </tr>
                    <tr>
                        <th scope="row"><?php echo esc_html__('Memory Limit', 'wp-queue'); ?></th>
                        <td><?php echo esc_html($report['memory_limit_formatted']); ?></td>
                    </tr>
                    <tr>
                        <th scope="row"><?php echo esc_html__('Current Memory Usage', 'wp-queue'); ?></th>
                        <td>
                            <?php echo esc_html($report['current_memory_formatted']); ?>
                            (<?php echo esc_html((string) $report['memory_percent']); ?>%)
                        </td>
                    </tr>
                    <tr>
                        <th scope="row"><?php echo esc_html__('Max Execution Time', 'wp-queue'); ?></th>
                        <td><?php echo esc_html((string) $report['max_execution_time']); ?>s</td>
                    </tr>
                    <tr>
                        <th scope="row"><?php echo esc_html__('Timezone', 'wp-queue'); ?></th>
                        <td><?php echo esc_html($report['timezone']); ?></td>
                    </tr>
                </tbody>
            </table>

            <h2><?php echo esc_html__('WP-Cron Status', 'wp-queue'); ?></h2>
            <table class="wp-list-table widefat fixed striped">
                <tbody>
                    <tr>
                        <th scope="row"><?php echo esc_html__('WP-Cron Disabled', 'wp-queue'); ?></th>
                        <td>
                            <?php if ($report['wp_cron_disabled']) { ?>
                                <span class="status-badge status-failed"><?php echo esc_html__('Disabled', 'wp-queue'); ?></span>
                                <p class="description"><?php echo esc_html__('DISABLE_WP_CRON is set to true. Background tasks will not run automatically.', 'wp-queue'); ?></p>
                            <?php } else { ?>
                                <span class="status-badge status-completed"><?php echo esc_html__('Enabled', 'wp-queue'); ?></span>
                            <?php } ?>
                        </td>
                    </tr>
                    <tr>
                        <th scope="row"><?php echo esc_html__('Alternate Cron', 'wp-queue'); ?></th>
                        <td>
                            <?php if ($report['alternate_cron']) { ?>
                                <span class="status-badge status-completed"><?php echo esc_html__('Enabled', 'wp-queue'); ?></span>
                            <?php } else { ?>
                                <span class="status-badge status-idle"><?php echo esc_html__('Disabled', 'wp-queue'); ?></span>
                            <?php } ?>
                        </td>
                    </tr>
                    <tr>
                        <th scope="row"><?php echo esc_html__('Loopback Request', 'wp-queue'); ?></th>
                        <td>
                            <span class="status-badge status-<?php echo $report['loopback']['status'] === 'ok' ? 'completed' : ($report['loopback']['status'] === 'warning' ? 'pending' : 'failed'); ?>">
                                <?php
                                $status_text = $report['loopback']['status'];
        if ($status_text === 'ok') {
            $status_text = __('OK', 'wp-queue');
        } elseif ($status_text === 'warning') {
            $status_text = __('Warning', 'wp-queue');
        } else {
            $status_text = __('Error', 'wp-queue');
        }
        echo esc_html($status_text);
        ?>
                            </span>
                            <?php if ($report['loopback']['status'] !== 'ok') { ?>
                                <p class="description"><?php echo esc_html($report['loopback']['message']); ?></p>
                            <?php } ?>
                        </td>
                    </tr>
                </tbody>
            </table>

            <?php if ($report['action_scheduler']['installed']) { ?>
                <h2><?php echo esc_html__('Action Scheduler', 'wp-queue'); ?></h2>
                <table class="wp-list-table widefat fixed striped">
                    <tbody>
                        <tr>
                            <th scope="row"><?php echo esc_html__('Version', 'wp-queue'); ?></th>
                            <td><?php echo esc_html($report['action_scheduler']['version'] ?? 'Unknown'); ?></td>
                        </tr>
                        <?php if ($report['action_scheduler']['stats']) { ?>
                            <tr>
                                <th scope="row"><?php echo esc_html__('Pending Actions', 'wp-queue'); ?></th>
                                <td><?php echo esc_html((string) $report['action_scheduler']['stats']['pending']); ?></td>
                            </tr>
                            <tr>
                                <th scope="row"><?php echo esc_html__('Running Actions', 'wp-queue'); ?></th>
                                <td><?php echo esc_html((string) $report['action_scheduler']['stats']['running']); ?></td>
                            </tr>
                            <tr>
                                <th scope="row"><?php echo esc_html__('Failed Actions', 'wp-queue'); ?></th>
                                <td>
                                    <?php if ($report['action_scheduler']['stats']['failed'] > 0) { ?>
                                        <span class="status-badge status-failed">
                                            <?php echo esc_html((string) $report['action_scheduler']['stats']['failed']); ?>
                                        </span>
                                    <?php } else { ?>
                                        0
                                    <?php } ?>
                                </td>
                            </tr>
                        <?php } ?>
                    </tbody>
                </table>
            <?php } ?>

            <h2><?php echo esc_html__('Server Time', 'wp-queue'); ?></h2>
            <table class="wp-list-table widefat fixed striped">
                <tbody>
                    <tr>
                        <th scope="row"><?php echo esc_html__('Server Time', 'wp-queue'); ?></th>
                        <td><?php echo esc_html($report['time_info']['server_time']); ?></td>
                    </tr>
                    <tr>
                        <th scope="row"><?php echo esc_html__('WordPress Time', 'wp-queue'); ?></th>
                        <td><?php echo esc_html($report['time_info']['wp_time']); ?></td>
                    </tr>
                    <tr>
                        <th scope="row"><?php echo esc_html__('GMT Offset', 'wp-queue'); ?></th>
                        <td><?php echo esc_html((string) $report['time_info']['gmt_offset']); ?> hours</td>
                    </tr>
                </tbody>
            </table>
        </div>
    <?php
    }

    /**
     * Статус системы - объединённый с информацией
     */
    protected function renderSystemStatus(): void
    {
        $status = new SystemStatus();
        $report = $status->getFullReport();
        $metrics = WPQueue::logs()->metrics();
        $driver = WPQueue::manager()->getDefaultDriver();

        // Безопасное получение данных
        $phpInfo = $report['php'] ?? [];
        $cronInfo = $report['cron'] ?? [];
        $loopbackInfo = $report['loopback'] ?? [];
        $timeInfo = $report['time_info'] ?? [];
        $wpInfo = $report['wordpress'] ?? [];
        $asInfo = $report['action_scheduler'] ?? [];
        ?>
        <div class="wp-queue-content-wrapper">
            <!-- Статистика -->
            <div class="wp-queue-stats">
                <div class="stat-card stat-completed">
                    <span class="stat-number"><?php echo esc_html((string) ($metrics['completed'] ?? 0)); ?></span>
                    <span class="stat-label"><?php echo esc_html__('Completed', 'wp-queue'); ?></span>
                </div>
                <div class="stat-card stat-failed">
                    <span class="stat-number"><?php echo esc_html((string) ($metrics['failed'] ?? 0)); ?></span>
                    <span class="stat-label"><?php echo esc_html__('Errors', 'wp-queue'); ?></span>
                </div>
                <div class="stat-card">
                    <span class="stat-number"><?php echo esc_html($report['memory_limit_formatted'] ?? 'N/A'); ?></span>
                    <span class="stat-label"><?php echo esc_html__('Memory Limit', 'wp-queue'); ?></span>
                </div>
                <div class="stat-card">
                    <span class="stat-number"><?php echo esc_html((string) ($report['max_execution_time'] ?? 0)); ?>s</span>
                    <span class="stat-label"><?php echo esc_html__('Timeout', 'wp-queue'); ?></span>
                </div>
            </div>

            <!-- Версии -->
            <h2><?php echo esc_html__('Versions', 'wp-queue'); ?></h2>
            <table class="wp-list-table widefat fixed striped">
                <tbody>
                    <tr>
                        <th scope="row" style="width:200px;"><?php echo esc_html__('WP Queue', 'wp-queue'); ?></th>
                        <td><code><?php echo esc_html(defined('WP_QUEUE_VERSION') ? WP_QUEUE_VERSION : '1.0.0'); ?></code></td>
                    </tr>
                    <tr>
                        <th scope="row"><?php echo esc_html__('WordPress', 'wp-queue'); ?></th>
                        <td><code><?php echo esc_html($wpInfo['version'] ?? get_bloginfo('version')); ?></code></td>
                    </tr>
                    <tr>
                        <th scope="row"><?php echo esc_html__('PHP', 'wp-queue'); ?></th>
                        <td><code><?php echo esc_html($phpInfo['version'] ?? PHP_VERSION); ?></code></td>
                    </tr>
                </tbody>
            </table>

            <?php
            $resolvedMode = RuntimeMode::resolveMode();
        $loopbackEnabled = LoopbackDispatcher::isEnabled();
        $loopbackStatus = $loopbackInfo['status'] ?? 'unknown';
        $loopbackOk = $loopbackStatus === 'ok';

        if ($resolvedMode === RuntimeMode::MODE_DAEMON) {
            $handlerLabel = __('Daemon process', 'wp-queue');
            $handlerClass = 'status-completed';
            $handlerDescription = __('A daemon worker is expected to process queues. WP-Cron and loopback are disabled.', 'wp-queue');
        } elseif ($loopbackEnabled && $loopbackOk) {
            $handlerLabel = __('WP-Cron + Loopback', 'wp-queue');
            $handlerClass = 'status-completed';
            $handlerDescription = __('Loopback is enabled and working. Jobs start immediately after dispatch.', 'wp-queue');
        } elseif ($loopbackEnabled) {
            $handlerLabel = __('WP-Cron + Loopback', 'wp-queue');
            $handlerClass = 'status-pending';
            $handlerDescription = __('Loopback is inactive. Jobs will be processed only by WP-Cron, which can delay execution by up to one minute. Enable loopback or run a daemon worker for instant processing.', 'wp-queue');
        } else {
            $handlerLabel = __('WP-Cron only', 'wp-queue');
            $handlerClass = 'status-pending';
            $handlerDescription = __('Loopback is disabled. Jobs will be processed only by WP-Cron, which can delay execution by up to one minute. Run a daemon worker for instant processing.', 'wp-queue');
        }

        $wpCronDisabled = $report['wp_cron_disabled'] ?? false;
        ?>
            <!-- Статус компонентов -->
            <h2><?php echo esc_html__('Component Status', 'wp-queue'); ?></h2>
            <table class="wp-list-table widefat fixed striped">
                <tbody>
                    <tr>
                        <th scope="row" style="width:200px;"><?php echo esc_html__('Queue Storage backend', 'wp-queue'); ?></th>
                        <td>
                            <span class="status-badge status-completed"><?php echo esc_html(ucfirst($driver)); ?></span>
                        </td>
                    </tr>
                    <tr>
                        <th scope="row"><?php echo esc_html__('Queue handler', 'wp-queue'); ?></th>
                        <td>
                            <span class="status-badge <?php echo esc_attr($handlerClass); ?>"><?php echo esc_html($handlerLabel); ?></span>
                            <span class="description"><?php echo esc_html($handlerDescription); ?></span>
                            <?php if (! $wpCronDisabled && $resolvedMode !== RuntimeMode::MODE_DAEMON) { ?>
                                <span class="description"><?php echo esc_html__('WP-Cron checks the queue every minute as a fallback.', 'wp-queue'); ?></span>
                            <?php } ?>
                        </td>
                    </tr>
                    <tr>
                        <th scope="row"><?php echo esc_html__('WP-Cron', 'wp-queue'); ?></th>
                        <td>
                            <?php if ($wpCronDisabled) { ?>
                                <span class="status-badge status-failed"><?php echo esc_html__('Disabled', 'wp-queue'); ?></span>
                                <span class="description"><?php echo esc_html__('Configure system cron', 'wp-queue'); ?></span>
                            <?php } else { ?>
                                <span class="status-badge status-completed"><?php echo esc_html__('Active', 'wp-queue'); ?></span>
                            <?php } ?>
                        </td>
                    </tr>
                    <tr>
                        <th scope="row"><?php echo esc_html__('Loopback', 'wp-queue'); ?></th>
                        <td>
                            <?php
                            $lbBadgeClass = match ($loopbackStatus) {
                                'ok' => 'status-completed',
                                'warning' => 'status-pending',
                                default => 'status-failed',
                            };
        $lbStatusLabel = match ($loopbackStatus) {
            'ok' => __('OK', 'wp-queue'),
            'warning' => __('Warning', 'wp-queue'),
            'error' => __('Error', 'wp-queue'),
            default => __('Unknown', 'wp-queue'),
        };
        ?>
                            <span class="status-badge <?php echo esc_attr($lbBadgeClass); ?>"><?php echo esc_html($lbStatusLabel); ?></span>
                            <?php if ($loopbackStatus !== 'ok' && isset($loopbackInfo['message'])) { ?>
                                <span class="description"><?php echo esc_html($loopbackInfo['message']); ?></span>
                            <?php } ?>
                        </td>
                    </tr>
                </tbody>
            </table>

            <!-- PHP -->
            <h2><?php echo esc_html__('PHP', 'wp-queue'); ?></h2>
            <table class="wp-list-table widefat fixed striped">
                <tbody>
                    <tr>
                        <th scope="row" style="width:200px;"><?php echo esc_html__('Memory Limit', 'wp-queue'); ?></th>
                        <td><?php echo esc_html($phpInfo['memory_limit'] ?? ini_get('memory_limit')); ?></td>
                    </tr>
                    <tr>
                        <th scope="row"><?php echo esc_html__('Used', 'wp-queue'); ?></th>
                        <td><?php echo esc_html($phpInfo['memory_usage'] ?? size_format(memory_get_usage(true))); ?></td>
                    </tr>
                    <tr>
                        <th scope="row"><?php echo esc_html__('Max Execution Time', 'wp-queue'); ?></th>
                        <td><?php echo esc_html((string) ($phpInfo['max_execution_time'] ?? ini_get('max_execution_time'))); ?> сек</td>
                    </tr>
                </tbody>
            </table>

            <!-- Время -->
            <h2><?php echo esc_html__('Time', 'wp-queue'); ?></h2>
            <table class="wp-list-table widefat fixed striped">
                <tbody>
                    <tr>
                        <th scope="row" style="width:200px;"><?php echo esc_html__('Server Time', 'wp-queue'); ?></th>
                        <td><?php echo esc_html($timeInfo['server_time'] ?? gmdate('Y-m-d H:i:s')); ?></td>
                    </tr>
                    <tr>
                        <th scope="row"><?php echo esc_html__('WordPress Time', 'wp-queue'); ?></th>
                        <td><?php echo esc_html($timeInfo['wp_time'] ?? wp_date('Y-m-d H:i:s')); ?></td>
                    </tr>
                    <tr>
                        <th scope="row"><?php echo esc_html__('Timezone', 'wp-queue'); ?></th>
                        <td>
                            <?php
        $offset = $timeInfo['gmt_offset'] ?? get_option('gmt_offset', 0);
        echo 'GMT '.($offset >= 0 ? '+' : '').esc_html((string) $offset);
        ?>
                        </td>
                    </tr>
                </tbody>
            </table>

            <?php if ($asInfo['installed'] ?? false) { ?>
                <h2><?php echo esc_html__('Action Scheduler', 'wp-queue'); ?></h2>
                <table class="wp-list-table widefat fixed striped">
                    <tbody>
                        <tr>
                            <th scope="row" style="width:200px;"><?php echo esc_html__('Version', 'wp-queue'); ?></th>
                            <td><code><?php echo esc_html($asInfo['version'] ?? 'Unknown'); ?></code></td>
                        </tr>
                        <?php if (isset($asInfo['stats'])) { ?>
                            <tr>
                                <th scope="row"><?php echo esc_html__('Pending', 'wp-queue'); ?></th>
                                <td><?php echo esc_html((string) ($asInfo['stats']['pending'] ?? 0)); ?></td>
                            </tr>
                        <?php } ?>
                    </tbody>
                </table>
            <?php } ?>
        </div>
    <?php
    }

    /**
     * Информация о системе - редирект на статус
     */
    protected function renderSystemInfo(): void
    {
        $this->renderSystemStatus();
    }

    /**
     * Инструменты
     */
    protected function renderSystemTools(): void
    {
        ?>
        <div class="wp-queue-content-wrapper">
            <h2><?php echo esc_html__('Tools', 'wp-queue'); ?></h2>

            <div class="wp-queue-tools-grid">
                <div class="tool-card">
                    <h3><span class="dashicons dashicons-trash"></span> <?php echo esc_html__('Clear Logs', 'wp-queue'); ?></h3>
                    <p class="description"><?php echo esc_html__('Deletes all job execution logs', 'wp-queue'); ?></p>
                    <button class="button wp-queue-clear-all-logs"><?php echo esc_html__('Clear All Logs', 'wp-queue'); ?></button>
                </div>

                <div class="tool-card">
                    <h3><span class="dashicons dashicons-update"></span> <?php echo esc_html__('Process Queues', 'wp-queue'); ?></h3>
                    <p class="description"><?php echo esc_html__('Forces processing of all queues', 'wp-queue'); ?></p>
                    <button class="button wp-queue-process-all"><?php echo esc_html__('Process Now', 'wp-queue'); ?></button>
                </div>

                <div class="tool-card">
                    <h3><span class="dashicons dashicons-backup"></span> <?php echo esc_html__('Clear All Queues', 'wp-queue'); ?></h3>
                    <p class="description"><?php echo esc_html__('Deletes all jobs from all queues', 'wp-queue'); ?></p>
                    <button class="button button-link-delete wp-queue-clear-all-queues"><?php echo esc_html__('Clear All', 'wp-queue'); ?></button>
                </div>
            </div>

            <h2><?php echo esc_html__('WP-CLI Commands', 'wp-queue'); ?></h2>
            <div class="wp-queue-help">
                <pre><code># Обработать очередь
wp queue work --queue=default

# Статус очередей
wp queue status

# Очистить очередь
wp queue clear default

# Список cron событий
wp queue cron list</code></pre>
            </div>
        </div>
    <?php
    }

    /**
     * Справка - полностью переработанная
     */
    protected function renderSystemHelp(): void
    {
        ?>
        <div class="wp-queue-content-wrapper wp-queue-help-page">
            <div class="help-intro">
                <h2><?php echo esc_html__('WP Queue — Queue System for WordPress', 'wp-queue'); ?></h2>
                <p class="description">
                    <?php echo esc_html__('WP Queue allows executing tasks asynchronously in the background without blocking the main WordPress execution flow.', 'wp-queue'); ?>
                </p>
            </div>

            <div class="help-grid">
                <!-- Быстрый старт -->
                <div class="help-card">
                    <div class="help-card-icon">
                        <span class="dashicons dashicons-welcome-learn-more"></span>
                    </div>
                    <h3><?php echo esc_html__('Quick Start', 'wp-queue'); ?></h3>
                    <p><?php echo esc_html__('Create a job class and dispatch it to the queue:', 'wp-queue'); ?></p>
                    <pre><code>use WPQueue\Jobs\Job;

class MyJob extends Job {
    public function handle(): void {
        // Ваш код
    }
}

// Отправка в очередь
WPQueue::dispatch(new MyJob());</code></pre>
                </div>

                <!-- Отложенные задачи -->
                <div class="help-card">
                    <div class="help-card-icon">
                        <span class="dashicons dashicons-clock"></span>
                    </div>
                    <h3><?php echo esc_html__('Delayed Jobs', 'wp-queue'); ?></h3>
                    <p><?php echo esc_html__('Schedule a job to run after a specific time:', 'wp-queue'); ?></p>
                    <pre><code>// Через 5 минут
WPQueue::dispatch(
    (new MyJob())->delay(300)
);

// В определённое время
WPQueue::dispatch(
    (new MyJob())->delay(
        strtotime('tomorrow 9:00')
    )
);</code></pre>
                </div>

                <!-- Именованные очереди -->
                <div class="help-card">
                    <div class="help-card-icon">
                        <span class="dashicons dashicons-database"></span>
                    </div>
                    <h3><?php echo esc_html__('Named Queues', 'wp-queue'); ?></h3>
                    <p><?php echo esc_html__('Distribute jobs across different queues:', 'wp-queue'); ?></p>
                    <pre><code>// Отправить в очередь emails
WPQueue::dispatch(
    (new SendEmailJob())->onQueue('emails')
);

// Отправить в очередь sync
WPQueue::dispatch(
    (new SyncDataJob())->onQueue('sync')
);</code></pre>
                </div>

                <!-- Повторные попытки -->
                <div class="help-card">
                    <div class="help-card-icon">
                        <span class="dashicons dashicons-update"></span>
                    </div>
                    <h3><?php echo esc_html__('Retry Attempts', 'wp-queue'); ?></h3>
                    <p><?php echo esc_html__('Configure automatic retries on errors:', 'wp-queue'); ?></p>
                    <pre><code>class MyJob extends Job {
    protected int $maxAttempts = 3;
    protected int $timeout = 60;

    public function handle(): void {
        // Код задачи
    }

    public function failed(\Throwable $e): void {
        // Обработка ошибки
    }
}</code></pre>
                </div>

                <!-- Планировщик -->
                <div class="help-card">
                    <div class="help-card-icon">
                        <span class="dashicons dashicons-calendar-alt"></span>
                    </div>
                    <h3><?php echo esc_html__('Scheduler', 'wp-queue'); ?></h3>
                    <p><?php echo esc_html__('Run tasks on schedule:', 'wp-queue'); ?></p>
                    <pre><code>// Каждый час
WPQueue::scheduler()->hourly(
    new CleanupJob()
);

// Ежедневно в 3:00
WPQueue::scheduler()->dailyAt(
    new ReportJob(), '03:00'
);

// Каждые 5 минут
WPQueue::scheduler()->everyMinutes(
    new CheckJob(), 5
);</code></pre>
                </div>

                <!-- WP-CLI -->
                <div class="help-card">
                    <div class="help-card-icon">
                        <span class="dashicons dashicons-editor-code"></span>
                    </div>
                    <h3><?php echo esc_html__('WP-CLI команды', 'wp-queue'); ?></h3>
                    <p><?php echo esc_html__('Manage queues from command line:', 'wp-queue'); ?></p>
                    <pre><code># Обработать очередь
wp queue work --queue=default

# Статус очередей
wp queue status

# Очистить очередь
wp queue clear default

# Список cron событий
wp queue cron list

# Запустить cron событие
wp queue cron run hook_name</code></pre>
                </div>
            </div>

            <!-- Полезные ссылки -->
            <div class="help-links">
                <h3><?php echo esc_html__('Useful Links', 'wp-queue'); ?></h3>
                <ul>
                    <li>
                        <span class="dashicons dashicons-book"></span>
                        <a href="https://github.com/developer/wp-queue" target="_blank">
                            <?php echo esc_html__('Documentation on GitHub', 'wp-queue'); ?>
                        </a>
                    </li>
                    <li>
                        <span class="dashicons dashicons-sos"></span>
                        <a href="https://github.com/developer/wp-queue/issues" target="_blank">
                            <?php echo esc_html__('Report an Issue', 'wp-queue'); ?>
                        </a>
                    </li>
                    <li>
                        <span class="dashicons dashicons-admin-plugins"></span>
                        <a href="https://github.com/rwsite/wp-queue-example-plugin" target="_blank">
                            <?php echo esc_html__('Example Plugin on GitHub', 'wp-queue'); ?>
                        </a>
                    </li>
                </ul>
            </div>
        </div>
    <?php
    }

    /**
     * Запланированные задачи (scheduled jobs)
     */
    protected function renderSchedulerScheduled(): void
    {
        $this->renderQueuesJobs();
    }

    /**
     * Приостановленные cron события
     */
    protected function renderSchedulerPaused(): void
    {
        $monitor = new CronMonitor();
        $paused = $monitor->getPaused();
        ?>
        <div class="wp-queue-paused">
            <?php if (empty($paused)) { ?>
                <div class="notice notice-info" style="margin: 0;">
                    <p><?php echo esc_html__('No paused events', 'wp-queue'); ?></p>
                </div>
            <?php } else { ?>
                <table class="wp-list-table widefat fixed striped">
                    <thead>
                        <tr>
                            <th><?php echo esc_html__('Hook', 'wp-queue'); ?></th>
                            <th style="width: 120px;"><?php echo esc_html__('Schedule', 'wp-queue'); ?></th>
                            <th style="width: 150px;"><?php echo esc_html__('Paused Since', 'wp-queue'); ?></th>
                            <th style="width: 120px;"><?php echo esc_html__('Actions', 'wp-queue'); ?></th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($paused as $event) { ?>
                            <tr>
                                <td><code><?php echo esc_html($event['hook']); ?></code></td>
                                <td><?php echo esc_html($event['schedule'] ?: __('One-time', 'wp-queue')); ?></td>
                                <td><?php echo esc_html(wp_date('Y-m-d H:i:s', $event['paused_at'])); ?></td>
                                <td>
                                    <button class="button button-small wp-queue-cron-resume"
                                        data-hook="<?php echo esc_attr($event['hook']); ?>"
                                        data-args="<?php echo esc_attr(wp_json_encode($event['args'])); ?>">
                                        <?php echo esc_html__('Resume', 'wp-queue'); ?>
                                    </button>
                                </td>
                            </tr>
                        <?php } ?>
                    </tbody>
                </table>
            <?php } ?>
        </div>
<?php
    }

    /**
     * Инструкция по запуску демон-воркера
     */
    protected function renderSystemDaemon(): void
    {
        ?>
        <div class="wp-queue-content-wrapper">
            <div class="wp-queue-section-header">
                <h1><?php echo esc_html__('Daemon worker', 'wp-queue'); ?></h1>
                <p class="description"><?php echo esc_html__('How to run WP Queue as a long-running background process.', 'wp-queue'); ?></p>
            </div>

            <div class="wp-queue-help">
                <h2><?php echo esc_html__('When to use a daemon', 'wp-queue'); ?></h2>
                <p><?php echo esc_html__('Use a daemon worker on VPS, Docker or dedicated servers where you can keep a process running. It polls the queue continuously and starts jobs immediately. Without a daemon, WP Queue relies on WP-Cron and/or loopback, which can delay jobs by up to a minute.', 'wp-queue'); ?></p>

                <h2><?php echo esc_html__('1. Enable daemon runtime mode', 'wp-queue'); ?></h2>
                <p><?php echo esc_html__('Add this to wp-config.php:', 'wp-queue'); ?></p>
                <pre><code>define('WP_QUEUE_RUNTIME_MODE', 'daemon');</code></pre>
                <p class="description"><?php echo esc_html__('In this mode WP-Cron scheduling and loopback requests are disabled automatically.', 'wp-queue'); ?></p>

                <h2><?php echo esc_html__('2. Run the worker', 'wp-queue'); ?></h2>

                <h3><?php echo esc_html__('With WP-CLI', 'wp-queue'); ?></h3>
                <p><?php echo esc_html__('Start the worker from the WordPress root:', 'wp-queue'); ?></p>
                <pre><code>wp queue work --daemon --memory=256</code></pre>
                <p class="description"><?php echo esc_html__('--memory limits the PHP memory for the worker process. Adjust the value to your needs.', 'wp-queue'); ?></p>

                <h3><?php echo esc_html__('Without WP-CLI', 'wp-queue'); ?></h3>
                <p><?php echo esc_html__('If WP-CLI is not available, create a PHP script that boots WordPress and runs the worker loop:', 'wp-queue'); ?></p>
                <pre><code>&lt;?php
define('WP_QUEUE_DAEMON', true);
define('WP_QUEUE_RUNTIME_MODE', 'daemon');

require '/var/www/html/wordpress/wp-load.php';

$queue = $argv[1] ?? 'default';
$memory = 256;

ini_set('memory_limit', "{$memory}M");
set_time_limit(0);

WPQueue\WPQueue::worker()->setMemoryLimit($memory);
WPQueue\WPQueue::worker()->daemon($queue);</code></pre>
                <p class="description"><?php echo esc_html__('Save the script, make it executable and run it in the background:', 'wp-queue'); ?></p>
                <pre><code>chmod +x /var/www/html/wp-queue-daemon.php
nohup php /var/www/html/wp-queue-daemon.php > /var/log/wp-queue-daemon.log 2>&1 &amp;</code></pre>

                <h2><?php echo esc_html__('3. Keep it running', 'wp-queue'); ?></h2>
                <p><?php echo esc_html__('The command above must stay alive. In production use one of the options below.', 'wp-queue'); ?></p>

                <h3><?php echo esc_html__('Option A: systemd service', 'wp-queue'); ?></h3>
                <pre><code>[Unit]
Description=WP Queue daemon worker
After=network.target

[Service]
Type=simple
User=www-data
WorkingDirectory=/var/www/html
ExecStart=/usr/local/bin/wp queue work --daemon --memory=256
Restart=always
RestartSec=5

[Install]
WantedBy=multi-user.target</code></pre>
                <p class="description"><?php echo esc_html__('Save as /etc/systemd/system/wp-queue-worker.service, then run systemctl enable --now wp-queue-worker.', 'wp-queue'); ?></p>

                <h3><?php echo esc_html__('Option B: Supervisor', 'wp-queue'); ?></h3>
                <pre><code>[program:wp-queue-worker]
command=/usr/local/bin/wp queue work --daemon --memory=256
directory=/var/www/html
user=www-data
autostart=true
autorestart=true
stderr_logfile=/var/log/wp-queue-worker.err.log
stdout_logfile=/var/log/wp-queue-worker.out.log</code></pre>

                <h3><?php echo esc_html__('Option C: Docker Compose', 'wp-queue'); ?></h3>
                <pre><code>worker:
  image: your-wordpress-image
  command: ["wp", "queue", "work", "--daemon", "--memory=256"]
  restart: unless-stopped</code></pre>

                <h3><?php echo esc_html__('Option D: plain PHP script', 'wp-queue'); ?></h3>
                <p><?php echo esc_html__('Use the script from step 2 (Without WP-CLI) inside screen, tmux or a simple cron-based restart loop. Remember that the script must stay alive to act as a daemon.', 'wp-queue'); ?></p>

                <h2><?php echo esc_html__('4. Check status', 'wp-queue'); ?></h2>
                <pre><code>wp queue status</code></pre>
                <p><?php echo esc_html__('If the worker is not running, queues will not be processed in daemon mode. Make sure the process is started and monitored.', 'wp-queue'); ?></p>
            </div>
        </div>
        <?php
    }
}

if (! function_exists('class_basename')) {
    function class_basename(string $class): string
    {
        return basename(str_replace('\\', '/', $class));
    }
}

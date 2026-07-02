<?php

declare(strict_types=1);

namespace RoboJackSparrow\Core;

use RoboJackSparrow\Admin\Admin;
use RoboJackSparrow\Admin\Menu\ApiKeysPage;
use RoboJackSparrow\Admin\Menu\ArticlesPage;
use RoboJackSparrow\Admin\Menu\DashboardPage;
use RoboJackSparrow\Admin\Menu\LogsPage;
use RoboJackSparrow\Admin\Menu\QueuePage;
use RoboJackSparrow\Admin\Menu\SettingsPage;
use RoboJackSparrow\Admin\Menu\SourcesPage;
use RoboJackSparrow\Ai\HealthMonitor;
use RoboJackSparrow\Cron\CronManager;
use RoboJackSparrow\Database\Repositories\ArticleRepository;
use RoboJackSparrow\Database\Repositories\LogRepository;
use RoboJackSparrow\Database\Repositories\QueueRepository as QueueRepositoryModel;
use RoboJackSparrow\Database\Repositories\SettingRepository;
use RoboJackSparrow\Database\Repositories\SourceRepository;
use RoboJackSparrow\Database\Schema;
use RoboJackSparrow\Queue\QueueManager;
use RoboJackSparrow\Queue\Worker;

final class Plugin
{
    private static ?Plugin $instance = null;

    private Logger $logger;
    private QueueManager $queueManager;
    private Worker $worker;
    private CronManager $cronManager;
    private ?Admin $admin = null;

    public static function instance(): self
    {
        if (self::$instance === null) {
            self::$instance = new self();
        }

        return self::$instance;
    }

    private function __construct()
    {
        $this->logger = new Logger();
        $this->queueManager = new QueueManager($this->logger);
        $this->worker = new Worker($this->queueManager, $this->logger);
        $this->cronManager = new CronManager();

        $this->registerHooks();

        if (is_admin()) {
            $this->admin = $this->buildAdmin();
            $this->admin->register();
        }
    }

    private function buildAdmin(): Admin
    {
        $settings = new SettingRepository(new Encryption());
        $articles = new ArticleRepository();
        $queue = new QueueRepositoryModel();
        $logs = new LogRepository();
        $sources = new SourceRepository();
        $health = new HealthMonitor();

        return new Admin(
            new DashboardPage($articles, $queue, $logs, $health),
            new ArticlesPage($articles),
            new QueuePage($queue),
            new SourcesPage($sources),
            new LogsPage($logs),
            new SettingsPage($settings),
            new ApiKeysPage($settings)
        );
    }

    private function registerHooks(): void
    {
        $this->cronManager->register();

        add_action('rjs_worker_process', [$this->worker, 'processNextBatch']);
        add_action('init', [$this, 'loadTextdomain']);
    }

    public function loadTextdomain(): void
    {
        load_plugin_textdomain('robojacksparrow', false, dirname(RJS_PLUGIN_BASENAME) . '/languages');
    }

    public function getLogger(): Logger
    {
        return $this->logger;
    }

    public function getQueueManager(): QueueManager
    {
        return $this->queueManager;
    }

    public static function activate(): void
    {
        Schema::install();
        (new CronManager())->register();
    }

    public static function deactivate(): void
    {
        CronManager::clearScheduled();
    }
}

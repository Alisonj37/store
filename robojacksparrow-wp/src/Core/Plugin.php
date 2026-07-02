<?php

declare(strict_types=1);

namespace RoboJackSparrow\Core;

use RoboJackSparrow\Cron\CronManager;
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

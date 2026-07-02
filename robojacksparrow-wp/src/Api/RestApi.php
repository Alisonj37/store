<?php

declare(strict_types=1);

namespace RoboJackSparrow\Api;

use RoboJackSparrow\Api\Controllers\ArticleController;
use RoboJackSparrow\Api\Controllers\LogController;
use RoboJackSparrow\Api\Controllers\QueueController;
use RoboJackSparrow\Api\Controllers\SettingsController;
use RoboJackSparrow\Api\Controllers\SourceController;

/**
 * Registra os endpoints REST do plugin sob o namespace robojacksparrow/v1.
 */
class RestApi
{
    private const NAMESPACE = 'robojacksparrow/v1';

    public function __construct(
        private ArticleController $articles,
        private QueueController $queue,
        private SettingsController $settings,
        private LogController $logs,
        private SourceController $sources
    ) {
    }

    public function register(): void
    {
        add_action('rest_api_init', [$this, 'registerRoutes']);
    }

    public function registerRoutes(): void
    {
        $this->articles->registerRoutes(self::NAMESPACE);
        $this->queue->registerRoutes(self::NAMESPACE);
        $this->settings->registerRoutes(self::NAMESPACE);
        $this->logs->registerRoutes(self::NAMESPACE);
        $this->sources->registerRoutes(self::NAMESPACE);
    }
}

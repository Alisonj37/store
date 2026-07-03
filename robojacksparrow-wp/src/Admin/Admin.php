<?php

declare(strict_types=1);

namespace RoboJackSparrow\Admin;

use RoboJackSparrow\Admin\Menu\ApiKeysPage;
use RoboJackSparrow\Admin\Menu\ArticlesPage;
use RoboJackSparrow\Admin\Menu\AutopilotPage;
use RoboJackSparrow\Admin\Menu\DashboardPage;
use RoboJackSparrow\Admin\Menu\GenerateArticlePage;
use RoboJackSparrow\Admin\Menu\LogsPage;
use RoboJackSparrow\Admin\Menu\QueuePage;
use RoboJackSparrow\Admin\Menu\SettingsPage;
use RoboJackSparrow\Admin\Menu\SourcesPage;

/**
 * Registra o menu wp-admin do plugin. UI 100% PHP puro (sem React/SPA/build
 * step), para compatibilidade com hospedagem compartilhada.
 */
class Admin
{
    private const CAPABILITY = 'manage_options';
    private const SLUG = 'robojacksparrow';

    public function __construct(
        private DashboardPage $dashboard,
        private ArticlesPage $articles,
        private GenerateArticlePage $generateArticle,
        private AutopilotPage $autopilot,
        private QueuePage $queue,
        private SourcesPage $sources,
        private LogsPage $logs,
        private SettingsPage $settings,
        private ApiKeysPage $apiKeys
    ) {
    }

    public function register(): void
    {
        add_action('admin_menu', [$this, 'registerMenu']);
    }

    public function registerMenu(): void
    {
        add_menu_page(
            'RoboJackSparrow WP',
            'RoboJackSparrow',
            self::CAPABILITY,
            self::SLUG,
            fn () => print $this->dashboard->render(),
            'dashicons-admin-generic'
        );

        add_submenu_page(self::SLUG, 'Dashboard', 'Dashboard', self::CAPABILITY, self::SLUG, fn () => print $this->dashboard->render());
        add_submenu_page(self::SLUG, 'Artigos', 'Artigos', self::CAPABILITY, self::SLUG . '-articles', fn () => print $this->articles->render());
        add_submenu_page(self::SLUG, 'Gerar Artigo', 'Gerar Artigo', self::CAPABILITY, self::SLUG . '-generate', fn () => print $this->generateArticle->render());
        add_submenu_page(self::SLUG, 'Piloto Automatico', 'Piloto Automatico', self::CAPABILITY, self::SLUG . '-autopilot', fn () => print $this->autopilot->render());
        add_submenu_page(self::SLUG, 'Fila', 'Fila', self::CAPABILITY, self::SLUG . '-queue', fn () => print $this->queue->render());
        add_submenu_page(self::SLUG, 'Fontes', 'Fontes', self::CAPABILITY, self::SLUG . '-sources', fn () => print $this->sources->render());
        add_submenu_page(self::SLUG, 'Logs', 'Logs', self::CAPABILITY, self::SLUG . '-logs', fn () => print $this->logs->render());
        add_submenu_page(self::SLUG, 'Configuracoes', 'Configuracoes', self::CAPABILITY, self::SLUG . '-settings', fn () => print $this->settings->render());
        add_submenu_page(self::SLUG, 'API Keys', 'API Keys', self::CAPABILITY, self::SLUG . '-api-keys', fn () => print $this->apiKeys->render());
    }
}

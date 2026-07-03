<?php

declare(strict_types=1);

namespace RoboJackSparrow\Tests\Unit\Admin;

use RoboJackSparrow\Admin\Menu\ApiKeysPage;
use RoboJackSparrow\Admin\Menu\ArticlesPage;
use RoboJackSparrow\Admin\Menu\DashboardPage;
use RoboJackSparrow\Admin\Menu\SettingsPage;
use RoboJackSparrow\Admin\Menu\SourcesPage;
use RoboJackSparrow\Ai\HealthMonitor;
use RoboJackSparrow\Core\Encryption;
use RoboJackSparrow\Core\Logger;
use RoboJackSparrow\Database\Repositories\ArticleRepository;
use RoboJackSparrow\Database\Repositories\LogRepository;
use RoboJackSparrow\Database\Repositories\QueueRepository;
use RoboJackSparrow\Database\Repositories\SettingRepository;
use RoboJackSparrow\Database\Repositories\SourceRepository;
use RoboJackSparrow\Tests\TestCase;

final class AdminPagesTest extends TestCase
{
    public function testDashboardRendersArticleQueueCountsAndLlmHealth(): void
    {
        $this->wpdb->articles = [
            1 => ['id' => 1, 'status' => 'published', 'created_at' => '2026-01-01 00:00:00'],
            2 => ['id' => 2, 'status' => 'published', 'created_at' => '2026-01-02 00:00:00'],
            3 => ['id' => 3, 'status' => 'pending', 'created_at' => '2026-01-03 00:00:00'],
        ];
        $this->wpdb->llmHealth = ['openai' => ['provider' => 'openai', 'status' => 'healthy', 'avg_latency_ms' => 250, 'success_rate_24h' => 98.5, 'consecutive_failures' => 0]];

        $page = new DashboardPage(new ArticleRepository(), new QueueRepository(), new LogRepository(), new HealthMonitor());
        $html = $page->render();

        $this->assertStringContainsString('published: <strong>2</strong>', $html);
        $this->assertStringContainsString('rjs-badge-healthy', $html);
    }

    public function testArticlesPageAppliesGetStatusFilter(): void
    {
        $this->wpdb->articles = [
            1 => ['id' => 1, 'status' => 'published', 'source_type' => 'rss', 'source_title' => 'A', 'created_at' => '2026-01-01 00:00:00'],
            2 => ['id' => 2, 'status' => 'pending', 'source_type' => 'rss', 'source_title' => 'B', 'created_at' => '2026-01-02 00:00:00'],
        ];
        $_GET['status'] = 'published';

        $html = (new ArticlesPage(new ArticleRepository()))->render();

        $this->assertStringContainsString('1 artigo(s) no total.', $html);
    }

    public function testSourcesPageRejectsSubmissionWithInvalidNonce(): void
    {
        $sources = new SourceRepository();
        $_POST = ['rjs_action' => 'add_source', 'rjs_nonce' => 'wrong', 'source_name' => 'X', 'source_url' => 'https://x.com/feed'];

        (new SourcesPage($sources))->render();

        $this->assertSame([], $this->wpdb->sources);
    }

    public function testSourcesPageRejectsSubmissionWithoutCapability(): void
    {
        $GLOBALS['__rjs_can'] = false;
        $sources = new SourceRepository();
        $_POST = ['rjs_action' => 'add_source', 'rjs_nonce' => 'nonce-rjs_sources', 'source_name' => 'X', 'source_url' => 'https://x.com/feed'];

        (new SourcesPage($sources))->render();

        $this->assertSame([], $this->wpdb->sources);
    }

    public function testSourcesPageFullLifecycleAddToggleDelete(): void
    {
        $sources = new SourceRepository();
        $page = new SourcesPage($sources);

        $_POST = ['rjs_action' => 'add_source', 'rjs_nonce' => 'nonce-rjs_sources', 'source_name' => 'Tech Blog', 'source_url' => 'https://example.com/feed', 'scrape_frequency' => 'daily'];
        $html = $page->render();
        $this->assertCount(1, $this->wpdb->sources);
        $this->assertStringContainsString('Tech Blog', $html);

        $id = array_key_first($this->wpdb->sources);

        $_POST = ['rjs_action' => 'toggle_source', 'rjs_nonce' => 'nonce-rjs_sources', 'source_id' => (string) $id];
        $page->render();
        $this->assertSame(0, $this->wpdb->sources[$id]['is_active']);

        $_POST = ['rjs_action' => 'delete_source', 'rjs_nonce' => 'nonce-rjs_sources', 'source_id' => (string) $id];
        $page->render();
        $this->assertSame([], $this->wpdb->sources);
    }

    public function testSettingsPageSavesViaSettingRepository(): void
    {
        $settings = new SettingRepository(new Encryption(), new Logger());
        $_POST = [
            'rjs_action' => 'save_settings',
            'rjs_nonce'  => 'nonce-rjs_settings',
            'rjs_content_language' => 'en_US',
        ];

        $html = (new SettingsPage($settings))->render();

        // SettingRepository is the single source of truth for these keys -
        // ContentEngine (Fase 5) reads from it directly, so there is no
        // native wp_options mirror to keep in sync anymore.
        $this->assertSame('en_US', $settings->get('rjs_content_language'));
        $this->assertArrayNotHasKey('rjs_content_language', $GLOBALS['__rjs_options']);
        $this->assertStringContainsString('value="en_US"', $html);
    }

    public function testApiKeysPageNeverEchoesTheRawSecretBackAndBlankFieldsAreNoOps(): void
    {
        $settings = new SettingRepository(new Encryption(), new Logger());
        $page = new ApiKeysPage($settings);

        $before = $page->render();
        $this->assertStringContainsString('Nao configurado', $before);

        $_POST = [
            'rjs_action' => 'save_api_keys',
            'rjs_nonce'  => 'nonce-rjs_api_keys',
            'rjs_openai_api_key' => 'sk-openai-1234567890abcdef',
            'rjs_anthropic_api_key' => '',
        ];
        $after = $page->render();

        $this->assertSame('sk-openai-1234567890abcdef', $settings->get('rjs_openai_api_key'));
        $this->assertStringNotContainsString('sk-openai-1234567890abcdef', $after);
        $this->assertNull($settings->get('rjs_anthropic_api_key'), 'a blank submitted field must not create/overwrite the key');
    }
}

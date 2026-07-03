<?php

declare(strict_types=1);

namespace RoboJackSparrow\Tests\Unit\Admin;

use RoboJackSparrow\Admin\Menu\ApiKeysPage;
use RoboJackSparrow\Admin\Menu\ArticlesPage;
use RoboJackSparrow\Admin\Menu\DashboardPage;
use RoboJackSparrow\Admin\Menu\GenerateArticlePage;
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
use RoboJackSparrow\Queue\QueueManager;
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

    public function testArticlesPageShowsGerarButtonOnlyForPendingArticles(): void
    {
        $this->wpdb->articles = [
            1 => ['id' => 1, 'status' => 'pending', 'source_type' => 'rss', 'source_title' => 'A', 'created_at' => '2026-01-01 00:00:00'],
            2 => ['id' => 2, 'status' => 'published', 'source_type' => 'rss', 'source_title' => 'B', 'created_at' => '2026-01-02 00:00:00'],
        ];

        $html = (new ArticlesPage(new ArticleRepository(), new QueueManager(new Logger())))->render();

        $this->assertSame(1, substr_count($html, '>Gerar<'), 'the Gerar button must appear exactly once, only for the pending article');
    }

    public function testArticlesPageGerarButtonEnqueuesScrapeJobForThatArticle(): void
    {
        $this->wpdb->articles[1] = ['id' => 1, 'status' => 'pending', 'source_type' => 'rss', 'source_title' => 'A', 'created_at' => '2026-01-01 00:00:00'];

        $page = new ArticlesPage(new ArticleRepository(), new QueueManager(new Logger()));

        $_POST = ['rjs_action' => 'generate_now', 'rjs_nonce' => 'nonce-rjs_articles', 'article_id' => '1'];
        $html = $page->render();

        $this->assertCount(1, $this->wpdb->queueRows);
        $this->assertSame('scrape', reset($this->wpdb->queueRows)['job_type']);
        $this->assertStringContainsString('enviado para a fila', $html);
    }

    public function testArticlesPageGerarButtonRejectsAlreadyProcessedArticle(): void
    {
        $this->wpdb->articles[1] = ['id' => 1, 'status' => 'published', 'source_type' => 'rss', 'source_title' => 'A', 'created_at' => '2026-01-01 00:00:00'];

        $page = new ArticlesPage(new ArticleRepository(), new QueueManager(new Logger()));

        $_POST = ['rjs_action' => 'generate_now', 'rjs_nonce' => 'nonce-rjs_articles', 'article_id' => '1'];
        $html = $page->render();

        $this->assertSame([], $this->wpdb->queueRows);
        $this->assertStringContainsString('ja processado', $html);
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

    public function testSettingsPageSavesAutopilotAndProviderPreferences(): void
    {
        $settings = new SettingRepository(new Encryption(), new Logger());
        $_POST = [
            'rjs_action' => 'save_settings',
            'rjs_nonce'  => 'nonce-rjs_settings',
            'rjs_autopilot_enabled' => '1',
            'rjs_autopilot_publish_status' => 'publish',
            'rjs_preferred_llm_provider' => 'anthropic',
            'rjs_preferred_image_provider' => 'pexels',
            'rjs_openai_model' => 'gpt-4o-mini',
        ];

        $html = (new SettingsPage($settings))->render();

        $this->assertSame('1', $settings->get('rjs_autopilot_enabled'));
        $this->assertSame('publish', $settings->get('rjs_autopilot_publish_status'));
        $this->assertSame('anthropic', $settings->get('rjs_preferred_llm_provider'));
        $this->assertSame('pexels', $settings->get('rjs_preferred_image_provider'));
        $this->assertSame('gpt-4o-mini', $settings->get('rjs_openai_model'));
        $this->assertStringContainsString('value="gpt-4o-mini"', $html);
    }

    public function testSettingsPageUncheckedCheckboxIsSavedAsDisabled(): void
    {
        $settings = new SettingRepository(new Encryption(), new Logger());
        $settings->set('rjs_autopilot_enabled', '1');

        // Submitting the form with the checkbox omitted (as browsers do for
        // unchecked checkboxes) must persist it as disabled, not leave the
        // previous '1' value untouched.
        $_POST = [
            'rjs_action' => 'save_settings',
            'rjs_nonce'  => 'nonce-rjs_settings',
        ];

        (new SettingsPage($settings))->render();

        $this->assertSame('0', $settings->get('rjs_autopilot_enabled'));
    }

    public function testSettingsPageRejectsUnknownSelectValue(): void
    {
        $settings = new SettingRepository(new Encryption(), new Logger());
        $_POST = [
            'rjs_action' => 'save_settings',
            'rjs_nonce'  => 'nonce-rjs_settings',
            'rjs_preferred_llm_provider' => 'not-a-real-provider',
        ];

        (new SettingsPage($settings))->render();

        $this->assertNull($settings->get('rjs_preferred_llm_provider'));
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

    public function testApiKeysPageShowsHelpLinksForKnownProvidersButNotForKeiIa(): void
    {
        $settings = new SettingRepository(new Encryption(), new Logger());
        $html = (new ApiKeysPage($settings))->render();

        $this->assertStringContainsString('https://platform.openai.com/api-keys', $html);
        $this->assertStringContainsString('https://console.anthropic.com/settings/keys', $html);

        // kei.ia has no confirmed public documentation - must not fabricate a link for it.
        $keiSection = substr($html, (int) strpos($html, 'kei.ia'));
        $keiSection = substr($keiSection, 0, (int) strpos($keiSection, '</p>'));
        $this->assertStringNotContainsString('<a href', $keiSection);
    }

    public function testGenerateArticlePageCreatesArticleAndEnqueuesScrapeJob(): void
    {
        $this->wpdb->categories['Tecnologia'] = 42;

        $articles = new ArticleRepository();
        $queue = new QueueManager(new Logger());
        $page = new GenerateArticlePage($articles, $queue);

        $_POST = [
            'rjs_action' => 'generate_article',
            'rjs_nonce'  => 'nonce-rjs_generate_article',
            'source_url' => 'https://example.com/noticia',
            'category_id' => '42',
            'assigned_llm' => 'anthropic',
            'assigned_image_source' => 'pexels',
            'publish_mode' => 'draft',
        ];

        $html = $page->render();

        $this->assertCount(1, $this->wpdb->articles);
        $article = reset($this->wpdb->articles);
        $this->assertSame('https://example.com/noticia', $article['source_url']);
        $this->assertSame('manual', $article['source_type']);
        $this->assertSame(42, $article['category_id']);
        $this->assertSame('Tecnologia', $article['category_name']);
        $this->assertSame('anthropic', $article['assigned_llm']);
        $this->assertSame('pexels', $article['assigned_image_source']);
        $this->assertSame('draft', $article['target_post_status']);

        $this->assertCount(1, $this->wpdb->queueRows);
        $this->assertSame('scrape', reset($this->wpdb->queueRows)['job_type']);
        $this->assertStringContainsString('criado e enviado', $html);
    }

    public function testGenerateArticlePageRejectsEmptyUrl(): void
    {
        $articles = new ArticleRepository();
        $queue = new QueueManager(new Logger());
        $page = new GenerateArticlePage($articles, $queue);

        $_POST = [
            'rjs_action' => 'generate_article',
            'rjs_nonce'  => 'nonce-rjs_generate_article',
            'source_url' => '',
        ];

        $html = $page->render();

        $this->assertSame([], $this->wpdb->articles);
        $this->assertStringContainsString('Informe uma URL valida', $html);
    }

    public function testGenerateArticlePageRequiresValidScheduledDateWhenSchedulingChosen(): void
    {
        $articles = new ArticleRepository();
        $queue = new QueueManager(new Logger());
        $page = new GenerateArticlePage($articles, $queue);

        $_POST = [
            'rjs_action' => 'generate_article',
            'rjs_nonce'  => 'nonce-rjs_generate_article',
            'source_url' => 'https://example.com/x',
            'publish_mode' => 'future',
            'scheduled_at' => '',
        ];

        $html = $page->render();

        $this->assertSame([], $this->wpdb->articles);
        $this->assertStringContainsString('data/hora valida', $html);
    }

    public function testGenerateArticlePageSchedulesWithValidDatetime(): void
    {
        $articles = new ArticleRepository();
        $queue = new QueueManager(new Logger());
        $page = new GenerateArticlePage($articles, $queue);

        $_POST = [
            'rjs_action' => 'generate_article',
            'rjs_nonce'  => 'nonce-rjs_generate_article',
            'source_url' => 'https://example.com/x',
            'publish_mode' => 'future',
            'scheduled_at' => '2026-09-01T14:30',
        ];

        $page->render();

        $article = reset($this->wpdb->articles);
        $this->assertSame('future', $article['target_post_status']);
        $this->assertSame('2026-09-01 14:30:00', $article['scheduled_at']);
    }
}

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
use RoboJackSparrow\Ai\LLMRouter;
use RoboJackSparrow\Ai\Memory\MemoryBuffer;
use RoboJackSparrow\Ai\Memory\MemoryStore;
use RoboJackSparrow\Ai\Providers\AnthropicProvider;
use RoboJackSparrow\Ai\Providers\DeepSeekProvider;
use RoboJackSparrow\Ai\Providers\GeminiProvider;
use RoboJackSparrow\Ai\Providers\GroqProvider;
use RoboJackSparrow\Ai\Providers\OpenAIProvider;
use RoboJackSparrow\Api\Controllers\ArticleController;
use RoboJackSparrow\Api\Controllers\LogController;
use RoboJackSparrow\Api\Controllers\QueueController;
use RoboJackSparrow\Api\Controllers\SettingsController;
use RoboJackSparrow\Api\Controllers\SourceController;
use RoboJackSparrow\Api\RestApi;
use RoboJackSparrow\Content\ContentEngine;
use RoboJackSparrow\Content\Faq\FaqExtractor;
use RoboJackSparrow\Content\Outline\OutlineGenerator;
use RoboJackSparrow\Content\Prompts\PromptLibrary;
use RoboJackSparrow\Content\Seo\SeoGenerator;
use RoboJackSparrow\Cron\CronManager;
use RoboJackSparrow\Cron\Handlers\RssCron;
use RoboJackSparrow\Database\Repositories\ArticleRepository;
use RoboJackSparrow\Database\Repositories\LogRepository;
use RoboJackSparrow\Database\Repositories\QueueRepository as QueueRepositoryModel;
use RoboJackSparrow\Database\Repositories\SettingRepository;
use RoboJackSparrow\Database\Repositories\SourceRepository;
use RoboJackSparrow\Database\Schema;
use RoboJackSparrow\Image\ImageEngine;
use RoboJackSparrow\Image\Providers\KeiIaProvider;
use RoboJackSparrow\Image\Providers\PexelsProvider;
use RoboJackSparrow\Image\Providers\PixabayProvider;
use RoboJackSparrow\Image\Providers\ReplicateProvider;
use RoboJackSparrow\Image\Providers\UnsplashProvider;
use RoboJackSparrow\Publisher\WordPress\MediaUploader;
use RoboJackSparrow\Publisher\WordPress\PostPublisher;
use RoboJackSparrow\Publisher\WordPress\SeoIntegrator;
use RoboJackSparrow\Publisher\WordPress\TaxonomyManager;
use RoboJackSparrow\Queue\Jobs\JobRegistry;
use RoboJackSparrow\Queue\QueueManager;
use RoboJackSparrow\Queue\Worker;
use RoboJackSparrow\Research\ResearchEngine;
use RoboJackSparrow\Research\TavilyDriver;
use RoboJackSparrow\Scraper\Drivers\FirecrawlDriver;
use RoboJackSparrow\Scraper\Drivers\JinaAiDriver;
use RoboJackSparrow\Scraper\Rss\RssParser;
use RoboJackSparrow\Scraper\ScraperEngine;

final class Plugin
{
    private static ?Plugin $instance = null;

    private Logger $logger;
    private QueueManager $queueManager;
    private Worker $worker;
    private CronManager $cronManager;
    private RestApi $restApi;
    private JobRegistry $jobRegistry;
    private RssCron $rssCron;
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

        $settings = new SettingRepository(new Encryption(), $this->logger);

        $this->restApi = $this->buildRestApi($settings);
        $this->jobRegistry = $this->buildJobRegistry($settings);
        $this->rssCron = new RssCron(
            new SourceRepository(),
            new RssParser(),
            new ArticleRepository(),
            $this->queueManager,
            $this->logger
        );

        $this->registerHooks();

        if (is_admin()) {
            $this->admin = $this->buildAdmin($settings);
            $this->admin->register();
        }
    }

    /**
     * REST routes must be registered regardless of is_admin() - REST API
     * requests are not considered "admin" context by WordPress.
     */
    private function buildRestApi(SettingRepository $settings): RestApi
    {
        return new RestApi(
            new ArticleController(new ArticleRepository()),
            new QueueController(new QueueRepositoryModel()),
            new SettingsController($settings),
            new LogController(new LogRepository()),
            new SourceController(new SourceRepository())
        );
    }

    private function buildAdmin(SettingRepository $settings): Admin
    {
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

    /**
     * Wires the Queue -> {Scraper, Research, Content, Image, Publisher}
     * pipeline: without this, jobs popped by the Worker have no registered
     * handler and always fail (see JobRegistry's docblock).
     */
    private function buildJobRegistry(SettingRepository $settings): JobRegistry
    {
        $health = new HealthMonitor();

        $llmProviders = [
            'openai'    => new OpenAIProvider((string) $settings->get('rjs_openai_api_key', '')),
            'anthropic' => new AnthropicProvider((string) $settings->get('rjs_anthropic_api_key', '')),
            'groq'      => new GroqProvider((string) $settings->get('rjs_groq_api_key', '')),
            'gemini'    => new GeminiProvider((string) $settings->get('rjs_gemini_api_key', '')),
            'deepseek'  => new DeepSeekProvider((string) $settings->get('rjs_deepseek_api_key', '')),
        ];
        $llmRouter = new LLMRouter($llmProviders, $health, $this->logger);

        // JinaAiDriver works keyless (lower rate limit); Firecrawl is the
        // fallback and uses rjs_firecrawl_api_key from the API Keys page.
        $scraperEngine = new ScraperEngine(
            [
                new JinaAiDriver(),
                new FirecrawlDriver((string) $settings->get('rjs_firecrawl_api_key', '')),
            ],
            $this->logger
        );

        $research = new ResearchEngine(
            new TavilyDriver((string) $settings->get('rjs_tavily_api_key', '')),
            $this->logger
        );

        $prompts = new PromptLibrary();
        $contentEngine = new ContentEngine(
            $llmRouter,
            new MemoryBuffer(new MemoryStore()),
            new SeoGenerator(),
            new FaqExtractor($llmRouter, $prompts),
            new OutlineGenerator(),
            $prompts,
            $settings,
            $this->logger
        );

        $imageProviders = [
            new KeiIaProvider((string) $settings->get('rjs_kei_api_key', '')),
            new ReplicateProvider((string) $settings->get('rjs_replicate_api_key', '')),
            new UnsplashProvider((string) $settings->get('rjs_unsplash_api_key', '')),
            new PexelsProvider((string) $settings->get('rjs_pexels_api_key', '')),
            new PixabayProvider((string) $settings->get('rjs_pixabay_api_key', '')),
        ];
        $imageEngine = new ImageEngine($imageProviders, $this->logger);

        $postPublisher = new PostPublisher(
            new TaxonomyManager(),
            new MediaUploader(),
            new SeoIntegrator(),
            $this->logger
        );

        return new JobRegistry(
            new ArticleRepository(),
            $this->queueManager,
            $scraperEngine,
            $research,
            $contentEngine,
            $imageEngine,
            $postPublisher,
            $this->logger
        );
    }

    private function registerHooks(): void
    {
        $this->cronManager->register();
        $this->restApi->register();
        $this->jobRegistry->register();
        $this->rssCron->register();

        add_action('rjs_worker_process', [$this->worker, 'processNextBatch']);
        add_action('init', [$this, 'loadTextdomain']);
    }

    public function getRestApi(): RestApi
    {
        return $this->restApi;
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

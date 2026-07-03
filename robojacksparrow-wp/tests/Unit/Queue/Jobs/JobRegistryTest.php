<?php

declare(strict_types=1);

namespace RoboJackSparrow\Tests\Unit\Queue\Jobs;

use RoboJackSparrow\Ai\Contracts\LLMProviderInterface;
use RoboJackSparrow\Ai\Dto\LLMRequest;
use RoboJackSparrow\Ai\Dto\LLMResponse;
use RoboJackSparrow\Ai\HealthMonitor;
use RoboJackSparrow\Ai\LLMException;
use RoboJackSparrow\Ai\LLMRouter;
use RoboJackSparrow\Ai\Memory\MemoryBuffer;
use RoboJackSparrow\Ai\Memory\MemoryStore;
use RoboJackSparrow\Content\ContentEngine;
use RoboJackSparrow\Content\Faq\FaqExtractor;
use RoboJackSparrow\Content\Outline\OutlineGenerator;
use RoboJackSparrow\Content\Prompts\PromptLibrary;
use RoboJackSparrow\Content\Seo\SeoGenerator;
use RoboJackSparrow\Core\Encryption;
use RoboJackSparrow\Core\Logger;
use RoboJackSparrow\Database\Repositories\ArticleRepository;
use RoboJackSparrow\Database\Repositories\SettingRepository;
use RoboJackSparrow\Image\Contracts\ImageProviderInterface;
use RoboJackSparrow\Image\Dto\ImageAttribution;
use RoboJackSparrow\Image\Dto\ImageRequest;
use RoboJackSparrow\Image\Dto\ImageResult;
use RoboJackSparrow\Image\ImageEngine;
use RoboJackSparrow\Publisher\WordPress\MediaUploader;
use RoboJackSparrow\Publisher\WordPress\PostPublisher;
use RoboJackSparrow\Publisher\WordPress\SeoIntegrator;
use RoboJackSparrow\Publisher\WordPress\TaxonomyManager;
use RoboJackSparrow\Queue\Jobs\JobRegistry;
use RoboJackSparrow\Queue\QueueManager;
use RoboJackSparrow\Queue\Worker;
use RoboJackSparrow\Research\ResearchEngine;
use RoboJackSparrow\Research\TavilyDriver;
use RoboJackSparrow\Scraper\Contracts\ScraperDriverInterface;
use RoboJackSparrow\Scraper\Dto\ScrapedContent;
use RoboJackSparrow\Scraper\ScraperEngine;
use RoboJackSparrow\Tests\TestCase;

/**
 * End-to-end proof that the Queue's job types are actually wired to real
 * handlers (the code-review finding this fixes: previously
 * Worker::dispatch() had nothing registered for any job type, so every job
 * failed immediately and the scrape -> content -> image -> publish chain
 * never ran). Drives the whole chain through the real Worker/QueueManager,
 * only faking the external HTTP-touching leaf classes (scraper driver, LLM
 * provider, image provider).
 */
final class JobRegistryTest extends TestCase
{
    public function testFullChainScrapeToPublishRunsEndToEnd(): void
    {
        $this->wpdb->articles[1] = [
            'id'          => 1,
            'source_type' => 'scraper',
            'source_url'  => 'https://example.com/original-article',
            'status'      => 'pending',
            'priority'    => 5,
            'created_at'  => '2026-01-01 00:00:00',
        ];

        $articles = new ArticleRepository();
        $queue = new QueueManager(new Logger());
        $logger = new Logger();

        $scraper = new ScraperEngine([new FakeScraper()], $logger);
        $research = new ResearchEngine(new TavilyDriver(''), $logger); // no key -> fallback from scraped content
        $settings = new SettingRepository(new Encryption(), $logger);

        $llmProvider = new FakeLlmProvider();
        $llmRouter = new LLMRouter(['test' => $llmProvider], new HealthMonitor(), $logger);
        $prompts = new PromptLibrary();
        $content = new ContentEngine(
            $llmRouter,
            new MemoryBuffer(new MemoryStore()),
            new SeoGenerator(),
            new FaqExtractor($llmRouter, $prompts),
            new OutlineGenerator(),
            $prompts,
            $settings,
            $logger
        );

        $image = new ImageEngine([new FakeImageProvider()], $logger);

        $publisher = new PostPublisher(new TaxonomyManager(), new MediaUploader(), new SeoIntegrator(), $logger);

        $registry = new JobRegistry($articles, $queue, $scraper, $research, $content, $image, $publisher, $logger);
        $registry->register();

        $queue->enqueue(1, 'scrape');

        $worker = new Worker($queue, $logger, batchSize: 1);

        // Each stage enqueues the next job type; run the worker once per
        // stage (scrape -> generate_content -> generate_image -> publish).
        for ($i = 0; $i < 4; $i++) {
            $worker->processNextBatch();
        }

        $article = $articles->find(1);

        $this->assertSame('published', $article->status);
        $this->assertNotEmpty($article->generated_content);
        $this->assertStringContainsString('Conteudo gerado', $article->generated_content);
        $this->assertSame('Titulo Gerado', $article->source_title, 'the generated (SEO-optimized) title must be persisted and used for publishing');
        $this->assertNotNull($article->wordpress_post_id);
        $this->assertSame('Titulo Gerado', $this->wpdb->posts[$article->wordpress_post_id]['post_title']);
        $this->assertSame(500, $article->featured_image_id, 'the generated image must have been attached as the featured image');

        // Every job in the chain must have completed, none stuck/failed.
        $statuses = array_column($this->wpdb->queueRows, 'status');
        $this->assertSame(['completed', 'completed', 'completed', 'completed'], $statuses);
    }

    public function testMissingArticleThrowsInsteadOfSilentlyFailing(): void
    {
        $articles = new ArticleRepository();
        $queue = new QueueManager(new Logger());
        $logger = new Logger();
        $settings = new SettingRepository(new Encryption(), $logger);
        $prompts = new PromptLibrary();
        $llmRouter = new LLMRouter([], new HealthMonitor(), $logger);

        $registry = new JobRegistry(
            $articles,
            $queue,
            new ScraperEngine([], $logger),
            new ResearchEngine(null, $logger),
            new ContentEngine($llmRouter, new MemoryBuffer(new MemoryStore()), new SeoGenerator(), new FaqExtractor($llmRouter, $prompts), new OutlineGenerator(), $prompts, $settings, $logger),
            new ImageEngine([], $logger),
            new PostPublisher(new TaxonomyManager(), new MediaUploader(), new SeoIntegrator(), $logger),
            $logger
        );

        $this->expectException(\RoboJackSparrow\Queue\Jobs\JobHandlerException::class);
        $registry->handleScrape(null, \RoboJackSparrow\Queue\Job::fromRow((object) [
            'id' => 1, 'article_id' => 999, 'job_type' => 'scrape', 'job_payload' => '{}', 'attempts' => 0, 'max_attempts' => 3, 'status' => 'processing',
        ]));
    }
}

final class FakeScraper implements ScraperDriverInterface
{
    public function getName(): string
    {
        return 'fake';
    }

    public function scrape(string $url): ScrapedContent
    {
        return new ScrapedContent(
            url: $url,
            title: 'Noticia Original',
            text: 'Um robo foi anunciado hoje. Muito interessante.',
            html: '<h1>Noticia Original</h1><p>Um robo foi anunciado hoje.</p>'
        );
    }
}

final class FakeLlmProvider implements LLMProviderInterface
{
    public function getName(): string
    {
        return 'test';
    }

    public function send(LLMRequest $request): LLMResponse
    {
        $prompt = $request->getPrompt();

        if (str_contains($prompt, 'Crie a estrutura de um artigo original')) {
            return new LLMResponse(json_encode([
                'title'            => 'Titulo Gerado',
                'meta_description' => 'Descricao gerada.',
                'sections'         => [['title' => 'Introducao', 'level' => 2]],
                'focus_keywords'   => ['robo'],
                'image_prompt'     => 'a robot',
            ]), 100, 80, 20, 'test-model', 'stop', []);
        }

        if (str_contains($prompt, 'Voce esta escrevendo a secao')) {
            return new LLMResponse('<p>Conteudo gerado para a secao.</p>', 50, 30, 20, 'test-model', 'stop', []);
        }

        if (str_contains($prompt, 'extraia de 3 a 6 perguntas')) {
            return new LLMResponse(json_encode([]), 10, 5, 5, 'test-model', 'stop', []);
        }

        throw new LLMException('Unexpected prompt: ' . substr($prompt, 0, 60));
    }
}

final class FakeImageProvider implements ImageProviderInterface
{
    public function getName(): string
    {
        return 'fake-image';
    }

    public function generate(ImageRequest $request): ImageResult
    {
        return new ImageResult(
            binaryData: 'fake-binary-image-data',
            mimeType: 'image/png',
            provider: 'fake-image',
            attribution: new ImageAttribution(sourceName: 'Fake')
        );
    }
}

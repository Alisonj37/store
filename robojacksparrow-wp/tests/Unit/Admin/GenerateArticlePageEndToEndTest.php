<?php

declare(strict_types=1);

namespace RoboJackSparrow\Tests\Unit\Admin;

use RoboJackSparrow\Admin\Menu\ArticlesPage;
use RoboJackSparrow\Admin\Menu\GenerateArticlePage;
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
 * Reproduces the user-reported bug: submitting "Gerar Artigo" enqueued the
 * job and returned a generic "sent to the queue" message with no visible
 * start/finish or a way to open the result, since WP-Cron only picks jobs
 * up passively. GenerateArticlePage now drains the queue synchronously
 * (given a Worker) so the same request reports the final outcome.
 */
final class GenerateArticlePageEndToEndTest extends TestCase
{
    private function buildWorker(QueueManager $queue, ArticleRepository $articles, Logger $logger): Worker
    {
        $research = new ResearchEngine(new TavilyDriver(''), $logger);
        $settings = new SettingRepository(new Encryption(), $logger);
        $llmRouter = new LLMRouter(['test' => new FakeLlmProvider()], new HealthMonitor(), $logger);
        $prompts = new PromptLibrary();
        $content = new ContentEngine($llmRouter, new MemoryBuffer(new MemoryStore()), new SeoGenerator(), new FaqExtractor($llmRouter, $prompts), new OutlineGenerator(), $prompts, $settings, $logger);
        $image = new ImageEngine([new FakeImageProvider()], $logger);
        $publisher = new PostPublisher(new TaxonomyManager(), new MediaUploader(), new SeoIntegrator(), $logger);
        $scraper = new ScraperEngine([new FakeScraper()], $logger);

        $registry = new JobRegistry($articles, $queue, $scraper, $research, $content, $image, $publisher, $logger);
        $registry->register();

        return new Worker($queue, $logger);
    }

    public function testSubmittingAUrlSynchronouslyDrainsTheQueueAndShowsEditAndViewLinks(): void
    {
        $logger = new Logger();
        $articles = new ArticleRepository();
        $queue = new QueueManager($logger);
        $worker = $this->buildWorker($queue, $articles, $logger);

        $page = new GenerateArticlePage($articles, $queue, $worker);

        $_POST = [
            'rjs_action' => 'generate_article',
            'rjs_nonce'  => 'nonce-rjs_generate_article',
            'source_url' => 'https://example.com/original-article',
        ];

        $html = $page->render();

        $this->assertStringContainsString('publicado com sucesso', $html);
        $this->assertStringContainsString('Editar', $html);
        $this->assertStringContainsString('Ver artigo', $html);
        $this->assertStringContainsString('post.php?post=', $html, 'must link to the real WP edit screen for the published post');

        $article = reset($this->wpdb->articles);
        $this->assertSame('published', $article['status']);
    }

    public function testWithoutAWorkerFallsBackToTheQueuedStatusNotice(): void
    {
        $logger = new Logger();
        $articles = new ArticleRepository();
        $queue = new QueueManager($logger);

        // No Worker wired (mirrors any caller that doesn't want synchronous
        // processing) - must not claim success it never verified.
        $page = new GenerateArticlePage($articles, $queue, null);

        $_POST = [
            'rjs_action' => 'generate_article',
            'rjs_nonce'  => 'nonce-rjs_generate_article',
            'source_url' => 'https://example.com/x',
        ];

        $html = $page->render();

        $this->assertStringNotContainsString('publicado com sucesso', $html);
        $this->assertStringContainsString('status atual: pending', $html);
    }

    public function testArticlesPageGerarButtonSynchronouslyProcessesAndShowsEditViewLinks(): void
    {
        $logger = new Logger();
        $articles = new ArticleRepository();
        $queue = new QueueManager($logger);
        $worker = $this->buildWorker($queue, $articles, $logger);

        $this->wpdb->articles[1] = [
            'id' => 1, 'source_type' => 'rss', 'source_url' => 'https://example.com/original-article',
            'status' => 'pending', 'priority' => 5, 'created_at' => '2026-01-01 00:00:00',
        ];

        $page = new ArticlesPage($articles, $queue, $worker);

        $_POST = ['rjs_action' => 'generate_now', 'rjs_nonce' => 'nonce-rjs_articles', 'article_id' => '1'];
        $html = $page->render();

        $this->assertStringContainsString('publicado com sucesso', $html);
        $this->assertStringContainsString('Editar', $html);
        $this->assertStringContainsString('Ver artigo', $html);

        // The listing itself must also offer Editar/Ver for the now-published row.
        $_POST = [];
        $listingHtml = $page->render();
        $this->assertStringContainsString('>Editar<', $listingHtml);
        $this->assertStringContainsString('>Ver<', $listingHtml);
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

<?php

declare(strict_types=1);

namespace RoboJackSparrow\Queue\Jobs;

use DateTimeImmutable;
use RoboJackSparrow\Content\ContentEngine;
use RoboJackSparrow\Content\Linking\LinkInjector;
use RoboJackSparrow\Core\Logger;
use RoboJackSparrow\Database\Repositories\ArticleRepository;
use RoboJackSparrow\Image\Dto\ImageAttribution;
use RoboJackSparrow\Image\Dto\ImageRequest;
use RoboJackSparrow\Image\Dto\ImageResult;
use RoboJackSparrow\Image\ImageEngine;
use RoboJackSparrow\Publisher\Dto\PublishRequest;
use RoboJackSparrow\Publisher\WordPress\PostPublisher;
use RoboJackSparrow\Queue\Job;
use RoboJackSparrow\Queue\QueueManager;
use RoboJackSparrow\Research\ResearchEngine;
use RoboJackSparrow\Scraper\Dto\ScrapedContent;
use RoboJackSparrow\Scraper\ScraperEngine;

/**
 * Conecta os tipos de job da fila (scrape -> generate_content ->
 * generate_image -> publish) as engines das Fases 2-7 via o mecanismo de
 * extensao rjs_dispatch_job_{tipo} do Worker (Fase 1). O resultado de cada
 * etapa viaja para a proxima via job_payload (JSON) em vez de colunas
 * novas no schema: o conteudo raspado vai no payload do job de conteudo,
 * a imagem gerada viaja em base64 ate o job de publicacao.
 *
 * process_rss (criacao dos artigos a partir de feeds RSS) fica fora desta
 * cadeia - depende dos handlers de Cron (src/Cron/Handlers), ainda nao
 * implementados.
 */
class JobRegistry
{
    public function __construct(
        private ArticleRepository $articles,
        private QueueManager $queue,
        private ScraperEngine $scraper,
        private ResearchEngine $research,
        private ContentEngine $content,
        private ImageEngine $image,
        private PostPublisher $publisher,
        private Logger $logger
    ) {
    }

    public function register(): void
    {
        add_filter('rjs_dispatch_job_scrape', [$this, 'handleScrape'], 10, 2);
        add_filter('rjs_dispatch_job_generate_content', [$this, 'handleGenerateContent'], 10, 2);
        add_filter('rjs_dispatch_job_generate_image', [$this, 'handleGenerateImage'], 10, 2);
        add_filter('rjs_dispatch_job_publish', [$this, 'handlePublish'], 10, 2);
    }

    public function handleScrape(mixed $handled, Job $job): bool
    {
        $article = $this->articles->find($job->getArticleId());
        if ($article === null) {
            throw new JobHandlerException("Article {$job->getArticleId()} not found for scrape job");
        }

        $scraped = $this->scraper->scrape((string) $article->source_url);

        $this->queue->updateArticleStatus($job->getArticleId(), 'queued_content');
        $this->queue->enqueue($job->getArticleId(), 'generate_content', [
            'scraped_title' => $scraped->getTitle(),
            'scraped_text'  => $scraped->getText(),
            'scraped_html'  => $scraped->getHtml(),
            'scraped_url'   => $scraped->getUrl(),
        ]);

        return true;
    }

    public function handleGenerateContent(mixed $handled, Job $job): bool
    {
        $article = $this->articles->find($job->getArticleId());
        if ($article === null) {
            throw new JobHandlerException("Article {$job->getArticleId()} not found for generate_content job");
        }

        $payload = $job->getPayload();

        $source = new ScrapedContent(
            url: (string) ($payload['scraped_url'] ?? ''),
            title: (string) ($payload['scraped_title'] ?? ''),
            text: (string) ($payload['scraped_text'] ?? ''),
            html: isset($payload['scraped_html']) ? (string) $payload['scraped_html'] : null
        );

        $researchData = $this->research->enrich($source);
        $generated = $this->content->generate(
            $job->getArticleId(),
            $source,
            $researchData,
            $this->articleOverride($article->assigned_llm ?? null)
        );

        $relatedArticles = $this->articles->findRelatedPublished($job->getArticleId(), $article->category_name ?? null);
        $htmlContent = (new LinkInjector())->appendInternalLinks($generated->getHtmlContent(), $relatedArticles);

        $this->articles->update($job->getArticleId(), [
            // No dedicated "generated title" column exists on the articles
            // table; source_title is repurposed to hold the final,
            // SEO-optimized title ContentEngine produced (distinct from
            // the *original* scraped title passed into generate() above),
            // since it is what handlePublish() uses as the WP post title.
            'source_title'        => $generated->getTitle(),
            'generated_content'   => $htmlContent,
            'seo_title'           => $generated->getSeoTitle(),
            'seo_description'     => $generated->getSeoDescription(),
            'seo_schema'          => wp_json_encode($generated->getSchemaArticle()),
            'faq_schema'          => wp_json_encode($generated->getSchemaFaq()),
            'tags'                => wp_json_encode($generated->getFocusKeywords()),
            'image_prompt'        => $generated->getImagePrompt(),
            'content_tokens_used' => $generated->getTokensUsed(),
            'status'              => 'queued_image',
        ]);

        $this->queue->enqueue($job->getArticleId(), 'generate_image', [
            'image_prompt' => $generated->getImagePrompt() ?? $generated->getTitle(),
        ]);

        return true;
    }

    public function handleGenerateImage(mixed $handled, Job $job): bool
    {
        $article = $this->articles->find($job->getArticleId());
        if ($article === null) {
            throw new JobHandlerException("Article {$job->getArticleId()} not found for generate_image job");
        }

        $payload = $job->getPayload();
        $prompt = trim((string) ($payload['image_prompt'] ?? ''));

        if ($prompt === '') {
            // Nothing to generate an image from: skip straight to publish
            // without a featured image rather than failing the whole article.
            $this->articles->update($job->getArticleId(), ['status' => 'queued_publish']);
            $this->queue->enqueue($job->getArticleId(), 'publish', []);

            return true;
        }

        $result = $this->image->generate(
            new ImageRequest($prompt),
            $this->articleOverride($article->assigned_image_source ?? null)
        );

        $this->articles->update($job->getArticleId(), ['status' => 'queued_publish']);
        $this->queue->enqueue($job->getArticleId(), 'publish', [
            'image_base64'      => base64_encode($result->getBinaryData()),
            'image_mime'        => $result->getMimeType(),
            'image_provider'    => $result->getProvider(),
            'image_source_url'  => $result->getSourceUrl(),
            'image_author'      => $result->getAttribution()->getAuthorName(),
            'image_source_name' => $result->getAttribution()->getSourceName(),
        ]);

        return true;
    }

    public function handlePublish(mixed $handled, Job $job): bool
    {
        $article = $this->articles->find($job->getArticleId());
        if ($article === null) {
            throw new JobHandlerException("Article {$job->getArticleId()} not found for publish job");
        }

        $postStatus = (string) ($article->target_post_status ?? 'publish');
        $scheduledAt = $postStatus === 'future' ? $this->parseScheduledAt($article->scheduled_at ?? null) : null;

        // A 'future' status with no parseable date can never actually
        // publish through wp_insert_post; fall back to a draft rather than
        // silently losing the article in scheduling limbo.
        if ($postStatus === 'future' && $scheduledAt === null) {
            $postStatus = 'draft';
        }

        $request = new PublishRequest(
            title: (string) $article->source_title,
            htmlContent: (string) $article->generated_content,
            seoTitle: (string) ($article->seo_title ?? ''),
            seoDescription: (string) ($article->seo_description ?? ''),
            schemaArticle: $this->decodeJsonColumn($article->seo_schema ?? null),
            schemaFaq: $this->decodeJsonColumn($article->faq_schema ?? null),
            focusKeywords: $this->decodeJsonColumn($article->tags ?? null),
            tags: $this->decodeJsonColumn($article->tags ?? null),
            categoryName: $article->category_name ?? null,
            featuredImage: $this->buildFeaturedImage($job->getPayload()),
            postType: (string) ($article->custom_post_type ?? 'post'),
            postStatus: $postStatus,
            scheduledAt: $scheduledAt
        );

        $result = $this->publisher->publish($request);

        $this->articles->update($job->getArticleId(), [
            'wordpress_post_id'  => $result->getPostId(),
            'wordpress_post_url' => $result->getPostUrl(),
            'featured_image_id'  => $result->getFeaturedImageId(),
            'status'             => 'published',
            'completed_at'       => current_time('mysql'),
        ]);

        return true;
    }

    private function parseScheduledAt(?string $value): ?DateTimeImmutable
    {
        if ($value === null || trim($value) === '') {
            return null;
        }

        $parsed = DateTimeImmutable::createFromFormat('Y-m-d H:i:s', $value);

        return $parsed !== false ? $parsed : null;
    }

    private function buildFeaturedImage(array $payload): ?ImageResult
    {
        if (!isset($payload['image_base64'])) {
            return null;
        }

        $binary = base64_decode((string) $payload['image_base64'], true);
        if ($binary === false) {
            $this->logger->warning('Could not decode featured image payload for publish job');

            return null;
        }

        return new ImageResult(
            binaryData: $binary,
            mimeType: (string) ($payload['image_mime'] ?? 'image/png'),
            provider: (string) ($payload['image_provider'] ?? ''),
            attribution: new ImageAttribution(
                authorName: $payload['image_author'] ?? null,
                sourceName: $payload['image_source_name'] ?? null
            ),
            sourceUrl: $payload['image_source_url'] ?? null
        );
    }

    /**
     * assigned_llm/assigned_image_source default to 'auto' on the articles
     * table, meaning "no article-level preference"; translate that (and
     * empty/null) into null so the engines fall back to the global setting.
     */
    private function articleOverride(?string $value): ?string
    {
        $value = trim((string) $value);

        return ($value === '' || $value === 'auto') ? null : $value;
    }

    private function decodeJsonColumn(?string $json): array
    {
        if ($json === null || $json === '') {
            return [];
        }

        $decoded = json_decode($json, true);

        return is_array($decoded) ? $decoded : [];
    }
}

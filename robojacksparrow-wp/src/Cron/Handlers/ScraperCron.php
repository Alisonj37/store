<?php

declare(strict_types=1);

namespace RoboJackSparrow\Cron\Handlers;

use RoboJackSparrow\Core\Logger;
use RoboJackSparrow\Cron\ScrapeFrequency;
use RoboJackSparrow\Database\Repositories\ArticleRepository;
use RoboJackSparrow\Database\Repositories\SettingRepository;
use RoboJackSparrow\Database\Repositories\SourceRepository;
use RoboJackSparrow\Publisher\WordPress\CategoryNameResolver;
use RoboJackSparrow\Queue\QueueManager;
use RoboJackSparrow\Scraper\SiteLinkDiscovery;
use Throwable;

/**
 * Coleta periodica a partir de fontes do tipo 'scraper' (uma URL de site
 * qualquer, nao um feed RSS) - hook rjs_scraper_collect, agendado a cada 15
 * minutos por CronManager. Complementa RssCron para sites sem feed RSS: usa
 * SiteLinkDiscovery para achar links novos na propria pagina (ou seguir um
 * feed declarado nela, quando existe) e cria um artigo por link inedito,
 * entrando na mesma cadeia de fila (scrape -> generate_content ->
 * generate_image -> publish, ver JobRegistry).
 *
 * Mesmo gate de 'rjs_autopilot_enabled' usado por RssCron: desligado, o
 * artigo fica pendente para geracao manual em vez de ser enfileirado.
 */
class ScraperCron
{
    private const MAX_LINKS_PER_SOURCE = 10;

    public function __construct(
        private SourceRepository $sources,
        private SiteLinkDiscovery $discovery,
        private ArticleRepository $articles,
        private QueueManager $queue,
        private SettingRepository $settings,
        private Logger $logger
    ) {
    }

    public function register(): void
    {
        add_action('rjs_scraper_collect', [$this, 'collect']);
    }

    public function collect(): void
    {
        foreach ($this->sources->findAll() as $source) {
            if ((int) $source->is_active !== 1 || $source->source_type !== 'scraper') {
                continue;
            }

            if (!ScrapeFrequency::isDue($source->last_scraped_at ?? null, (string) $source->scrape_frequency)) {
                continue;
            }

            try {
                $this->collectFromSource($source);
            } catch (Throwable $e) {
                $this->logger->error('Site scraper collection failed for source', [
                    'source_id'  => $source->id,
                    'source_url' => $source->source_url,
                    'error'      => $e->getMessage(),
                ]);
            }
        }
    }

    private function collectFromSource(object $source): void
    {
        $links = $this->discovery->discoverArticleLinks($source->source_url, self::MAX_LINKS_PER_SOURCE);
        $newCount = 0;
        $autopilotEnabled = (string) $this->settings->get('rjs_autopilot_enabled', '0') === '1';
        $autopilotPublishStatus = (string) $this->settings->get('rjs_autopilot_publish_status', 'draft');

        foreach ($links as $link) {
            $link = trim($link);

            if ($link === '' || $this->articles->existsBySourceUrl($link)) {
                continue;
            }

            $articleId = $this->articles->create([
                'source_type'        => 'scraper',
                'source_url'         => $link,
                'status'             => 'pending',
                'category_id'        => $source->category_id,
                'category_name'      => CategoryNameResolver::nameFor($source->category_id ?? null),
                'priority'           => 5,
                'target_post_status' => $autopilotEnabled ? $autopilotPublishStatus : 'draft',
            ]);

            if ($autopilotEnabled) {
                $this->queue->enqueue($articleId, 'scrape');
            }

            $newCount++;
        }

        $this->sources->update((int) $source->id, ['last_scraped_at' => current_time('mysql', true)]);

        $this->logger->info('Site scraper collection completed', [
            'source_id'    => $source->id,
            'new_articles' => $newCount,
        ]);
    }
}

<?php

declare(strict_types=1);

namespace RoboJackSparrow\Cron\Handlers;

use RoboJackSparrow\Core\Logger;
use RoboJackSparrow\Cron\ScrapeFrequency;
use RoboJackSparrow\Database\Repositories\ArticleRepository;
use RoboJackSparrow\Database\Repositories\SettingRepository;
use RoboJackSparrow\Database\Repositories\SourceRepository;
use RoboJackSparrow\Queue\QueueManager;
use RoboJackSparrow\Scraper\Rss\RssParser;
use Throwable;

/**
 * Coleta periodica de feeds RSS (hook rjs_rss_collect, agendado a cada 15
 * minutos por CronManager - o tick e so o intervalo minimo possivel; cada
 * fonte so e realmente coletada quando seu proprio scrape_frequency ja
 * venceu, ver ScrapeFrequency::isDue()). Para cada fonte RSS ativa e no
 * horario, verifica itens novos comparando source_url (RSS nem sempre
 * garante um guid estavel entre execucoes) e cria um artigo para cada item
 * inedito, entrando na mesma cadeia da fila que artigos criados
 * manualmente ou via REST API (scrape -> generate_content ->
 * generate_image -> publish, ver JobRegistry).
 *
 * Quando 'rjs_autopilot_enabled' esta desligado, o artigo e criado mas o
 * job 'scrape' NAO e enfileirado automaticamente - fica pendente ate um
 * clique manual em "Gerar" na pagina Artigos (ver ArticlesPage).
 */
class RssCron
{
    public function __construct(
        private SourceRepository $sources,
        private RssParser $parser,
        private ArticleRepository $articles,
        private QueueManager $queue,
        private SettingRepository $settings,
        private Logger $logger
    ) {
    }

    public function register(): void
    {
        add_action('rjs_rss_collect', [$this, 'collect']);
    }

    public function collect(): void
    {
        foreach ($this->sources->findAll() as $source) {
            if ((int) $source->is_active !== 1 || $source->source_type !== 'rss') {
                continue;
            }

            if (!ScrapeFrequency::isDue($source->last_scraped_at ?? null, (string) $source->scrape_frequency)) {
                continue;
            }

            try {
                $this->collectFromSource($source);
            } catch (Throwable $e) {
                $this->logger->error('RSS collection failed for source', [
                    'source_id'  => $source->id,
                    'source_url' => $source->source_url,
                    'error'      => $e->getMessage(),
                ]);
            }
        }
    }

    private function collectFromSource(object $source): void
    {
        $items = $this->parser->parseFeed($source->source_url);
        $newCount = 0;
        $autopilotEnabled = (string) $this->settings->get('rjs_autopilot_enabled', '0') === '1';
        $autopilotPublishStatus = (string) $this->settings->get('rjs_autopilot_publish_status', 'draft');

        foreach ($items as $item) {
            $link = $item->getLink();

            if ($link === '' || $this->articles->existsBySourceUrl($link)) {
                continue;
            }

            $articleId = $this->articles->create([
                'source_type'        => 'rss',
                'source_url'         => $link,
                'source_title'       => $item->getTitle(),
                'rss_feed_url'       => $source->source_url,
                'status'             => 'pending',
                'category_id'        => $source->category_id,
                'priority'           => 5,
                'target_post_status' => $autopilotEnabled ? $autopilotPublishStatus : 'draft',
            ]);

            if ($autopilotEnabled) {
                $this->queue->enqueue($articleId, 'scrape');
            }

            $newCount++;
        }

        $this->sources->update((int) $source->id, ['last_scraped_at' => current_time('mysql', true)]);

        $this->logger->info('RSS collection completed', [
            'source_id'    => $source->id,
            'new_articles' => $newCount,
        ]);
    }
}

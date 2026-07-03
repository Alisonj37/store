<?php

declare(strict_types=1);

namespace RoboJackSparrow\Cron\Handlers;

use RoboJackSparrow\Core\Logger;
use RoboJackSparrow\Database\Repositories\ArticleRepository;
use RoboJackSparrow\Database\Repositories\SourceRepository;
use RoboJackSparrow\Queue\QueueManager;
use RoboJackSparrow\Scraper\Rss\RssParser;
use Throwable;

/**
 * Coleta periodica de feeds RSS (hook rjs_rss_collect, agendado a cada 4
 * horas por CronManager). Para cada fonte RSS ativa, verifica itens novos
 * comparando source_url (RSS nem sempre garante um guid estavel entre
 * execucoes) e cria um artigo + job 'scrape' para cada item inedito,
 * entrando na mesma cadeia da fila que artigos criados manualmente ou via
 * REST API (scrape -> generate_content -> generate_image -> publish, ver
 * JobRegistry).
 */
class RssCron
{
    public function __construct(
        private SourceRepository $sources,
        private RssParser $parser,
        private ArticleRepository $articles,
        private QueueManager $queue,
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

        foreach ($items as $item) {
            $link = $item->getLink();

            if ($link === '' || $this->articles->existsBySourceUrl($link)) {
                continue;
            }

            $articleId = $this->articles->create([
                'source_type'  => 'rss',
                'source_url'   => $link,
                'source_title' => $item->getTitle(),
                'rss_feed_url' => $source->source_url,
                'status'       => 'pending',
                'category_id'  => $source->category_id,
                'priority'     => 5,
            ]);

            $this->queue->enqueue($articleId, 'scrape');
            $newCount++;
        }

        $this->sources->update((int) $source->id, ['last_scraped_at' => current_time('mysql')]);

        $this->logger->info('RSS collection completed', [
            'source_id'    => $source->id,
            'new_articles' => $newCount,
        ]);
    }
}

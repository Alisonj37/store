<?php

declare(strict_types=1);

namespace RoboJackSparrow\Scraper;

use RoboJackSparrow\Core\Logger;
use RoboJackSparrow\Scraper\Contracts\ScraperDriverInterface;
use RoboJackSparrow\Scraper\Dto\ScrapedContent;
use Throwable;

/**
 * Orquestra a cascata de scraper drivers (Jina.ai -> Firecrawl -> ...),
 * tentando cada um em ordem ate o primeiro sucesso.
 */
class ScraperEngine
{
    /**
     * @param ScraperDriverInterface[] $drivers Em ordem de preferencia da cascata.
     */
    public function __construct(
        private array $drivers,
        private Logger $logger
    ) {
    }

    public function scrape(string $url): ScrapedContent
    {
        $lastError = null;

        foreach ($this->drivers as $driver) {
            try {
                $content = $driver->scrape($url);

                $this->logger->info('Scrape successful', [
                    'driver' => $driver->getName(),
                    'url'    => $url,
                ]);

                return $content;
            } catch (Throwable $e) {
                $lastError = $e;
                $this->logger->warning('Scraper driver failed, trying next', [
                    'driver' => $driver->getName(),
                    'url'    => $url,
                    'error'  => $e->getMessage(),
                ]);
            }
        }

        throw new ScraperException("All scrapers failed for URL: {$url}. Last error: " . ($lastError?->getMessage() ?? 'unknown'));
    }
}

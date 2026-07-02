<?php

declare(strict_types=1);

namespace RoboJackSparrow\Scraper\Contracts;

use RoboJackSparrow\Scraper\Dto\ScrapedContent;
use RoboJackSparrow\Scraper\ScraperException;

interface ScraperDriverInterface
{
    public function getName(): string;

    /**
     * @throws ScraperException When the target cannot be scraped after retries.
     */
    public function scrape(string $url): ScrapedContent;
}

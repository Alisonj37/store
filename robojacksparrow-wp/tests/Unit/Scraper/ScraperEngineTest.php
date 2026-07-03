<?php

declare(strict_types=1);

namespace RoboJackSparrow\Tests\Unit\Scraper;

use RoboJackSparrow\Core\Logger;
use RoboJackSparrow\Scraper\Contracts\ScraperDriverInterface;
use RoboJackSparrow\Scraper\Dto\ScrapedContent;
use RoboJackSparrow\Scraper\ScraperEngine;
use RoboJackSparrow\Scraper\ScraperException;
use RoboJackSparrow\Tests\TestCase;

final class ScraperEngineTest extends TestCase
{
    public function testCascadesToTheNextDriverOnFailure(): void
    {
        $bad = new FakeScraperDriver('bad', shouldFail: true);
        $good = new FakeScraperDriver('good', shouldFail: false);

        $engine = new ScraperEngine([$bad, $good], new Logger());
        $content = $engine->scrape('https://example.com/article');

        $this->assertSame('good', $content->getScraperUsed());
        $this->assertSame(1, $bad->calls);
        $this->assertSame(1, $good->calls);
    }

    public function testThrowsAggregateExceptionWhenEveryDriverFails(): void
    {
        $engine = new ScraperEngine([
            new FakeScraperDriver('a', shouldFail: true),
            new FakeScraperDriver('b', shouldFail: true),
        ], new Logger());

        $this->expectException(ScraperException::class);
        $this->expectExceptionMessageMatches('/All scrapers failed/');
        $engine->scrape('https://example.com/article');
    }
}

final class FakeScraperDriver implements ScraperDriverInterface
{
    public int $calls = 0;

    public function __construct(private string $name, private bool $shouldFail)
    {
    }

    public function getName(): string
    {
        return $this->name;
    }

    public function scrape(string $url): ScrapedContent
    {
        $this->calls++;

        if ($this->shouldFail) {
            throw new ScraperException("simulated failure from {$this->name}");
        }

        return new ScrapedContent(url: $url, title: 'Title', text: 'Text', html: null, scraperUsed: $this->name);
    }
}

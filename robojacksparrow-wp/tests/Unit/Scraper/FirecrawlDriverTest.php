<?php

declare(strict_types=1);

namespace RoboJackSparrow\Tests\Unit\Scraper;

use RoboJackSparrow\Scraper\Drivers\FirecrawlDriver;
use RoboJackSparrow\Scraper\ScraperException;
use RoboJackSparrow\Tests\HttpFixtures;
use RoboJackSparrow\Tests\TestCase;

final class FirecrawlDriverTest extends TestCase
{
    public function testScrapeMapsMarkdownHtmlAndMetadata(): void
    {
        HttpFixtures::set('POST', 'https://api.firecrawl.dev/v1/scrape', [
            'response' => ['code' => 200],
            'body'     => json_encode([
                'data' => [
                    'markdown' => '# Title',
                    'html'     => '<h1>Title</h1>',
                    'metadata' => [
                        'title'         => 'Firecrawl Title',
                        'author'        => 'Jane Doe',
                        'publishedTime' => '2024-05-01T12:00:00Z',
                        'ogImage'       => 'https://example.com/og.png',
                    ],
                ],
            ]),
        ]);

        $content = (new FirecrawlDriver('fc-key'))->scrape('https://example.com/page');

        $this->assertSame('Firecrawl Title', $content->getTitle());
        $this->assertSame('Jane Doe', $content->getAuthor());
        $this->assertSame(['https://example.com/og.png'], $content->getImages());
        $this->assertSame('2024', $content->getPublishedAt()?->format('Y'));
    }

    public function testEmptyApiKeyIsRejectedWithoutHttpCall(): void
    {
        $this->expectException(ScraperException::class);
        (new FirecrawlDriver(''))->scrape('https://example.com/x');
    }

    public function testApiErrorSurfacesAsScraperException(): void
    {
        HttpFixtures::set('POST', 'https://api.firecrawl.dev/v1/scrape', [
            'response' => ['code' => 402],
            'body'     => json_encode(['error' => 'Payment required']),
        ]);

        $this->expectException(ScraperException::class);
        $this->expectExceptionMessageMatches('/Payment required/');
        (new FirecrawlDriver('fc-key'))->scrape('https://example.com/x');
    }
}

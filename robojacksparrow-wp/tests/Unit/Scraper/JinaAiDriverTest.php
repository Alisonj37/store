<?php

declare(strict_types=1);

namespace RoboJackSparrow\Tests\Unit\Scraper;

use RoboJackSparrow\Scraper\Drivers\JinaAiDriver;
use RoboJackSparrow\Scraper\ScraperException;
use RoboJackSparrow\Tests\HttpFixtures;
use RoboJackSparrow\Tests\TestCase;

final class JinaAiDriverTest extends TestCase
{
    public function testScrapeParsesTitleAndMarkdownContent(): void
    {
        HttpFixtures::set('GET', 'https://r.jina.ai/https://example.com/article', [
            'response' => ['code' => 200],
            'body'     => "Title: Example Article\nURL Source: https://example.com/article\nMarkdown Content:\n# Hello\n\nBody text.",
        ]);

        $driver = new JinaAiDriver();
        $content = $driver->scrape('https://example.com/article');

        $this->assertSame('jina', $driver->getName());
        $this->assertSame('Example Article', $content->getTitle());
        $this->assertStringContainsString('Body text.', $content->getText());
    }

    public function testRetriesOnTransientFailureThenSucceeds(): void
    {
        $attempts = 0;
        HttpFixtures::setPrefix('GET', 'https://r.jina.ai/', function () use (&$attempts) {
            $attempts++;
            if ($attempts < 2) {
                return new \WP_Error('http_request_failed', 'timeout');
            }

            return ['response' => ['code' => 200], 'body' => "Title: Retried\nMarkdown Content:\nok"];
        });

        $content = (new JinaAiDriver())->scrape('https://example.com/x');

        $this->assertSame('Retried', $content->getTitle());
        $this->assertSame(2, $attempts);
    }

    public function testThrowsAfterExhaustingRetries(): void
    {
        HttpFixtures::setPrefix('GET', 'https://r.jina.ai/', fn () => new \WP_Error('http_request_failed', 'down'));

        $this->expectException(ScraperException::class);
        (new JinaAiDriver())->scrape('https://example.com/x');
    }
}

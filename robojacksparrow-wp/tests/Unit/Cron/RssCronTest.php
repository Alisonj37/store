<?php

declare(strict_types=1);

namespace RoboJackSparrow\Tests\Unit\Cron;

use RoboJackSparrow\Core\Logger;
use RoboJackSparrow\Cron\Handlers\RssCron;
use RoboJackSparrow\Database\Repositories\ArticleRepository;
use RoboJackSparrow\Database\Repositories\SourceRepository;
use RoboJackSparrow\Queue\QueueManager;
use RoboJackSparrow\Scraper\Rss\RssParser;
use RoboJackSparrow\Tests\HttpFixtures;
use RoboJackSparrow\Tests\TestCase;

final class RssCronTest extends TestCase
{
    private function feedBody(): array
    {
        return [
            'response' => ['code' => 200],
            'body'     => '<?xml version="1.0" encoding="UTF-8"?>
<rss version="2.0"><channel><title>Test Feed</title>
<item><title>Item One</title><link>https://example.com/1</link><description>Desc one</description><pubDate>Mon, 01 Jan 2024 10:00:00 GMT</pubDate><guid>guid-1</guid></item>
<item><title>Item Two</title><link>https://example.com/2</link><description>Desc two</description><pubDate>Tue, 02 Jan 2024 10:00:00 GMT</pubDate><guid>guid-2</guid></item>
</channel></rss>',
        ];
    }

    public function testCollectCreatesArticlesAndEnqueuesScrapeJobsForNewItemsOnly(): void
    {
        $this->wpdb->sources[1] = [
            'id' => 1, 'source_name' => 'Tech Blog', 'source_url' => 'https://example.com/feed',
            'source_type' => 'rss', 'is_active' => 1, 'category_id' => 7,
            'scrape_frequency' => '4_hours', 'created_at' => '2026-01-01 00:00:00',
        ];
        // Item One was already imported by a previous run. Seeded at a high
        // id so it doesn't collide with FakeWpdb's own auto-increment
        // counter (which starts at 1, unaware of manually-seeded rows).
        $this->wpdb->articles[900] = ['id' => 900, 'source_url' => 'https://example.com/1', 'status' => 'published', 'created_at' => '2026-01-01 00:00:00'];

        HttpFixtures::set('GET', 'https://example.com/feed', $this->feedBody());

        $cron = new RssCron(new SourceRepository(), new RssParser(), new ArticleRepository(), new QueueManager(new Logger()), new Logger());
        $cron->collect();

        // Only Item Two (new) should have created an article + queue job.
        $this->assertCount(2, $this->wpdb->articles);
        $newArticle = $this->wpdb->articles[1];
        $this->assertSame('https://example.com/2', $newArticle['source_url']);
        $this->assertSame('Item Two', $newArticle['source_title']);
        $this->assertSame('rss', $newArticle['source_type']);
        $this->assertSame(7, $newArticle['category_id']);

        $this->assertCount(1, $this->wpdb->queueRows, 'exactly one scrape job for the one new item');
        $this->assertSame('scrape', reset($this->wpdb->queueRows)['job_type']);

        $this->assertNotEmpty($this->wpdb->sources[1]['last_scraped_at']);
    }

    public function testInactiveSourcesAreSkipped(): void
    {
        $this->wpdb->sources[1] = [
            'id' => 1, 'source_name' => 'Inactive', 'source_url' => 'https://example.com/feed',
            'source_type' => 'rss', 'is_active' => 0, 'category_id' => null,
            'scrape_frequency' => '4_hours', 'created_at' => '2026-01-01 00:00:00',
        ];

        $cron = new RssCron(new SourceRepository(), new RssParser(), new ArticleRepository(), new QueueManager(new Logger()), new Logger());
        $cron->collect();

        $this->assertSame([], $this->wpdb->articles);
        $this->assertSame(0, HttpFixtures::callCount('GET', 'https://example.com/feed'));
    }

    public function testNonRssSourcesAreSkipped(): void
    {
        $this->wpdb->sources[1] = [
            'id' => 1, 'source_name' => 'Direct scraper', 'source_url' => 'https://example.com/page',
            'source_type' => 'scraper', 'is_active' => 1, 'category_id' => null,
            'scrape_frequency' => '4_hours', 'created_at' => '2026-01-01 00:00:00',
        ];

        $cron = new RssCron(new SourceRepository(), new RssParser(), new ArticleRepository(), new QueueManager(new Logger()), new Logger());
        $cron->collect();

        $this->assertSame([], $this->wpdb->articles);
    }

    public function testOneFailingSourceDoesNotStopCollectionOfOthers(): void
    {
        $this->wpdb->sources = [
            1 => ['id' => 1, 'source_name' => 'Broken', 'source_url' => 'https://example.com/broken-feed', 'source_type' => 'rss', 'is_active' => 1, 'category_id' => null, 'scrape_frequency' => '4_hours', 'created_at' => 'x'],
            2 => ['id' => 2, 'source_name' => 'Good', 'source_url' => 'https://example.com/feed', 'source_type' => 'rss', 'is_active' => 1, 'category_id' => null, 'scrape_frequency' => '4_hours', 'created_at' => 'x'],
        ];
        // No fixture registered for the broken feed -> HttpFixtures returns a WP_Error.
        HttpFixtures::set('GET', 'https://example.com/feed', $this->feedBody());

        $cron = new RssCron(new SourceRepository(), new RssParser(), new ArticleRepository(), new QueueManager(new Logger()), new Logger());
        $cron->collect();

        $this->assertCount(2, $this->wpdb->articles, 'the good source must still be collected despite the broken one failing');
    }

    public function testRegisterHooksIntoRjsRssCollect(): void
    {
        $cron = new RssCron(new SourceRepository(), new RssParser(), new ArticleRepository(), new QueueManager(new Logger()), new Logger());
        $cron->register();

        $this->assertNotEmpty($GLOBALS['__rjs_hooks']['action']['rjs_rss_collect'] ?? []);
    }
}

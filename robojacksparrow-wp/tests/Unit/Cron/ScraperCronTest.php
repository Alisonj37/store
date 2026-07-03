<?php

declare(strict_types=1);

namespace RoboJackSparrow\Tests\Unit\Cron;

use RoboJackSparrow\Core\Encryption;
use RoboJackSparrow\Core\Logger;
use RoboJackSparrow\Cron\Handlers\ScraperCron;
use RoboJackSparrow\Database\Repositories\ArticleRepository;
use RoboJackSparrow\Database\Repositories\SettingRepository;
use RoboJackSparrow\Database\Repositories\SourceRepository;
use RoboJackSparrow\Queue\QueueManager;
use RoboJackSparrow\Scraper\Rss\RssParser;
use RoboJackSparrow\Scraper\SiteLinkDiscovery;
use RoboJackSparrow\Tests\HttpFixtures;
use RoboJackSparrow\Tests\TestCase;

final class ScraperCronTest extends TestCase
{
    private function makeCron(?SettingRepository $settings = null): ScraperCron
    {
        $logger = new Logger();

        return new ScraperCron(
            new SourceRepository(),
            new SiteLinkDiscovery(new RssParser(), $logger),
            new ArticleRepository(),
            new QueueManager($logger),
            $settings ?? new SettingRepository(new Encryption(), $logger),
            $logger
        );
    }

    private function listingHtml(): string
    {
        return '<html><body>'
            . '<a href="/2026/01/first-new-article-slug">First</a>'
            . '<a href="/2026/01/second-new-article-slug">Second</a>'
            . '<a href="/contact">Contact</a>'
            . '</body></html>';
    }

    public function testCollectsNewLinksAndEnqueuesWhenAutopilotIsEnabled(): void
    {
        $this->wpdb->sources[1] = [
            'id' => 1, 'source_name' => 'Blog X', 'source_url' => 'https://example.com/blog',
            'source_type' => 'scraper', 'is_active' => 1, 'category_id' => 3,
            'scrape_frequency' => '15_minutes', 'created_at' => '2026-01-01 00:00:00',
        ];

        HttpFixtures::set('GET', 'https://example.com/blog', ['response' => ['code' => 200], 'body' => $this->listingHtml()]);

        $settings = new SettingRepository(new Encryption(), new Logger());
        $settings->set('rjs_autopilot_enabled', '1');
        $settings->set('rjs_autopilot_publish_status', 'publish');

        $cron = $this->makeCron($settings);
        $cron->collect();

        $this->assertCount(2, $this->wpdb->articles);
        $first = $this->wpdb->articles[1];
        $this->assertSame('https://example.com/2026/01/first-new-article-slug', $first['source_url']);
        $this->assertSame('scraper', $first['source_type']);
        $this->assertSame(3, $first['category_id']);
        $this->assertSame('publish', $first['target_post_status']);

        $this->assertCount(2, $this->wpdb->queueRows);
        $this->assertNotEmpty($this->wpdb->sources[1]['last_scraped_at']);
    }

    public function testArticlesStayPendingWithoutEnqueueingWhenAutopilotIsDisabled(): void
    {
        $this->wpdb->sources[1] = [
            'id' => 1, 'source_name' => 'Blog X', 'source_url' => 'https://example.com/blog',
            'source_type' => 'scraper', 'is_active' => 1, 'category_id' => null,
            'scrape_frequency' => '15_minutes', 'created_at' => '2026-01-01 00:00:00',
        ];

        HttpFixtures::set('GET', 'https://example.com/blog', ['response' => ['code' => 200], 'body' => $this->listingHtml()]);

        $cron = $this->makeCron();
        $cron->collect();

        $this->assertCount(2, $this->wpdb->articles);
        $this->assertSame('pending', $this->wpdb->articles[1]['status']);
        $this->assertSame('draft', $this->wpdb->articles[1]['target_post_status']);
        $this->assertSame([], $this->wpdb->queueRows);
    }

    public function testDoesNotCreateDuplicateArticlesForAlreadySeenLinks(): void
    {
        $this->wpdb->sources[1] = [
            'id' => 1, 'source_name' => 'Blog X', 'source_url' => 'https://example.com/blog',
            'source_type' => 'scraper', 'is_active' => 1, 'category_id' => null,
            'scrape_frequency' => '15_minutes', 'created_at' => '2026-01-01 00:00:00',
        ];
        $this->wpdb->articles[900] = [
            'id' => 900, 'source_url' => 'https://example.com/2026/01/first-new-article-slug',
            'status' => 'published', 'created_at' => '2026-01-01 00:00:00',
        ];

        HttpFixtures::set('GET', 'https://example.com/blog', ['response' => ['code' => 200], 'body' => $this->listingHtml()]);

        $cron = $this->makeCron();
        $cron->collect();

        // Only the second (unseen) link creates a new article.
        $this->assertCount(2, $this->wpdb->articles);
        $newArticle = $this->wpdb->articles[1];
        $this->assertSame('https://example.com/2026/01/second-new-article-slug', $newArticle['source_url']);
    }

    public function testRssTypeSourcesAreSkipped(): void
    {
        $this->wpdb->sources[1] = [
            'id' => 1, 'source_name' => 'Feed X', 'source_url' => 'https://example.com/feed',
            'source_type' => 'rss', 'is_active' => 1, 'category_id' => null,
            'scrape_frequency' => '4_hours', 'created_at' => '2026-01-01 00:00:00',
        ];

        $cron = $this->makeCron();
        $cron->collect();

        $this->assertSame([], $this->wpdb->articles);
        $this->assertSame(0, HttpFixtures::callCount('GET', 'https://example.com/feed'));
    }

    public function testOneFailingSourceDoesNotStopCollectionOfOthers(): void
    {
        $this->wpdb->sources = [
            1 => ['id' => 1, 'source_name' => 'Broken', 'source_url' => 'https://example.com/broken', 'source_type' => 'scraper', 'is_active' => 1, 'category_id' => null, 'scrape_frequency' => '15_minutes', 'created_at' => 'x'],
            2 => ['id' => 2, 'source_name' => 'Good', 'source_url' => 'https://example.com/blog', 'source_type' => 'scraper', 'is_active' => 1, 'category_id' => null, 'scrape_frequency' => '15_minutes', 'created_at' => 'x'],
        ];
        HttpFixtures::set('GET', 'https://example.com/blog', ['response' => ['code' => 200], 'body' => $this->listingHtml()]);
        // No fixture for /broken -> HttpFixtures returns a WP_Error.

        $cron = $this->makeCron();
        $cron->collect();

        $this->assertCount(2, $this->wpdb->articles, 'the good source must still be collected despite the broken one failing');
    }
}

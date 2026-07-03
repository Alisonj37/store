<?php

declare(strict_types=1);

namespace RoboJackSparrow\Tests\Unit\Cron;

use RoboJackSparrow\Core\Encryption;
use RoboJackSparrow\Core\Logger;
use RoboJackSparrow\Cron\Handlers\RssCron;
use RoboJackSparrow\Database\Repositories\ArticleRepository;
use RoboJackSparrow\Database\Repositories\SettingRepository;
use RoboJackSparrow\Database\Repositories\SourceRepository;
use RoboJackSparrow\Queue\QueueManager;
use RoboJackSparrow\Scraper\Rss\RssParser;
use RoboJackSparrow\Tests\HttpFixtures;
use RoboJackSparrow\Tests\TestCase;

final class RssCronTest extends TestCase
{
    private function makeCron(?SettingRepository $settings = null): RssCron
    {
        $logger = new Logger();

        return new RssCron(
            new SourceRepository(),
            new RssParser(),
            new ArticleRepository(),
            new QueueManager($logger),
            $settings ?? new SettingRepository(new Encryption(), $logger),
            $logger
        );
    }

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

    public function testCollectCreatesArticlesAndEnqueuesScrapeJobsForNewItemsOnlyWhenAutopilotEnabled(): void
    {
        $this->wpdb->categories['Tecnologia'] = 7;
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

        $settings = new SettingRepository(new Encryption(), new Logger());
        $settings->set('rjs_autopilot_enabled', '1');
        $settings->set('rjs_autopilot_publish_status', 'publish');

        $cron = $this->makeCron($settings);
        $cron->collect();

        // Only Item Two (new) should have created an article + queue job.
        $this->assertCount(2, $this->wpdb->articles);
        $newArticle = $this->wpdb->articles[1];
        $this->assertSame('https://example.com/2', $newArticle['source_url']);
        $this->assertSame('Item Two', $newArticle['source_title']);
        $this->assertSame('rss', $newArticle['source_type']);
        $this->assertSame(7, $newArticle['category_id']);
        $this->assertSame('Tecnologia', $newArticle['category_name'], 'the category name must be resolved so handlePublish() actually applies it, not just the id');
        $this->assertSame('publish', $newArticle['target_post_status']);

        $this->assertCount(1, $this->wpdb->queueRows, 'exactly one scrape job for the one new item');
        $this->assertSame('scrape', reset($this->wpdb->queueRows)['job_type']);

        $this->assertNotEmpty($this->wpdb->sources[1]['last_scraped_at']);
    }

    public function testCollectCreatesArticlesWithoutEnqueueingWhenAutopilotIsDisabled(): void
    {
        $this->wpdb->sources[1] = [
            'id' => 1, 'source_name' => 'Tech Blog', 'source_url' => 'https://example.com/feed',
            'source_type' => 'rss', 'is_active' => 1, 'category_id' => 7,
            'scrape_frequency' => '4_hours', 'created_at' => '2026-01-01 00:00:00',
        ];

        HttpFixtures::set('GET', 'https://example.com/feed', $this->feedBody());

        // Autopilot is off by default - a fresh install must not start
        // auto-publishing before the admin has reviewed/configured it.
        $cron = $this->makeCron();
        $cron->collect();

        $this->assertCount(2, $this->wpdb->articles, 'articles are still created, just left pending');
        $newArticle = $this->wpdb->articles[1];
        $this->assertSame('pending', $newArticle['status']);
        $this->assertSame('draft', $newArticle['target_post_status']);

        $this->assertSame([], $this->wpdb->queueRows, 'no scrape job must be enqueued while autopilot is disabled');
    }

    public function testInactiveSourcesAreSkipped(): void
    {
        $this->wpdb->sources[1] = [
            'id' => 1, 'source_name' => 'Inactive', 'source_url' => 'https://example.com/feed',
            'source_type' => 'rss', 'is_active' => 0, 'category_id' => null,
            'scrape_frequency' => '4_hours', 'created_at' => '2026-01-01 00:00:00',
        ];

        $cron = $this->makeCron();
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

        $cron = $this->makeCron();
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

        $cron = $this->makeCron();
        $cron->collect();

        $this->assertCount(2, $this->wpdb->articles, 'the good source must still be collected despite the broken one failing');
    }

    public function testSourceNotYetDueForItsFrequencyIsSkipped(): void
    {
        $this->wpdb->sources[1] = [
            'id' => 1, 'source_name' => 'Tech Blog', 'source_url' => 'https://example.com/feed',
            'source_type' => 'rss', 'is_active' => 1, 'category_id' => null,
            'scrape_frequency' => '4_hours', 'last_scraped_at' => gmdate('Y-m-d H:i:s', time() - 60), // 1 minute ago
            'created_at' => '2026-01-01 00:00:00',
        ];

        HttpFixtures::set('GET', 'https://example.com/feed', $this->feedBody());

        $cron = $this->makeCron();
        $cron->collect();

        $this->assertSame([], $this->wpdb->articles, 'a 4-hour source scraped 1 minute ago is not due yet');
        $this->assertSame(0, HttpFixtures::callCount('GET', 'https://example.com/feed'));
    }

    public function testSourcePastItsFrequencyIntervalIsCollected(): void
    {
        $this->wpdb->sources[1] = [
            'id' => 1, 'source_name' => 'Tech Blog', 'source_url' => 'https://example.com/feed',
            'source_type' => 'rss', 'is_active' => 1, 'category_id' => null,
            'scrape_frequency' => '15_minutes', 'last_scraped_at' => gmdate('Y-m-d H:i:s', time() - 3600), // 1 hour ago
            'created_at' => '2026-01-01 00:00:00',
        ];

        HttpFixtures::set('GET', 'https://example.com/feed', $this->feedBody());

        $cron = $this->makeCron();
        $cron->collect();

        $this->assertCount(2, $this->wpdb->articles, 'a 15-minute source scraped an hour ago is due');
    }

    public function testRegisterHooksIntoRjsRssCollect(): void
    {
        $cron = $this->makeCron();
        $cron->register();

        $this->assertNotEmpty($GLOBALS['__rjs_hooks']['action']['rjs_rss_collect'] ?? []);
    }
}

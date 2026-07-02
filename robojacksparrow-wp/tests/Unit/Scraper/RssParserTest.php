<?php

declare(strict_types=1);

namespace RoboJackSparrow\Tests\Unit\Scraper;

use RoboJackSparrow\Scraper\Rss\RssParser;
use RoboJackSparrow\Tests\HttpFixtures;
use RoboJackSparrow\Tests\TestCase;

final class RssParserTest extends TestCase
{
    public function testParseFeedNormalizesItemsDatesCategoriesAndEnclosure(): void
    {
        HttpFixtures::set('GET', 'https://example.com/feed', [
            'response' => ['code' => 200],
            'body'     => '<?xml version="1.0" encoding="UTF-8"?>
<rss version="2.0"><channel><title>Test Feed</title>
<item><title>Item One</title><link>https://example.com/1</link><description>Desc one</description><pubDate>Mon, 01 Jan 2024 10:00:00 GMT</pubDate><guid>guid-1</guid><category>Tech</category><category>News</category></item>
<item><title>Item Two</title><link>https://example.com/2</link><description>Desc two</description><pubDate>Tue, 02 Jan 2024 10:00:00 GMT</pubDate><guid>guid-2</guid><enclosure url="https://example.com/2.jpg" type="image/jpeg" /></item>
</channel></rss>',
        ]);

        $items = (new RssParser())->parseFeed('https://example.com/feed');

        $this->assertCount(2, $items);
        $this->assertSame('Item One', $items[0]->getTitle());
        $this->assertSame(['Tech', 'News'], $items[0]->getCategories());
        $this->assertSame('2024-01-01', $items[0]->getPubDate()?->format('Y-m-d'));
        $this->assertSame([], $items[1]->getCategories());
        $this->assertSame('https://example.com/2.jpg', $items[1]->getEnclosureUrl());
    }

    public function testDiscoverFeedsFindsRssLinkTag(): void
    {
        $links = (new RssParser())->discoverFeeds(
            '<html><head><link rel="alternate" type="application/rss+xml" href="https://example.com/feed" /></head></html>'
        );

        $this->assertSame(['https://example.com/feed'], $links);
    }
}

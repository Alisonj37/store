<?php

declare(strict_types=1);

namespace RoboJackSparrow\Tests\Unit\Scraper;

use RoboJackSparrow\Core\Logger;
use RoboJackSparrow\Scraper\Rss\RssParser;
use RoboJackSparrow\Scraper\ScraperException;
use RoboJackSparrow\Scraper\SiteLinkDiscovery;
use RoboJackSparrow\Tests\HttpFixtures;
use RoboJackSparrow\Tests\TestCase;

final class SiteLinkDiscoveryTest extends TestCase
{
    public function testFollowsADeclaredFeedInsteadOfGuessingLinks(): void
    {
        $listingHtml = '<html><head>'
            . '<link rel="alternate" type="application/rss+xml" href="/feed">'
            . '</head><body><a href="/random-nav-link">Nav</a></body></html>';

        HttpFixtures::set('GET', 'https://example.com/blog', ['response' => ['code' => 200], 'body' => $listingHtml]);
        HttpFixtures::set('GET', 'https://example.com/feed', [
            'response' => ['code' => 200],
            'body'     => '<?xml version="1.0"?><rss version="2.0"><channel><title>Feed</title>'
                . '<item><title>Post One</title><link>https://example.com/2026/01/post-one-title</link></item>'
                . '</channel></rss>',
        ]);

        $links = (new SiteLinkDiscovery(new RssParser(), new Logger()))->discoverArticleLinks('https://example.com/blog');

        $this->assertSame(['https://example.com/2026/01/post-one-title'], $links);
    }

    public function testFallsBackToLinkHeuristicsWhenNoFeedIsDeclared(): void
    {
        $listingHtml = '<html><body>'
            . '<a href="/">Home</a>'
            . '<a href="/category/tech">Category</a>'
            . '<a href="/2026/01/a-great-new-article-slug">A great article</a>'
            . '<a href="https://other-domain.com/2026/01/off-site-article">Off-site</a>'
            . '<a href="/contact">Contact</a>'
            . '</body></html>';

        HttpFixtures::set('GET', 'https://example.com/blog', ['response' => ['code' => 200], 'body' => $listingHtml]);

        $links = (new SiteLinkDiscovery(new RssParser(), new Logger()))->discoverArticleLinks('https://example.com/blog');

        $this->assertSame(['https://example.com/2026/01/a-great-new-article-slug'], $links);
    }

    public function testThrowsOnNonSuccessHttpStatus(): void
    {
        HttpFixtures::set('GET', 'https://example.com/blog', ['response' => ['code' => 500], 'body' => '']);

        $this->expectException(ScraperException::class);
        (new SiteLinkDiscovery(new RssParser(), new Logger()))->discoverArticleLinks('https://example.com/blog');
    }

    public function testRespectsTheLimit(): void
    {
        $html = '<html><body>';
        for ($i = 1; $i <= 5; $i++) {
            $html .= "<a href=\"/2026/01/article-number-{$i}\">Article {$i}</a>";
        }
        $html .= '</body></html>';

        HttpFixtures::set('GET', 'https://example.com/blog', ['response' => ['code' => 200], 'body' => $html]);

        $links = (new SiteLinkDiscovery(new RssParser(), new Logger()))->discoverArticleLinks('https://example.com/blog', 2);

        $this->assertCount(2, $links);
    }
}

<?php

declare(strict_types=1);

namespace RoboJackSparrow\Scraper;

use RoboJackSparrow\Core\Logger;
use RoboJackSparrow\Scraper\Rss\RssParser;
use Throwable;

/**
 * Discovers "new content" links from a plain site URL (a homepage, a blog
 * listing, a category page) for autopilot sources that aren't an RSS feed.
 *
 * Two strategies, in order:
 *  1. If the page declares an RSS/Atom <link> in its <head>, follow that
 *     feed instead - far more reliable than guessing which <a> tags are
 *     articles.
 *  2. Otherwise, fall back to a best-effort heuristic over the page's own
 *     <a href> links (same-host, not an obvious nav/category/legal page,
 *     slug-like last path segment). This is NOT guaranteed to be 100%
 *     accurate on every site layout - it is a reasonable default, not a
 *     verified scraper for a specific site's markup.
 */
class SiteLinkDiscovery
{
    private const TIMEOUT = 30;

    /**
     * @var string[]
     */
    private const NON_ARTICLE_PATH_PATTERNS = [
        '#^/?$#',
        '#^/(category|categoria|tag|tags|author|autor|page|pagina|search|busca|cart|carrinho|checkout|account|conta|login|wp-admin|wp-login|feed|rss|about|sobre|contact|contato|privacy|privacidade|terms|termos)(/|$)#i',
    ];

    public function __construct(
        private RssParser $rssParser,
        private Logger $logger
    ) {
    }

    /**
     * @return string[] Absolute URLs of candidate new content, most-recent-first when known.
     */
    public function discoverArticleLinks(string $pageUrl, int $limit = 10): array
    {
        $response = wp_remote_get($pageUrl, ['timeout' => self::TIMEOUT]);

        if (is_wp_error($response)) {
            throw new ScraperException("Failed to fetch {$pageUrl}: " . $response->get_error_message());
        }

        $code = (int) wp_remote_retrieve_response_code($response);
        if ($code < 200 || $code >= 300) {
            throw new ScraperException("{$pageUrl} returned HTTP {$code}");
        }

        $html = (string) wp_remote_retrieve_body($response);
        if (trim($html) === '') {
            return [];
        }

        $viaFeed = $this->discoverViaDeclaredFeed($html, $pageUrl, $limit);
        if ($viaFeed !== null) {
            return $viaFeed;
        }

        return array_slice($this->extractCandidateLinks($html, $pageUrl), 0, $limit);
    }

    /**
     * @return ?string[] Null when no usable feed was found/followed, so the caller falls back to heuristics.
     */
    private function discoverViaDeclaredFeed(string $html, string $pageUrl, int $limit): ?array
    {
        $feeds = $this->rssParser->discoverFeeds($html);
        if ($feeds === []) {
            return null;
        }

        $feedUrl = $this->toAbsoluteUrl($feeds[0], $pageUrl) ?? $feeds[0];

        try {
            $items = $this->rssParser->parseFeed($feedUrl);
        } catch (Throwable $e) {
            $this->logger->warning('Declared feed discovery failed, falling back to link heuristics', [
                'url'   => $pageUrl,
                'feed'  => $feedUrl,
                'error' => $e->getMessage(),
            ]);

            return null;
        }

        $links = array_values(array_filter(array_map(static fn ($item) => trim($item->getLink()), $items)));

        return $links !== [] ? array_slice($links, 0, $limit) : null;
    }

    /**
     * @return string[]
     */
    private function extractCandidateLinks(string $html, string $baseUrl): array
    {
        $host = parse_url($baseUrl, PHP_URL_HOST);
        if ($host === null || $host === false) {
            return [];
        }

        $previous = libxml_use_internal_errors(true);
        $doc = new \DOMDocument();
        $doc->loadHTML('<?xml encoding="utf-8"?>' . $html);
        libxml_clear_errors();
        libxml_use_internal_errors($previous);

        $seen = [];
        $links = [];

        foreach ($doc->getElementsByTagName('a') as $anchor) {
            $href = trim($anchor->getAttribute('href'));
            if ($href === '' || str_starts_with($href, '#') || str_starts_with($href, 'javascript:') || str_starts_with($href, 'mailto:')) {
                continue;
            }

            $absolute = $this->toAbsoluteUrl($href, $baseUrl);
            if ($absolute === null || parse_url($absolute, PHP_URL_HOST) !== $host) {
                continue;
            }

            $absolute = (string) strtok($absolute, '#');
            if (isset($seen[$absolute])) {
                continue;
            }

            if (!$this->looksLikeArticlePath((string) parse_url($absolute, PHP_URL_PATH))) {
                continue;
            }

            $seen[$absolute] = true;
            $links[] = $absolute;
        }

        return $links;
    }

    private function looksLikeArticlePath(string $path): bool
    {
        foreach (self::NON_ARTICLE_PATH_PATTERNS as $pattern) {
            if (preg_match($pattern, $path) === 1) {
                return false;
            }
        }

        $segments = array_values(array_filter(explode('/', trim($path, '/')), static fn (string $s) => $s !== ''));
        if ($segments === []) {
            return false;
        }

        $last = (string) end($segments);

        return strlen($last) >= 8 && (str_contains($last, '-') || preg_match('/\d/', $last) === 1);
    }

    private function toAbsoluteUrl(string $href, string $baseUrl): ?string
    {
        if (preg_match('#^https?://#i', $href) === 1) {
            return $href;
        }

        $base = parse_url($baseUrl);
        if (!isset($base['scheme'], $base['host'])) {
            return null;
        }

        $origin = $base['scheme'] . '://' . $base['host'] . (isset($base['port']) ? ':' . $base['port'] : '');

        if (str_starts_with($href, '//')) {
            return $base['scheme'] . ':' . $href;
        }

        if (str_starts_with($href, '/')) {
            return $origin . $href;
        }

        $basePath = $base['path'] ?? '/';
        $baseDir = str_ends_with($basePath, '/') ? $basePath : dirname($basePath) . '/';

        return $origin . $baseDir . $href;
    }
}

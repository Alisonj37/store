<?php

declare(strict_types=1);

namespace RoboJackSparrow\Scraper\Rss;

use DateTimeImmutable;
use Exception;
use RoboJackSparrow\Scraper\Dto\RssItem;
use RoboJackSparrow\Scraper\ScraperException;
use SimpleXMLElement;

class RssParser
{
    private const TIMEOUT = 30;

    /**
     * Fetches and parses a feed, returning normalized items. Supports RSS
     * 2.0 (<channel><item>) and, best-effort, Atom (<feed><entry>) feeds.
     *
     * @return RssItem[]
     */
    public function parseFeed(string $feedUrl): array
    {
        $response = wp_remote_get($feedUrl, [
            'timeout' => self::TIMEOUT,
            'headers' => ['Accept' => 'application/rss+xml, application/xml, text/xml'],
        ]);

        if (is_wp_error($response)) {
            throw new ScraperException("Failed to fetch feed {$feedUrl}: " . $response->get_error_message());
        }

        $code = (int) wp_remote_retrieve_response_code($response);
        if ($code < 200 || $code >= 300) {
            throw new ScraperException("Feed {$feedUrl} returned HTTP {$code}");
        }

        $body = (string) wp_remote_retrieve_body($response);

        $previousSetting = libxml_use_internal_errors(true);
        $xml = simplexml_load_string($body);
        libxml_clear_errors();
        libxml_use_internal_errors($previousSetting);

        if ($xml === false) {
            throw new ScraperException("Invalid XML in feed: {$feedUrl}");
        }

        $items = isset($xml->channel->item) ? $xml->channel->item : ($xml->entry ?? null);
        if ($items === null) {
            return [];
        }

        $result = [];
        foreach ($items as $item) {
            $result[] = $this->mapItem($item);
        }

        return $result;
    }

    /**
     * Detects RSS/Atom feed <link> tags declared in an HTML page's <head>.
     *
     * @return string[]
     */
    public function discoverFeeds(string $html): array
    {
        preg_match_all(
            '/<link[^>]+type=["\'](?:application\/rss\+xml|application\/atom\+xml)["\'][^>]+href=["\']([^"\']+)["\']/i',
            $html,
            $matches
        );

        return $matches[1] ?? [];
    }

    private function mapItem(SimpleXMLElement $item): RssItem
    {
        return new RssItem(
            title: trim((string) $item->title),
            link: trim((string) ($item->link ?? '')),
            description: trim((string) ($item->description ?? $item->summary ?? '')),
            pubDate: $this->parseDate((string) ($item->pubDate ?? $item->published ?? $item->updated ?? '')),
            guid: trim((string) ($item->guid ?? $item->id ?? $item->link ?? '')),
            enclosureUrl: $this->extractEnclosure($item),
            categories: $this->extractCategories($item)
        );
    }

    private function extractEnclosure(SimpleXMLElement $item): ?string
    {
        if (isset($item->enclosure['url'])) {
            return (string) $item->enclosure['url'];
        }

        return null;
    }

    /**
     * @return string[]
     */
    private function extractCategories(SimpleXMLElement $item): array
    {
        $categories = [];

        foreach ($item->category as $category) {
            $value = trim((string) $category);
            if ($value !== '') {
                $categories[] = $value;
            }
        }

        return $categories;
    }

    private function parseDate(string $value): ?DateTimeImmutable
    {
        if (trim($value) === '') {
            return null;
        }

        try {
            return new DateTimeImmutable($value);
        } catch (Exception) {
            return null;
        }
    }
}

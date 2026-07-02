<?php

declare(strict_types=1);

namespace RoboJackSparrow\Scraper\Drivers;

use DateTimeImmutable;
use Exception;
use RoboJackSparrow\Scraper\Contracts\ScraperDriverInterface;
use RoboJackSparrow\Scraper\Dto\ScrapedContent;
use RoboJackSparrow\Scraper\ScraperException;

/**
 * Scrapes a URL via the Firecrawl API (https://api.firecrawl.dev/v1/scrape),
 * which returns a structured JSON payload including Markdown, HTML and
 * page metadata. Requires an API key.
 */
class FirecrawlDriver implements ScraperDriverInterface
{
    private const API_URL = 'https://api.firecrawl.dev/v1/scrape';
    private const TIMEOUT = 60;

    public function __construct(private string $apiKey)
    {
    }

    public function getName(): string
    {
        return 'firecrawl';
    }

    public function scrape(string $url): ScrapedContent
    {
        if ($this->apiKey === '') {
            throw new ScraperException('Firecrawl API key is not configured');
        }

        $response = wp_remote_post(self::API_URL, [
            'timeout' => self::TIMEOUT,
            'headers' => [
                'Authorization' => 'Bearer ' . $this->apiKey,
                'Content-Type'  => 'application/json',
            ],
            'body' => wp_json_encode([
                'url'             => $url,
                'formats'         => ['markdown', 'html'],
                'onlyMainContent' => true,
            ]),
        ]);

        if (is_wp_error($response)) {
            throw new ScraperException("Firecrawl request failed for {$url}: " . $response->get_error_message());
        }

        $code = (int) wp_remote_retrieve_response_code($response);
        $body = json_decode((string) wp_remote_retrieve_body($response), true);

        if ($code < 200 || $code >= 300) {
            $message = is_array($body) ? ($body['error'] ?? "HTTP {$code}") : "HTTP {$code}";
            throw new ScraperException("Firecrawl API error for {$url}: {$message}");
        }

        $data = $body['data'] ?? null;
        if (!is_array($data)) {
            throw new ScraperException("Firecrawl returned an unexpected payload for {$url}");
        }

        $metadata = is_array($data['metadata'] ?? null) ? $data['metadata'] : [];

        return new ScrapedContent(
            url: $url,
            title: (string) ($metadata['title'] ?? $url),
            text: (string) ($data['markdown'] ?? ''),
            html: isset($data['html']) ? (string) $data['html'] : null,
            author: isset($metadata['author']) ? (string) $metadata['author'] : null,
            publishedAt: $this->parseDate($metadata['publishedTime'] ?? null),
            images: $this->extractImages($metadata),
            metadata: $metadata,
            scraperUsed: $this->getName()
        );
    }

    /**
     * @return string[]
     */
    private function extractImages(array $metadata): array
    {
        $ogImage = $metadata['ogImage'] ?? null;

        if (is_string($ogImage) && $ogImage !== '') {
            return [$ogImage];
        }

        if (is_array($ogImage)) {
            return array_values(array_filter($ogImage, 'is_string'));
        }

        return [];
    }

    private function parseDate(mixed $value): ?DateTimeImmutable
    {
        if (!is_string($value) || $value === '') {
            return null;
        }

        try {
            return new DateTimeImmutable($value);
        } catch (Exception) {
            return null;
        }
    }
}

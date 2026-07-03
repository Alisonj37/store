<?php

declare(strict_types=1);

namespace RoboJackSparrow\Scraper\Drivers;

use RoboJackSparrow\Scraper\Contracts\ScraperDriverInterface;
use RoboJackSparrow\Scraper\Dto\ScrapedContent;
use RoboJackSparrow\Scraper\ScraperException;

/**
 * Scrapes a URL via the Jina.ai Reader API (https://r.jina.ai/{url}), which
 * returns the page already converted to clean Markdown. No API key is
 * required for low-volume use; an optional key raises the rate limit.
 */
class JinaAiDriver implements ScraperDriverInterface
{
    private const BASE_URL = 'https://r.jina.ai/';
    private const TIMEOUT = 30;
    private const MAX_RETRIES = 3;

    public function __construct(private ?string $apiKey = null)
    {
    }

    public function getName(): string
    {
        return 'jina';
    }

    public function scrape(string $url): ScrapedContent
    {
        $headers = ['Accept' => 'text/plain'];
        if ($this->apiKey !== null && $this->apiKey !== '') {
            $headers['Authorization'] = 'Bearer ' . $this->apiKey;
        }

        $lastError = 'unknown error';

        for ($attempt = 1; $attempt <= self::MAX_RETRIES; $attempt++) {
            $response = wp_remote_get(self::BASE_URL . $url, [
                'timeout' => self::TIMEOUT,
                'headers' => $headers,
            ]);

            if (is_wp_error($response)) {
                $lastError = $response->get_error_message();
                $this->wait($attempt);
                continue;
            }

            $code = (int) wp_remote_retrieve_response_code($response);
            if ($code < 200 || $code >= 300) {
                $lastError = "HTTP {$code}";
                $this->wait($attempt);
                continue;
            }

            $body = (string) wp_remote_retrieve_body($response);
            if (trim($body) === '') {
                $lastError = 'Empty response body';
                $this->wait($attempt);
                continue;
            }

            return $this->parseMarkdown($url, $body);
        }

        throw new ScraperException("Jina.ai scrape failed for {$url} after " . self::MAX_RETRIES . " attempts: {$lastError}");
    }

    private function wait(int $attempt): void
    {
        if ($attempt < self::MAX_RETRIES) {
            usleep(300000 * $attempt);
        }
    }

    /**
     * Jina.ai's Reader API prefixes the Markdown body with metadata lines,
     * e.g.:
     *   Title: Example Article
     *   URL Source: https://example.com/article
     *   Markdown Content:
     *   # Example Article
     *   ...
     */
    private function parseMarkdown(string $url, string $markdown): ScrapedContent
    {
        $title = $url;
        if (preg_match('/^Title:\s*(.+)$/mi', $markdown, $m)) {
            $title = trim($m[1]);
        }

        $text = $markdown;
        if (preg_match('/Markdown Content:\s*(.*)$/is', $markdown, $m)) {
            $text = trim($m[1]);
        }

        return new ScrapedContent(
            url: $url,
            title: $title,
            text: $text,
            html: null,
            author: null,
            publishedAt: null,
            images: [],
            metadata: ['raw_length' => strlen($markdown)],
            scraperUsed: $this->getName()
        );
    }
}

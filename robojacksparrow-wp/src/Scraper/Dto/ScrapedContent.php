<?php

declare(strict_types=1);

namespace RoboJackSparrow\Scraper\Dto;

use DateTimeImmutable;

final class ScrapedContent
{
    public function __construct(
        private string $url,
        private string $title,
        private string $text,
        private ?string $html = null,
        private ?string $author = null,
        private ?DateTimeImmutable $publishedAt = null,
        private array $images = [],
        private array $metadata = [],
        private string $scraperUsed = ''
    ) {
    }

    public function getUrl(): string
    {
        return $this->url;
    }

    public function getTitle(): string
    {
        return $this->title;
    }

    public function getText(): string
    {
        return $this->text;
    }

    public function getHtml(): ?string
    {
        return $this->html;
    }

    public function getAuthor(): ?string
    {
        return $this->author;
    }

    public function getPublishedAt(): ?DateTimeImmutable
    {
        return $this->publishedAt;
    }

    /**
     * @return string[]
     */
    public function getImages(): array
    {
        return $this->images;
    }

    public function getMetadata(): array
    {
        return $this->metadata;
    }

    public function getScraperUsed(): string
    {
        return $this->scraperUsed;
    }

    /**
     * Extracts the H1-H6 heading structure from the HTML version of the
     * content, when available. Used by the Content Engine (Fase 5) to seed
     * the outline generation prompt.
     *
     * @return array<int, array{level: int, text: string}>
     */
    public function extractOutline(): array
    {
        if ($this->html === null || trim($this->html) === '') {
            return [];
        }

        preg_match_all('/<h([1-6])[^>]*>(.*?)<\/h\1>/is', $this->html, $matches, PREG_SET_ORDER);

        $outline = [];
        foreach ($matches as $match) {
            $text = trim(strip_tags($match[2]));
            if ($text === '') {
                continue;
            }
            $outline[] = [
                'level' => (int) $match[1],
                'text'  => $text,
            ];
        }

        return $outline;
    }
}

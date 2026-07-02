<?php

declare(strict_types=1);

namespace RoboJackSparrow\Scraper\Dto;

use DateTimeImmutable;

final class RssItem
{
    public function __construct(
        private string $title,
        private string $link,
        private string $description,
        private ?DateTimeImmutable $pubDate,
        private string $guid,
        private ?string $enclosureUrl,
        private array $categories = []
    ) {
    }

    public function getTitle(): string
    {
        return $this->title;
    }

    public function getLink(): string
    {
        return $this->link;
    }

    public function getDescription(): string
    {
        return $this->description;
    }

    public function getPubDate(): ?DateTimeImmutable
    {
        return $this->pubDate;
    }

    public function getGuid(): string
    {
        return $this->guid;
    }

    public function getEnclosureUrl(): ?string
    {
        return $this->enclosureUrl;
    }

    /**
     * @return string[]
     */
    public function getCategories(): array
    {
        return $this->categories;
    }
}

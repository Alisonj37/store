<?php

declare(strict_types=1);

namespace RoboJackSparrow\Image\Dto;

final class ImageAttribution
{
    public function __construct(
        private ?string $authorName = null,
        private ?string $authorUrl = null,
        private ?string $sourceName = null,
        private ?string $sourceUrl = null
    ) {
    }

    public function getAuthorName(): ?string
    {
        return $this->authorName;
    }

    public function getAuthorUrl(): ?string
    {
        return $this->authorUrl;
    }

    public function getSourceName(): ?string
    {
        return $this->sourceName;
    }

    public function getSourceUrl(): ?string
    {
        return $this->sourceUrl;
    }

    /**
     * Human-readable credit line for providers that require visual
     * attribution (e.g. Unsplash), null when there is nothing to credit.
     */
    public function toCreditText(): ?string
    {
        if ($this->authorName === null && $this->sourceName === null) {
            return null;
        }

        if ($this->authorName !== null && $this->sourceName !== null) {
            return sprintf('Foto por %s / %s', $this->authorName, $this->sourceName);
        }

        return $this->authorName ?? $this->sourceName;
    }
}

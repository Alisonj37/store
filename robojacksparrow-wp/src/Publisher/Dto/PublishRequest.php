<?php

declare(strict_types=1);

namespace RoboJackSparrow\Publisher\Dto;

use DateTimeImmutable;
use RoboJackSparrow\Image\Dto\ImageResult;

final class PublishRequest
{
    /**
     * @param string[] $tags
     * @param string[] $focusKeywords
     */
    public function __construct(
        private string $title,
        private string $htmlContent,
        private string $seoTitle,
        private string $seoDescription,
        private array $schemaArticle = [],
        private array $schemaFaq = [],
        private array $focusKeywords = [],
        private array $tags = [],
        private ?string $categoryName = null,
        private ?ImageResult $featuredImage = null,
        private string $postType = 'post',
        private string $postStatus = 'draft',
        private ?DateTimeImmutable $scheduledAt = null
    ) {
    }

    public function getTitle(): string
    {
        return $this->title;
    }

    public function getHtmlContent(): string
    {
        return $this->htmlContent;
    }

    public function getSeoTitle(): string
    {
        return $this->seoTitle;
    }

    public function getSeoDescription(): string
    {
        return $this->seoDescription;
    }

    public function getSchemaArticle(): array
    {
        return $this->schemaArticle;
    }

    public function getSchemaFaq(): array
    {
        return $this->schemaFaq;
    }

    /**
     * @return string[]
     */
    public function getFocusKeywords(): array
    {
        return $this->focusKeywords;
    }

    /**
     * @return string[]
     */
    public function getTags(): array
    {
        return $this->tags;
    }

    public function getCategoryName(): ?string
    {
        return $this->categoryName;
    }

    public function getFeaturedImage(): ?ImageResult
    {
        return $this->featuredImage;
    }

    public function getPostType(): string
    {
        return $this->postType;
    }

    public function getPostStatus(): string
    {
        return $this->postStatus;
    }

    public function getScheduledAt(): ?DateTimeImmutable
    {
        return $this->scheduledAt;
    }
}

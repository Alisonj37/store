<?php

declare(strict_types=1);

namespace RoboJackSparrow\Content\Dto;

final class SeoData
{
    public function __construct(
        private string $title,
        private string $description,
        private array $articleSchema
    ) {
    }

    public function getTitle(): string
    {
        return $this->title;
    }

    public function getDescription(): string
    {
        return $this->description;
    }

    public function getArticleSchema(): array
    {
        return $this->articleSchema;
    }
}

<?php

declare(strict_types=1);

namespace RoboJackSparrow\Research\Dto;

final class ResearchSource
{
    public function __construct(
        private string $url,
        private string $title,
        private string $content,
        private ?float $score = null
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

    public function getContent(): string
    {
        return $this->content;
    }

    public function getScore(): ?float
    {
        return $this->score;
    }
}

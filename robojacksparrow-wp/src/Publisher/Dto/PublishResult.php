<?php

declare(strict_types=1);

namespace RoboJackSparrow\Publisher\Dto;

final class PublishResult
{
    public function __construct(
        private int $postId,
        private string $postUrl,
        private ?int $featuredImageId = null
    ) {
    }

    public function getPostId(): int
    {
        return $this->postId;
    }

    public function getPostUrl(): string
    {
        return $this->postUrl;
    }

    public function getFeaturedImageId(): ?int
    {
        return $this->featuredImageId;
    }
}

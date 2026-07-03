<?php

declare(strict_types=1);

namespace RoboJackSparrow\Image\Dto;

final class ImageRequest
{
    public function __construct(
        private string $prompt,
        private int $width = 1024,
        private int $height = 1024
    ) {
    }

    public function getPrompt(): string
    {
        return $this->prompt;
    }

    public function getWidth(): int
    {
        return $this->width;
    }

    public function getHeight(): int
    {
        return $this->height;
    }
}

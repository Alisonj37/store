<?php

declare(strict_types=1);

namespace RoboJackSparrow\Publisher\Dto;

use RoboJackSparrow\Image\Dto\ImageResult;

/**
 * A single in-body image ready to replace its placeholder token (see
 * ContentEngine::planBodyImages()/placeholderFor()) once uploaded to the
 * media library.
 */
final class BodyImage
{
    public function __construct(
        private string $token,
        private ImageResult $image,
        private string $altText
    ) {
    }

    public function getToken(): string
    {
        return $this->token;
    }

    public function getImage(): ImageResult
    {
        return $this->image;
    }

    public function getAltText(): string
    {
        return $this->altText;
    }
}

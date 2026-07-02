<?php

declare(strict_types=1);

namespace RoboJackSparrow\Image\Contracts;

use RoboJackSparrow\Image\Dto\ImageRequest;
use RoboJackSparrow\Image\Dto\ImageResult;
use RoboJackSparrow\Image\ImageException;

interface ImageProviderInterface
{
    public function getName(): string;

    /**
     * @throws ImageException When no image could be produced/downloaded.
     */
    public function generate(ImageRequest $request): ImageResult;
}

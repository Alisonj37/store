<?php

declare(strict_types=1);

namespace RoboJackSparrow\Image\Dto;

final class ImageResult
{
    public function __construct(
        private string $binaryData,
        private string $mimeType,
        private string $provider,
        private ImageAttribution $attribution,
        private ?string $sourceUrl = null
    ) {
    }

    public function getBinaryData(): string
    {
        return $this->binaryData;
    }

    public function getMimeType(): string
    {
        return $this->mimeType;
    }

    public function getProvider(): string
    {
        return $this->provider;
    }

    public function getAttribution(): ImageAttribution
    {
        return $this->attribution;
    }

    /**
     * Original remote URL the image was downloaded from, when applicable.
     */
    public function getSourceUrl(): ?string
    {
        return $this->sourceUrl;
    }

    /**
     * Returns a copy of this result with different binary data (e.g. after
     * WatermarkApplier has processed it), keeping provider/attribution intact.
     */
    public function withBinaryData(string $binaryData, ?string $mimeType = null): self
    {
        return new self($binaryData, $mimeType ?? $this->mimeType, $this->provider, $this->attribution, $this->sourceUrl);
    }
}

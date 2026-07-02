<?php

declare(strict_types=1);

namespace RoboJackSparrow\Image\Providers;

use RoboJackSparrow\Image\Dto\ImageAttribution;
use RoboJackSparrow\Image\Dto\ImageRequest;
use RoboJackSparrow\Image\Dto\ImageResult;
use RoboJackSparrow\Image\ImageException;

/**
 * Ultimo elo da cascata de imagens. Setting: rjs_pixabay_api_key
 */
class PixabayProvider extends AbstractImageProvider
{
    private const API_URL = 'https://pixabay.com/api/';

    public function __construct(private string $apiKey)
    {
    }

    public function getName(): string
    {
        return 'pixabay';
    }

    public function generate(ImageRequest $request): ImageResult
    {
        if (trim($this->apiKey) === '') {
            throw new ImageException('Pixabay API key is not configured');
        }

        $url = self::API_URL . '?' . http_build_query([
            'key'        => $this->apiKey,
            'q'          => $request->getPrompt(),
            'image_type' => 'photo',
            'per_page'   => 3, // Pixabay's minimum allowed value.
            'safesearch' => 'true',
        ]);

        $response = wp_remote_get($url, ['timeout' => static::TIMEOUT]);

        if (is_wp_error($response)) {
            throw new ImageException('Pixabay request failed: ' . $response->get_error_message());
        }

        $code = (int) wp_remote_retrieve_response_code($response);
        $body = json_decode((string) wp_remote_retrieve_body($response), true);

        if ($code < 200 || $code >= 300 || !is_array($body)) {
            throw new ImageException("Pixabay API error: HTTP {$code}");
        }

        $hit = $body['hits'][0] ?? null;
        if (!is_array($hit)) {
            throw new ImageException('Pixabay returned no results');
        }

        $imageUrl = (string) ($hit['largeImageURL'] ?? '');
        if ($imageUrl === '') {
            throw new ImageException('Pixabay hit has no usable image URL');
        }

        return new ImageResult(
            binaryData: $this->download($imageUrl),
            mimeType: $this->guessMimeType($imageUrl),
            provider: $this->getName(),
            attribution: new ImageAttribution(
                authorName: (string) ($hit['user'] ?? ''),
                sourceName: 'Pixabay',
                sourceUrl: (string) ($hit['pageURL'] ?? '')
            ),
            sourceUrl: $imageUrl
        );
    }
}

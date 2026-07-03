<?php

declare(strict_types=1);

namespace RoboJackSparrow\Image\Providers;

use RoboJackSparrow\Image\Dto\ImageAttribution;
use RoboJackSparrow\Image\Dto\ImageRequest;
use RoboJackSparrow\Image\Dto\ImageResult;
use RoboJackSparrow\Image\ImageException;

/**
 * Setting: rjs_pexels_api_key
 */
class PexelsProvider extends AbstractImageProvider
{
    private const API_URL = 'https://api.pexels.com/v1/search';

    public function __construct(private string $apiKey)
    {
    }

    public function getName(): string
    {
        return 'pexels';
    }

    public function generate(ImageRequest $request): ImageResult
    {
        if (trim($this->apiKey) === '') {
            throw new ImageException('Pexels API key is not configured');
        }

        $url = self::API_URL . '?' . http_build_query([
            'query'    => $request->getPrompt(),
            'per_page' => 1,
        ]);

        $response = wp_remote_get($url, [
            'timeout' => static::TIMEOUT,
            'headers' => ['Authorization' => $this->apiKey],
        ]);

        if (is_wp_error($response)) {
            throw new ImageException('Pexels request failed: ' . $response->get_error_message());
        }

        $code = (int) wp_remote_retrieve_response_code($response);
        $body = json_decode((string) wp_remote_retrieve_body($response), true);

        if ($code < 200 || $code >= 300 || !is_array($body)) {
            throw new ImageException("Pexels API error: HTTP {$code}");
        }

        $photo = $body['photos'][0] ?? null;
        if (!is_array($photo)) {
            throw new ImageException('Pexels returned no results');
        }

        $imageUrl = (string) ($photo['src']['large'] ?? '');
        if ($imageUrl === '') {
            throw new ImageException('Pexels photo has no usable image URL');
        }

        return new ImageResult(
            binaryData: $this->download($imageUrl),
            mimeType: $this->guessMimeType($imageUrl),
            provider: $this->getName(),
            attribution: new ImageAttribution(
                authorName: (string) ($photo['photographer'] ?? ''),
                authorUrl: (string) ($photo['photographer_url'] ?? ''),
                sourceName: 'Pexels',
                sourceUrl: (string) ($photo['url'] ?? '')
            ),
            sourceUrl: $imageUrl
        );
    }
}

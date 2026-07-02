<?php

declare(strict_types=1);

namespace RoboJackSparrow\Image\Providers;

use RoboJackSparrow\Image\Dto\ImageAttribution;
use RoboJackSparrow\Image\Dto\ImageRequest;
use RoboJackSparrow\Image\Dto\ImageResult;
use RoboJackSparrow\Image\ImageException;

/**
 * Fallback de banco de imagens (fotos reais, nao geradas por IA).
 * Setting: rjs_unsplash_api_key (Access Key)
 */
class UnsplashProvider extends AbstractImageProvider
{
    private const API_URL = 'https://api.unsplash.com/photos/random';

    public function __construct(private string $accessKey)
    {
    }

    public function getName(): string
    {
        return 'unsplash';
    }

    public function generate(ImageRequest $request): ImageResult
    {
        if (trim($this->accessKey) === '') {
            throw new ImageException('Unsplash access key is not configured');
        }

        $url = self::API_URL . '?' . http_build_query([
            'query'       => $request->getPrompt(),
            'orientation' => 'landscape',
        ]);

        $response = wp_remote_get($url, [
            'timeout' => static::TIMEOUT,
            'headers' => ['Authorization' => 'Client-ID ' . $this->accessKey],
        ]);

        if (is_wp_error($response)) {
            throw new ImageException('Unsplash request failed: ' . $response->get_error_message());
        }

        $code = (int) wp_remote_retrieve_response_code($response);
        $body = json_decode((string) wp_remote_retrieve_body($response), true);

        if ($code < 200 || $code >= 300 || !is_array($body)) {
            throw new ImageException("Unsplash API error: HTTP {$code}");
        }

        $imageUrl = (string) ($body['urls']['regular'] ?? '');
        if ($imageUrl === '') {
            throw new ImageException('Unsplash response has no image URL');
        }

        $this->pingDownloadLocation($body['links']['download_location'] ?? null);

        return new ImageResult(
            binaryData: $this->download($imageUrl),
            mimeType: $this->guessMimeType($imageUrl),
            provider: $this->getName(),
            attribution: new ImageAttribution(
                authorName: (string) ($body['user']['name'] ?? ''),
                authorUrl: (string) ($body['user']['links']['html'] ?? ''),
                sourceName: 'Unsplash',
                sourceUrl: (string) ($body['links']['html'] ?? '')
            ),
            sourceUrl: $imageUrl
        );
    }

    /**
     * Unsplash's API guidelines require triggering this endpoint whenever a
     * photo is used, in addition to visual attribution. Best-effort and
     * non-fatal: a failure here must not break the image cascade.
     */
    private function pingDownloadLocation(mixed $url): void
    {
        if (!is_string($url) || $url === '') {
            return;
        }

        wp_remote_get($url, [
            'timeout' => 10,
            'headers' => ['Authorization' => 'Client-ID ' . $this->accessKey],
        ]);
    }
}

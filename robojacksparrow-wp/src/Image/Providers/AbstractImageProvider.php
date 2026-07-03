<?php

declare(strict_types=1);

namespace RoboJackSparrow\Image\Providers;

use RoboJackSparrow\Image\Contracts\ImageProviderInterface;
use RoboJackSparrow\Image\ImageException;

/**
 * Shared helpers for image providers: downloading a remote image URL to
 * raw bytes (so WatermarkApplier can operate on it) and guessing its MIME
 * type from the extension.
 */
abstract class AbstractImageProvider implements ImageProviderInterface
{
    protected const TIMEOUT = 60;

    protected function download(string $url): string
    {
        $response = wp_remote_get($url, ['timeout' => static::TIMEOUT]);

        if (is_wp_error($response)) {
            throw new ImageException(ucfirst($this->getName()) . ' failed to download image: ' . $response->get_error_message());
        }

        $code = (int) wp_remote_retrieve_response_code($response);
        if ($code < 200 || $code >= 300) {
            throw new ImageException(ucfirst($this->getName()) . " failed to download image: HTTP {$code}");
        }

        $body = (string) wp_remote_retrieve_body($response);
        if ($body === '') {
            throw new ImageException(ucfirst($this->getName()) . ' downloaded an empty image body');
        }

        return $body;
    }

    protected function guessMimeType(string $url): string
    {
        $path = (string) parse_url($url, PHP_URL_PATH);
        $extension = strtolower(pathinfo($path, PATHINFO_EXTENSION));

        return match ($extension) {
            'jpg', 'jpeg' => 'image/jpeg',
            'webp'        => 'image/webp',
            'gif'         => 'image/gif',
            default       => 'image/png',
        };
    }
}

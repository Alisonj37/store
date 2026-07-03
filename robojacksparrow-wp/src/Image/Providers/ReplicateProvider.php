<?php

declare(strict_types=1);

namespace RoboJackSparrow\Image\Providers;

use RoboJackSparrow\Image\Dto\ImageAttribution;
use RoboJackSparrow\Image\Dto\ImageRequest;
use RoboJackSparrow\Image\Dto\ImageResult;
use RoboJackSparrow\Image\ImageException;

/**
 * Fallback de IA generativa (kei.ia -> Replicate). Usa o modelo oficial
 * Flux Schnell via o endpoint de "predictions" com o header
 * `Prefer: wait=25`, que faz o Replicate responder de forma sincrona
 * (sem precisar de polling) quando a geracao termina dentro da janela -
 * essencial em hospedagem compartilhada, sem processos em background.
 * Se a predicao ainda estiver rodando apos a espera, faz um polling curto
 * antes de desistir.
 *
 * Setting: rjs_replicate_api_key
 */
class ReplicateProvider extends AbstractImageProvider
{
    private const API_URL = 'https://api.replicate.com/v1/models/black-forest-labs/flux-schnell/predictions';
    private const POLL_INTERVAL_SECONDS = 1;
    private const MAX_POLL_ATTEMPTS = 15;

    public function __construct(private string $apiKey)
    {
    }

    public function getName(): string
    {
        return 'replicate';
    }

    public function generate(ImageRequest $request): ImageResult
    {
        if (trim($this->apiKey) === '') {
            throw new ImageException('Replicate API key is not configured');
        }

        $response = wp_remote_post(self::API_URL, [
            'timeout' => static::TIMEOUT,
            'headers' => [
                'Authorization' => 'Bearer ' . $this->apiKey,
                'Content-Type'  => 'application/json',
                'Prefer'        => 'wait=25',
            ],
            'body' => wp_json_encode([
                'input' => [
                    'prompt'         => $request->getPrompt(),
                    'aspect_ratio'   => $this->resolveAspectRatio($request),
                    'output_format'  => 'png',
                ],
            ]),
        ]);

        if (is_wp_error($response)) {
            throw new ImageException('Replicate request failed: ' . $response->get_error_message());
        }

        $code = (int) wp_remote_retrieve_response_code($response);
        $body = json_decode((string) wp_remote_retrieve_body($response), true);

        if ($code < 200 || $code >= 300 || !is_array($body)) {
            $message = is_array($body) ? (string) ($body['detail'] ?? "HTTP {$code}") : "HTTP {$code}";
            throw new ImageException("Replicate API error: {$message}");
        }

        $prediction = $this->awaitCompletion($body);
        $imageUrl = $this->extractImageUrl($prediction);

        return new ImageResult(
            binaryData: $this->download($imageUrl),
            mimeType: $this->guessMimeType($imageUrl),
            provider: $this->getName(),
            attribution: new ImageAttribution(sourceName: 'Replicate (Flux Schnell)'),
            sourceUrl: $imageUrl
        );
    }

    private function resolveAspectRatio(ImageRequest $request): string
    {
        $ratio = $request->getWidth() / max(1, $request->getHeight());

        if (abs($ratio - 1.0) < 0.05) {
            return '1:1';
        }

        return $ratio > 1 ? '16:9' : '9:16';
    }

    private function awaitCompletion(array $prediction): array
    {
        $status = (string) ($prediction['status'] ?? '');
        $attempts = 0;

        while (!in_array($status, ['succeeded', 'failed', 'canceled'], true) && $attempts < self::MAX_POLL_ATTEMPTS) {
            $pollUrl = $prediction['urls']['get'] ?? null;
            if (!is_string($pollUrl) || $pollUrl === '') {
                break;
            }

            sleep(self::POLL_INTERVAL_SECONDS);

            $pollResponse = wp_remote_get($pollUrl, [
                'timeout' => static::TIMEOUT,
                'headers' => ['Authorization' => 'Bearer ' . $this->apiKey],
            ]);

            if (is_wp_error($pollResponse)) {
                throw new ImageException('Replicate polling failed: ' . $pollResponse->get_error_message());
            }

            $decoded = json_decode((string) wp_remote_retrieve_body($pollResponse), true);
            if (is_array($decoded)) {
                $prediction = $decoded;
            }

            $status = (string) ($prediction['status'] ?? '');
            $attempts++;
        }

        if ($status !== 'succeeded') {
            throw new ImageException("Replicate prediction did not succeed (status: {$status})");
        }

        return $prediction;
    }

    private function extractImageUrl(array $prediction): string
    {
        $output = $prediction['output'] ?? null;

        if (is_string($output) && $output !== '') {
            return $output;
        }

        if (is_array($output) && isset($output[0]) && is_string($output[0]) && $output[0] !== '') {
            return $output[0];
        }

        throw new ImageException('Replicate prediction succeeded but returned no image URL');
    }
}

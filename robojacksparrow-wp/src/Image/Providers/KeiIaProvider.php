<?php

declare(strict_types=1);

namespace RoboJackSparrow\Image\Providers;

use RoboJackSparrow\Image\Dto\ImageAttribution;
use RoboJackSparrow\Image\Dto\ImageRequest;
use RoboJackSparrow\Image\Dto\ImageResult;
use RoboJackSparrow\Image\ImageException;

/**
 * Provider principal da cascata de imagens.
 *
 * IMPORTANTE - implementacao best-effort: nao foi possivel localizar
 * documentacao publica de um servico "kei.ia" no endpoint informado
 * (https://api.kei.ia/v1/images/generations). O endpoint e a setting
 * (rjs_kei_api_key) usados aqui sao exatamente os que foram especificados;
 * o formato de payload/resposta segue o padrao mais comum do mercado
 * (compativel com a API de imagens da OpenAI: POST {prompt, size, n} ->
 * {data: [{url}]} ou {data: [{b64_json}]}). Ajuste `buildPayload()` e
 * `resolveImageData()` quando a documentacao real do provedor estiver
 * disponivel. Como faz parte de uma cascata (Replicate -> Unsplash ->
 * Pexels -> Pixabay), uma falha aqui nao impede a geracao da imagem.
 *
 * Setting: rjs_kei_api_key
 */
class KeiIaProvider extends AbstractImageProvider
{
    private const API_URL = 'https://api.kei.ia/v1/images/generations';

    public function __construct(private string $apiKey)
    {
    }

    public function getName(): string
    {
        return 'kei_ia';
    }

    public function generate(ImageRequest $request): ImageResult
    {
        if (trim($this->apiKey) === '') {
            throw new ImageException('kei.ia API key is not configured');
        }

        $response = wp_remote_post(self::API_URL, [
            'timeout' => static::TIMEOUT,
            'headers' => [
                'Authorization' => 'Bearer ' . $this->apiKey,
                'Content-Type'  => 'application/json',
            ],
            'body' => wp_json_encode($this->buildPayload($request)),
        ]);

        if (is_wp_error($response)) {
            throw new ImageException('kei.ia request failed: ' . $response->get_error_message());
        }

        $code = (int) wp_remote_retrieve_response_code($response);
        $body = json_decode((string) wp_remote_retrieve_body($response), true);

        if ($code < 200 || $code >= 300) {
            $message = is_array($body) ? (string) ($body['error']['message'] ?? $body['error'] ?? "HTTP {$code}") : "HTTP {$code}";
            throw new ImageException("kei.ia API error: {$message}");
        }

        if (!is_array($body)) {
            throw new ImageException('kei.ia returned an unexpected payload');
        }

        $item = $body['data'][0] ?? null;
        if (!is_array($item)) {
            throw new ImageException('kei.ia response has no image data');
        }

        return $this->buildResult($item);
    }

    private function buildPayload(ImageRequest $request): array
    {
        return [
            'prompt' => $request->getPrompt(),
            'size'   => $request->getWidth() . 'x' . $request->getHeight(),
            'n'      => 1,
        ];
    }

    private function buildResult(array $item): ImageResult
    {
        if (isset($item['b64_json']) && is_string($item['b64_json']) && $item['b64_json'] !== '') {
            $decoded = base64_decode($item['b64_json'], true);
            if ($decoded === false) {
                throw new ImageException('kei.ia returned invalid base64 image data');
            }

            return new ImageResult(
                binaryData: $decoded,
                mimeType: 'image/png',
                provider: $this->getName(),
                attribution: new ImageAttribution(sourceName: 'kei.ia')
            );
        }

        $imageUrl = isset($item['url']) ? (string) $item['url'] : '';
        if ($imageUrl === '') {
            throw new ImageException('kei.ia response has neither url nor b64_json');
        }

        return new ImageResult(
            binaryData: $this->download($imageUrl),
            mimeType: $this->guessMimeType($imageUrl),
            provider: $this->getName(),
            attribution: new ImageAttribution(sourceName: 'kei.ia'),
            sourceUrl: $imageUrl
        );
    }
}

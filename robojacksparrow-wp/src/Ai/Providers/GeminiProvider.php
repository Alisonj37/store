<?php

declare(strict_types=1);

namespace RoboJackSparrow\Ai\Providers;

use RoboJackSparrow\Ai\Contracts\LLMProviderInterface;
use RoboJackSparrow\Ai\Dto\LLMRequest;
use RoboJackSparrow\Ai\Dto\LLMResponse;
use RoboJackSparrow\Ai\LLMException;

class GeminiProvider implements LLMProviderInterface
{
    private const API_BASE = 'https://generativelanguage.googleapis.com/v1beta';
    private const DEFAULT_MODEL = 'gemini-1.5-flash';
    private const TIMEOUT = 60;

    public function __construct(private string $apiKey, private ?string $model = null)
    {
    }

    public function getName(): string
    {
        return 'gemini';
    }

    private function resolveModel(): string
    {
        return $this->model !== null && trim($this->model) !== '' ? $this->model : self::DEFAULT_MODEL;
    }

    public function send(LLMRequest $request): LLMResponse
    {
        if (trim($this->apiKey) === '') {
            throw new LLMException('Gemini API key is not configured');
        }

        $model = $request->getModel() ?? $this->resolveModel();
        $url = self::API_BASE . "/models/{$model}:generateContent?key=" . rawurlencode($this->apiKey);

        $promptText = trim(($request->getSystemPrompt() ?? '') . "\n\n" . $request->getPrompt());

        $payload = [
            'contents' => [
                [
                    'role'  => 'user',
                    'parts' => [['text' => $promptText]],
                ],
            ],
            'generationConfig' => [
                'temperature'      => $request->getTemperature() ?? 0.7,
                'maxOutputTokens'  => $request->getMaxTokens() ?? 4096,
                'responseMimeType' => $request->isJsonMode() ? 'application/json' : 'text/plain',
            ],
        ];

        $response = wp_remote_post($url, [
            'timeout' => self::TIMEOUT,
            'headers' => ['Content-Type' => 'application/json'],
            'body'    => wp_json_encode($payload),
        ]);

        if (is_wp_error($response)) {
            throw new LLMException('Gemini request failed: ' . $response->get_error_message());
        }

        $body = json_decode((string) wp_remote_retrieve_body($response), true);

        if (!is_array($body)) {
            throw new LLMException('Gemini returned an unexpected payload');
        }

        if (isset($body['error'])) {
            $message = is_array($body['error']) ? (string) ($body['error']['message'] ?? 'unknown error') : (string) $body['error'];
            throw new LLMException('Gemini API error: ' . $message);
        }

        $usage = $body['usageMetadata'] ?? [];

        return new LLMResponse(
            content: (string) ($body['candidates'][0]['content']['parts'][0]['text'] ?? ''),
            tokensUsed: (int) ($usage['totalTokenCount'] ?? 0),
            promptTokens: (int) ($usage['promptTokenCount'] ?? 0),
            completionTokens: (int) ($usage['candidatesTokenCount'] ?? 0),
            model: $model,
            finishReason: (string) ($body['candidates'][0]['finishReason'] ?? ''),
            rawResponse: $body
        );
    }
}

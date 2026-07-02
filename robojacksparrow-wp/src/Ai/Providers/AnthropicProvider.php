<?php

declare(strict_types=1);

namespace RoboJackSparrow\Ai\Providers;

use RoboJackSparrow\Ai\Contracts\LLMProviderInterface;
use RoboJackSparrow\Ai\Dto\LLMRequest;
use RoboJackSparrow\Ai\Dto\LLMResponse;
use RoboJackSparrow\Ai\LLMException;

class AnthropicProvider implements LLMProviderInterface
{
    private const API_URL = 'https://api.anthropic.com/v1/messages';
    private const API_VERSION = '2023-06-01';
    private const DEFAULT_MODEL = 'claude-3-haiku-20240307';
    private const TIMEOUT = 60;

    public function __construct(private string $apiKey)
    {
    }

    public function getName(): string
    {
        return 'anthropic';
    }

    public function send(LLMRequest $request): LLMResponse
    {
        if (trim($this->apiKey) === '') {
            throw new LLMException('Anthropic API key is not configured');
        }

        $payload = [
            'model'       => $request->getModel() ?? self::DEFAULT_MODEL,
            'max_tokens'  => $request->getMaxTokens() ?? 4096,
            'temperature' => $request->getTemperature() ?? 0.7,
            'messages'    => $this->formatMessages($request),
        ];

        $systemPrompt = $request->getSystemPrompt() ?? '';
        if ($request->isJsonMode()) {
            // Claude has no native json_mode; enforce it via the system prompt.
            $payload['system'] = trim($systemPrompt . "\n\nYou must respond with valid JSON only. No other text.");
        } elseif ($systemPrompt !== '') {
            $payload['system'] = $systemPrompt;
        }

        $response = wp_remote_post(self::API_URL, [
            'timeout' => self::TIMEOUT,
            'headers' => [
                'x-api-key'         => $this->apiKey,
                'anthropic-version' => self::API_VERSION,
                'Content-Type'      => 'application/json',
            ],
            'body' => wp_json_encode($payload),
        ]);

        if (is_wp_error($response)) {
            throw new LLMException('Anthropic request failed: ' . $response->get_error_message());
        }

        $body = json_decode((string) wp_remote_retrieve_body($response), true);

        if (!is_array($body)) {
            throw new LLMException('Anthropic returned an unexpected payload');
        }

        if (isset($body['error'])) {
            $message = is_array($body['error']) ? (string) ($body['error']['message'] ?? 'unknown error') : (string) $body['error'];
            throw new LLMException('Anthropic API error: ' . $message);
        }

        $usage = $body['usage'] ?? [];
        $inputTokens = (int) ($usage['input_tokens'] ?? 0);
        $outputTokens = (int) ($usage['output_tokens'] ?? 0);

        return new LLMResponse(
            content: (string) ($body['content'][0]['text'] ?? ''),
            tokensUsed: $inputTokens + $outputTokens,
            promptTokens: $inputTokens,
            completionTokens: $outputTokens,
            model: (string) ($body['model'] ?? self::DEFAULT_MODEL),
            finishReason: (string) ($body['stop_reason'] ?? ''),
            rawResponse: $body
        );
    }

    /**
     * @return array<int, array{role: string, content: string}>
     */
    private function formatMessages(LLMRequest $request): array
    {
        $messages = [];

        foreach ($request->getContextMemory() as $entry) {
            $messages[] = [
                'role'    => (string) ($entry['role'] ?? 'user'),
                'content' => (string) ($entry['content'] ?? ''),
            ];
        }

        $messages[] = ['role' => 'user', 'content' => $request->getPrompt()];

        return $messages;
    }
}

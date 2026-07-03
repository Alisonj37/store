<?php

declare(strict_types=1);

namespace RoboJackSparrow\Ai\Providers;

use RoboJackSparrow\Ai\Contracts\LLMProviderInterface;
use RoboJackSparrow\Ai\Dto\LLMRequest;
use RoboJackSparrow\Ai\Dto\LLMResponse;
use RoboJackSparrow\Ai\LLMException;

/**
 * Shared implementation for providers exposing an OpenAI-compatible
 * `chat/completions` endpoint (OpenAI itself, Groq, DeepSeek).
 */
abstract class AbstractOpenAiCompatibleProvider implements LLMProviderInterface
{
    /**
     * The single-call article generation can legitimately ask a model to
     * produce several thousand completion tokens (full article body + FAQs)
     * in one non-streamed response; 60s was tuned for the old, much smaller
     * per-section calls and could cut off a slower model mid-generation,
     * surfacing as a generic connection-timeout failure with no clear cause.
     */
    protected const TIMEOUT = 120;

    /**
     * @param ?string $model Admin-configured default model for this
     *     provider (e.g. rjs_openai_model). Falls back to getDefaultModel()
     *     when null/empty. A model set explicitly on the LLMRequest itself
     *     always wins over both.
     */
    public function __construct(protected string $apiKey, protected ?string $model = null)
    {
    }

    abstract protected function getApiUrl(): string;

    abstract protected function getDefaultModel(): string;

    protected function resolveModel(): string
    {
        return $this->model !== null && trim($this->model) !== '' ? $this->model : $this->getDefaultModel();
    }

    /**
     * OpenAI's reasoning-class models (o1/o3/o4... and the gpt-5 family)
     * use a different request shape than classic chat-completions models:
     * they reject a custom `temperature` (only the default is accepted) and
     * require `max_completion_tokens` instead of `max_tokens` - sending the
     * old shape gets an outright 400 error, which would otherwise look
     * exactly like any other "model not implemented well" failure. Groq and
     * DeepSeek (the other two providers built on this shared class) don't
     * have this model family, so the override lives in OpenAIProvider, not
     * here; this base implementation stays the classic shape by default.
     */
    protected function isReasoningModel(string $model): bool
    {
        return false;
    }

    public function send(LLMRequest $request): LLMResponse
    {
        if (trim($this->apiKey) === '') {
            throw new LLMException(ucfirst($this->getName()) . ' API key is not configured');
        }

        $model = $request->getModel() ?? $this->resolveModel();
        $isReasoningModel = $this->isReasoningModel($model);
        $maxTokensKey = $isReasoningModel ? 'max_completion_tokens' : 'max_tokens';

        $payload = array_filter(
            [
                'model'           => $model,
                'messages'        => $this->formatMessages($request),
                'temperature'     => $isReasoningModel ? null : ($request->getTemperature() ?? 0.7),
                $maxTokensKey     => $request->getMaxTokens() ?? 4096,
                'response_format' => $request->isJsonMode() ? ['type' => 'json_object'] : null,
            ],
            static fn ($value) => $value !== null
        );

        $response = wp_remote_post($this->getApiUrl(), [
            'timeout' => static::TIMEOUT,
            'headers' => [
                'Authorization' => 'Bearer ' . $this->apiKey,
                'Content-Type'  => 'application/json',
            ],
            'body' => wp_json_encode($payload),
        ]);

        if (is_wp_error($response)) {
            throw new LLMException(ucfirst($this->getName()) . ' request failed: ' . $response->get_error_message());
        }

        $body = json_decode((string) wp_remote_retrieve_body($response), true);

        if (!is_array($body)) {
            throw new LLMException(ucfirst($this->getName()) . ' returned an unexpected payload');
        }

        if (isset($body['error'])) {
            $message = is_array($body['error']) ? (string) ($body['error']['message'] ?? 'unknown error') : (string) $body['error'];
            throw new LLMException(ucfirst($this->getName()) . ' API error: ' . $message);
        }

        $usage = $body['usage'] ?? [];

        return new LLMResponse(
            content: (string) ($body['choices'][0]['message']['content'] ?? ''),
            tokensUsed: (int) ($usage['total_tokens'] ?? 0),
            promptTokens: (int) ($usage['prompt_tokens'] ?? 0),
            completionTokens: (int) ($usage['completion_tokens'] ?? 0),
            model: (string) ($body['model'] ?? $this->resolveModel()),
            finishReason: (string) ($body['choices'][0]['finish_reason'] ?? ''),
            rawResponse: $body
        );
    }

    /**
     * @return array<int, array{role: string, content: string}>
     */
    private function formatMessages(LLMRequest $request): array
    {
        $messages = [];

        if ($request->getSystemPrompt() !== null && $request->getSystemPrompt() !== '') {
            $messages[] = ['role' => 'system', 'content' => $request->getSystemPrompt()];
        }

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

<?php

declare(strict_types=1);

namespace RoboJackSparrow\Ai\Dto;

final class LLMResponse
{
    private string $provider = '';
    private int $latencyMs = 0;

    public function __construct(
        private string $content,
        private int $tokensUsed,
        private int $promptTokens,
        private int $completionTokens,
        private string $model,
        private string $finishReason,
        private array $rawResponse = []
    ) {
    }

    public function getContent(): string
    {
        return $this->content;
    }

    public function getTokensUsed(): int
    {
        return $this->tokensUsed;
    }

    public function getPromptTokens(): int
    {
        return $this->promptTokens;
    }

    public function getCompletionTokens(): int
    {
        return $this->completionTokens;
    }

    public function getModel(): string
    {
        return $this->model;
    }

    public function getFinishReason(): string
    {
        return $this->finishReason;
    }

    public function getRawResponse(): array
    {
        return $this->rawResponse;
    }

    /**
     * Set by LLMRouter after a successful call, so callers know which
     * provider actually served the request (routing is dynamic).
     */
    public function setProvider(string $provider): void
    {
        $this->provider = $provider;
    }

    public function getProvider(): string
    {
        return $this->provider;
    }

    public function setLatencyMs(int $latencyMs): void
    {
        $this->latencyMs = $latencyMs;
    }

    public function getLatencyMs(): int
    {
        return $this->latencyMs;
    }
}

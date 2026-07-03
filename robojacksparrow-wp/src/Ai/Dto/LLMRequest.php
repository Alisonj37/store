<?php

declare(strict_types=1);

namespace RoboJackSparrow\Ai\Dto;

final class LLMRequest
{
    /**
     * @param array<int, array{role: string, content: string}> $contextMemory
     */
    public function __construct(
        private string $prompt,
        private ?string $systemPrompt = null,
        private ?string $model = null,
        private ?float $temperature = null,
        private ?int $maxTokens = null,
        private bool $isJsonMode = false,
        private array $contextMemory = [],
        private ?string $preferredProvider = null
    ) {
    }

    public function getPrompt(): string
    {
        return $this->prompt;
    }

    public function getSystemPrompt(): ?string
    {
        return $this->systemPrompt;
    }

    public function getModel(): ?string
    {
        return $this->model;
    }

    public function getTemperature(): ?float
    {
        return $this->temperature;
    }

    public function getMaxTokens(): ?int
    {
        return $this->maxTokens;
    }

    public function isJsonMode(): bool
    {
        return $this->isJsonMode;
    }

    /**
     * @return array<int, array{role: string, content: string}>
     */
    public function getContextMemory(): array
    {
        return $this->contextMemory;
    }

    public function getPreferredProvider(): ?string
    {
        return $this->preferredProvider;
    }
}

<?php

declare(strict_types=1);

namespace RoboJackSparrow\Ai\Providers;

class OpenAIProvider extends AbstractOpenAiCompatibleProvider
{
    public function getName(): string
    {
        return 'openai';
    }

    protected function getApiUrl(): string
    {
        return 'https://api.openai.com/v1/chat/completions';
    }

    protected function getDefaultModel(): string
    {
        return 'gpt-4o-mini';
    }

    /**
     * o1/o3/o4-... and the gpt-5 family (e.g. the gpt-5.4-mini identifier
     * requested for this plugin) are OpenAI's reasoning-class models: as of
     * this plugin's last knowledge of the API, they require
     * max_completion_tokens instead of max_tokens and reject a custom
     * temperature. This is a pattern-based heuristic, not a hardcoded list -
     * new model names released after that cutoff are matched by the same
     * naming convention, but if OpenAI ever changes the convention itself,
     * this will need updating (a 400 error mentioning "max_tokens" or
     * "temperature" in the Logs page's Detalhes column is the tell).
     */
    protected function isReasoningModel(string $model): bool
    {
        return (bool) preg_match('/^(o[0-9]|gpt-5)/i', trim($model));
    }
}

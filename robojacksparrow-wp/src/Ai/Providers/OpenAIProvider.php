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
}

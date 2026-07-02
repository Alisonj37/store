<?php

declare(strict_types=1);

namespace RoboJackSparrow\Ai\Providers;

class GroqProvider extends AbstractOpenAiCompatibleProvider
{
    public function getName(): string
    {
        return 'groq';
    }

    protected function getApiUrl(): string
    {
        return 'https://api.groq.com/openai/v1/chat/completions';
    }

    protected function getDefaultModel(): string
    {
        return 'llama-3.1-70b-versatile';
    }
}

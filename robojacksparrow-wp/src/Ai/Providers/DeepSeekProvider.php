<?php

declare(strict_types=1);

namespace RoboJackSparrow\Ai\Providers;

class DeepSeekProvider extends AbstractOpenAiCompatibleProvider
{
    public function getName(): string
    {
        return 'deepseek';
    }

    protected function getApiUrl(): string
    {
        return 'https://api.deepseek.com/v1/chat/completions';
    }

    protected function getDefaultModel(): string
    {
        return 'deepseek-chat';
    }
}

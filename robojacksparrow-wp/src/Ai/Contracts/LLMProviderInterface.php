<?php

declare(strict_types=1);

namespace RoboJackSparrow\Ai\Contracts;

use RoboJackSparrow\Ai\Dto\LLMRequest;
use RoboJackSparrow\Ai\Dto\LLMResponse;
use RoboJackSparrow\Ai\LLMException;

interface LLMProviderInterface
{
    public function getName(): string;

    /**
     * @throws LLMException When the provider cannot fulfill the request.
     */
    public function send(LLMRequest $request): LLMResponse;
}

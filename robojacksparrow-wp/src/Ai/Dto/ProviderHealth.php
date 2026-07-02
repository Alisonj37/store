<?php

declare(strict_types=1);

namespace RoboJackSparrow\Ai\Dto;

final class ProviderHealth
{
    public function __construct(
        private string $provider,
        private string $status,
        private int $avgLatencyMs,
        private float $successRate24h,
        private int $consecutiveFailures
    ) {
    }

    public function getProvider(): string
    {
        return $this->provider;
    }

    /** healthy|degraded|down|rate_limited */
    public function getStatus(): string
    {
        return $this->status;
    }

    public function getAvgLatencyMs(): int
    {
        return $this->avgLatencyMs;
    }

    public function getSuccessRate24h(): float
    {
        return $this->successRate24h;
    }

    public function getConsecutiveFailures(): int
    {
        return $this->consecutiveFailures;
    }
}

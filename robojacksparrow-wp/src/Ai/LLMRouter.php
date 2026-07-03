<?php

declare(strict_types=1);

namespace RoboJackSparrow\Ai;

use RoboJackSparrow\Ai\Contracts\LLMProviderInterface;
use RoboJackSparrow\Ai\Dto\LLMRequest;
use RoboJackSparrow\Ai\Dto\LLMResponse;
use RoboJackSparrow\Ai\Dto\ProviderHealth;
use RoboJackSparrow\Core\Logger;
use Throwable;

/**
 * Roteia uma requisicao para o melhor provedor de LLM disponivel, com
 * failover automatico e score composto (disponibilidade + latencia +
 * taxa de sucesso 24h + falhas consecutivas), alimentado pelo HealthMonitor.
 */
class LLMRouter
{
    /**
     * A provider marked 'down' after 5 consecutive failures (see
     * HealthMonitor) had no way back in before this: rankProviders() simply
     * skipped it forever, since recordSuccess() - the only thing that ever
     * clears 'down' - can't run for a provider that never gets tried again.
     * Whatever originally caused the failures (an invalid key at setup
     * time, a transient outage, a bad model id since corrected) it would
     * stay locked out permanently, and if every configured provider ends up
     * in that state, EVERY single generation fails immediately with "No LLM
     * providers available" - indistinguishable, from the Logs page, from
     * every other failure reason. After this cooldown elapses since the
     * last check, a 'down' provider gets one more probe attempt instead of
     * being excluded outright.
     */
    private const DOWN_COOLDOWN_SECONDS = 600;

    /**
     * @param array<string, LLMProviderInterface> $providers Keyed by provider name (openai, anthropic, groq, gemini, deepseek).
     */
    public function __construct(
        private array $providers,
        private HealthMonitor $health,
        private Logger $logger
    ) {
    }

    public function route(LLMRequest $request): LLMResponse
    {
        $candidates = $this->rankProviders($request->getPreferredProvider());

        if ($candidates === []) {
            throw new LLMException('No LLM providers available (all are down or none configured)');
        }

        $lastError = null;

        foreach ($candidates as $provider) {
            try {
                $startTime = microtime(true);
                $response = $provider->send($request);
                $latency = (int) round((microtime(true) - $startTime) * 1000);

                $this->health->recordSuccess($provider->getName(), $latency);
                $response->setProvider($provider->getName());
                $response->setLatencyMs($latency);

                $this->logger->info('LLM call successful', [
                    'provider'   => $provider->getName(),
                    'model'      => $response->getModel(),
                    'latency_ms' => $latency,
                    'tokens'     => $response->getTokensUsed(),
                ]);

                return $response;
            } catch (Throwable $e) {
                $lastError = $e;
                $this->health->recordFailure($provider->getName(), $e->getMessage());
                $this->logger->warning('LLM provider failed, trying next', [
                    'provider' => $provider->getName(),
                    'error'    => $e->getMessage(),
                ]);
            }
        }

        throw new LLMException('All LLM providers failed. Last error: ' . ($lastError?->getMessage() ?? 'unknown'));
    }

    /**
     * Ranqueia os providers configurados por score composto (0-100+),
     * pulando os que estao 'down'. O provider preferido, se informado e
     * disponivel, recebe um bonus para ser tentado primeiro.
     *
     * @return LLMProviderInterface[]
     */
    private function rankProviders(?string $preferred): array
    {
        $scored = [];

        foreach ($this->providers as $name => $provider) {
            $health = $this->health->getStatus($name);

            if ($health->getStatus() === 'down' && !$this->downCooldownElapsed($health)) {
                continue;
            }

            $availabilityScore = $health->getStatus() === 'healthy' ? 40 : 20;
            $latencyScore = max(0.0, 30 - ($health->getAvgLatencyMs() / 100));
            $successScore = ($health->getSuccessRate24h() / 100) * 20;
            $healthScore = max(0, 10 - $health->getConsecutiveFailures() * 2);

            $totalScore = $availabilityScore + $latencyScore + $successScore + $healthScore;

            if ($preferred !== null && $preferred === $name) {
                $totalScore += 100;
            }

            $scored[$name] = ['provider' => $provider, 'score' => $totalScore];
        }

        uasort($scored, static fn (array $a, array $b) => $b['score'] <=> $a['score']);

        return array_column($scored, 'provider');
    }

    /**
     * True once DOWN_COOLDOWN_SECONDS have passed since a 'down' provider's
     * last check (or immediately, if it was somehow marked down without a
     * timestamp at all - never let a missing timestamp mean "never retry").
     */
    private function downCooldownElapsed(ProviderHealth $health): bool
    {
        $lastCheck = $health->getLastCheck();

        if ($lastCheck === null || $lastCheck === '') {
            return true;
        }

        $lastCheckTimestamp = strtotime($lastCheck . ' UTC');

        if ($lastCheckTimestamp === false) {
            return true;
        }

        return (time() - $lastCheckTimestamp) >= self::DOWN_COOLDOWN_SECONDS;
    }
}

<?php

declare(strict_types=1);

namespace RoboJackSparrow\Tests\Unit\Ai;

use RoboJackSparrow\Ai\Contracts\LLMProviderInterface;
use RoboJackSparrow\Ai\Dto\LLMRequest;
use RoboJackSparrow\Ai\Dto\LLMResponse;
use RoboJackSparrow\Ai\HealthMonitor;
use RoboJackSparrow\Ai\LLMException;
use RoboJackSparrow\Ai\LLMRouter;
use RoboJackSparrow\Core\Logger;
use RoboJackSparrow\Tests\TestCase;

final class LLMRouterTest extends TestCase
{
    public function testFailsOverFromAFailingProviderToAWorkingOne(): void
    {
        $health = new HealthMonitor();
        $bad = new FakeLLMProvider('bad', shouldFail: true);
        $good = new FakeLLMProvider('good', shouldFail: false);

        $router = new LLMRouter(['bad' => $bad, 'good' => $good], $health, new Logger());
        $response = $router->route(new LLMRequest('hi'));

        $this->assertSame('good', $response->getProvider());
        $this->assertSame(1, $bad->calls);
        $this->assertSame(1, $good->calls);
    }

    public function testSkipsProvidersMarkedDownWithoutInvokingThem(): void
    {
        $health = new HealthMonitor();
        $bad = new FakeLLMProvider('bad', shouldFail: true);

        // Drive HealthMonitor directly to the 'down' threshold (5 consecutive failures).
        for ($i = 0; $i < 5; $i++) {
            $health->recordFailure('bad', 'boom');
        }
        $this->assertSame('down', $health->getStatus('bad')->getStatus());

        $router = new LLMRouter(['bad' => $bad], $health, new Logger());

        $this->expectException(LLMException::class);
        $this->expectExceptionMessageMatches('/No LLM providers available/');

        try {
            $router->route(new LLMRequest('hi'));
        } finally {
            $this->assertSame(0, $bad->calls, 'a down provider must never be invoked');
        }
    }

    public function testPreferredProviderBonusWinsOverArrayOrder(): void
    {
        $p1 = new FakeLLMProvider('p1', shouldFail: false);
        $p2 = new FakeLLMProvider('p2', shouldFail: false);

        $router = new LLMRouter(['p1' => $p1, 'p2' => $p2], new HealthMonitor(), new Logger());
        $response = $router->route(new LLMRequest('hi', preferredProvider: 'p2'));

        $this->assertSame('p2', $response->getProvider());
        $this->assertSame(0, $p1->calls);
        $this->assertSame(1, $p2->calls);
    }

    public function testThrowsAggregateExceptionWhenEveryProviderFails(): void
    {
        $router = new LLMRouter(
            ['f1' => new FakeLLMProvider('f1', shouldFail: true), 'f2' => new FakeLLMProvider('f2', shouldFail: true)],
            new HealthMonitor(),
            new Logger()
        );

        $this->expectException(LLMException::class);
        $this->expectExceptionMessageMatches('/All LLM providers failed/');
        $router->route(new LLMRequest('hi'));
    }
}

final class FakeLLMProvider implements LLMProviderInterface
{
    public int $calls = 0;

    public function __construct(private string $name, private bool $shouldFail)
    {
    }

    public function getName(): string
    {
        return $this->name;
    }

    public function send(LLMRequest $request): LLMResponse
    {
        $this->calls++;

        if ($this->shouldFail) {
            throw new LLMException("simulated failure from {$this->name}");
        }

        return new LLMResponse('ok from ' . $this->name, 10, 5, 5, 'test-model', 'stop', []);
    }
}

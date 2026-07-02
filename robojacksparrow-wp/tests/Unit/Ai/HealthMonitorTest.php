<?php

declare(strict_types=1);

namespace RoboJackSparrow\Tests\Unit\Ai;

use RoboJackSparrow\Ai\HealthMonitor;
use RoboJackSparrow\Tests\TestCase;

final class HealthMonitorTest extends TestCase
{
    public function testUnseenProviderDefaultsToHealthy(): void
    {
        $status = (new HealthMonitor())->getStatus('never-seen');

        $this->assertSame('healthy', $status->getStatus());
        $this->assertSame(0, $status->getConsecutiveFailures());
    }

    public function testRecordSuccessPersistsLatencyAndStatus(): void
    {
        $health = new HealthMonitor();
        $health->recordSuccess('acme', 120);

        $status = $health->getStatus('acme');
        $this->assertSame('healthy', $status->getStatus());
        $this->assertSame(120, $status->getAvgLatencyMs());
    }

    public function testFiveConsecutiveFailuresMarkProviderDown(): void
    {
        $health = new HealthMonitor();

        for ($i = 0; $i < 5; $i++) {
            $health->recordFailure('acme', 'boom');
        }

        $status = $health->getStatus('acme');
        $this->assertSame(5, $status->getConsecutiveFailures());
        $this->assertSame('down', $status->getStatus());
    }

    public function testSuccessAfterFailuresResetsConsecutiveFailureCount(): void
    {
        $health = new HealthMonitor();
        $health->recordFailure('acme', 'boom');
        $health->recordFailure('acme', 'boom');
        $health->recordSuccess('acme', 50);

        $this->assertSame(0, $health->getStatus('acme')->getConsecutiveFailures());
    }
}

<?php

declare(strict_types=1);

namespace RoboJackSparrow\Tests\Unit\Cron;

use RoboJackSparrow\Cron\ScrapeFrequency;
use RoboJackSparrow\Tests\TestCase;

final class ScrapeFrequencyTest extends TestCase
{
    public function testNeverScrapedSourceIsAlwaysDue(): void
    {
        $this->assertTrue(ScrapeFrequency::isDue(null, '4_hours'));
        $this->assertTrue(ScrapeFrequency::isDue('', 'daily'));
    }

    public function testSourceIsNotDueBeforeItsIntervalElapses(): void
    {
        $fiveMinutesAgo = gmdate('Y-m-d H:i:s', time() - 5 * 60);

        $this->assertFalse(ScrapeFrequency::isDue($fiveMinutesAgo, '15_minutes'));
        $this->assertFalse(ScrapeFrequency::isDue($fiveMinutesAgo, 'daily'));
    }

    public function testSourceIsDueOnceItsIntervalElapses(): void
    {
        $twentyMinutesAgo = gmdate('Y-m-d H:i:s', time() - 20 * 60);

        $this->assertTrue(ScrapeFrequency::isDue($twentyMinutesAgo, '15_minutes'));
        $this->assertFalse(ScrapeFrequency::isDue($twentyMinutesAgo, '4_hours'));
    }

    public function testUnknownFrequencyFallsBackToTheDefaultInterval(): void
    {
        $fiveMinutesAgo = gmdate('Y-m-d H:i:s', time() - 5 * 60);

        $this->assertFalse(ScrapeFrequency::isDue($fiveMinutesAgo, 'not-a-real-frequency'));
    }

    public function testIsValidAndChoices(): void
    {
        $this->assertTrue(ScrapeFrequency::isValid('15_minutes'));
        $this->assertFalse(ScrapeFrequency::isValid('not-a-real-frequency'));
        $this->assertArrayHasKey('daily', ScrapeFrequency::choices());
        $this->assertArrayHasKey(ScrapeFrequency::defaultKey(), ScrapeFrequency::choices());
    }
}

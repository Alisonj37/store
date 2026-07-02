<?php

declare(strict_types=1);

namespace RoboJackSparrow\Tests\Unit\Cron;

use RoboJackSparrow\Cron\CronManager;
use RoboJackSparrow\Tests\TestCase;

final class CronManagerTest extends TestCase
{
    public function testRegisterSchedulesAllFiveEventsExactlyOnce(): void
    {
        $manager = new CronManager();
        $manager->register();
        $manager->register(); // idempotent: wp_next_scheduled() guard must prevent duplicates

        $this->assertCount(5, $GLOBALS['__rjs_cron']);
        $this->assertArrayHasKey('rjs_worker_process', $GLOBALS['__rjs_cron']);
        $this->assertArrayHasKey('rjs_daily_cleanup', $GLOBALS['__rjs_cron']);
    }

    public function testCustomIntervalsFilterExposesExpectedSeconds(): void
    {
        $manager = new CronManager();
        $schedules = $manager->addCustomIntervals([]);

        $this->assertSame(60, $schedules['rjs_every_minute']['interval']);
        $this->assertSame(900, $schedules['rjs_every_15_minutes']['interval']);
        $this->assertSame(14400, $schedules['rjs_every_4_hours']['interval']);
    }

    public function testClearScheduledRemovesEveryRegisteredHook(): void
    {
        $manager = new CronManager();
        $manager->register();
        $this->assertNotEmpty($GLOBALS['__rjs_cron']);

        CronManager::clearScheduled();

        $this->assertEmpty($GLOBALS['__rjs_cron']);
    }
}

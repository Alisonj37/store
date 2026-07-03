<?php

declare(strict_types=1);

namespace RoboJackSparrow\Tests\Unit;

use RoboJackSparrow\Core\Plugin;
use RoboJackSparrow\Tests\TestCase;

/**
 * @backupGlobals disabled
 */
final class PluginBootTest extends TestCase
{
    public function testInstanceIsASingleton(): void
    {
        $a = Plugin::instance();
        $b = Plugin::instance();

        $this->assertSame($a, $b);
    }

    public function testConstructionRegistersCronCustomIntervalsAndWorkerHook(): void
    {
        Plugin::instance();

        $this->assertNotEmpty($GLOBALS['__rjs_hooks']['action']['rjs_worker_process'] ?? []);
        $this->assertNotEmpty($GLOBALS['__rjs_hooks']['filter']['cron_schedules'] ?? []);
        $this->assertCount(5, $GLOBALS['__rjs_cron']);
    }

    public function testAdminMenuIsBuiltWhenIsAdmin(): void
    {
        $GLOBALS['__rjs_is_admin'] = true;

        Plugin::instance();
        \do_action('admin_menu');

        $this->assertArrayHasKey('robojacksparrow', $GLOBALS['__rjs_menu']);
        $this->assertCount(8, $GLOBALS['__rjs_submenu']);
    }

    public function testRestRoutesRegisterRegardlessOfIsAdmin(): void
    {
        $GLOBALS['__rjs_is_admin'] = false;

        Plugin::instance();
        \do_action('rest_api_init');

        $this->assertArrayHasKey('robojacksparrow/v1 /articles', $GLOBALS['__rjs_routes']);
        $this->assertArrayHasKey('robojacksparrow/v1 /sources', $GLOBALS['__rjs_routes']);
    }
}

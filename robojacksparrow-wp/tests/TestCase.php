<?php

declare(strict_types=1);

namespace RoboJackSparrow\Tests;

use PHPUnit\Framework\TestCase as BaseTestCase;
use wpdb;

abstract class TestCase extends BaseTestCase
{
    protected wpdb $wpdb;

    protected function setUp(): void
    {
        parent::setUp();

        global $wpdb;
        $wpdb = new wpdb();
        $this->wpdb = $wpdb;

        HttpFixtures::reset();

        $GLOBALS['__rjs_hooks'] = ['action' => [], 'filter' => []];
        $GLOBALS['__rjs_options'] = [];
        $GLOBALS['__rjs_cron'] = [];
        $GLOBALS['__rjs_is_admin'] = true;
        $GLOBALS['__rjs_can'] = true;
        $GLOBALS['__rjs_menu'] = [];
        $GLOBALS['__rjs_submenu'] = [];
        $GLOBALS['__rjs_routes'] = [];
        $_GET = [];
        $_POST = [];

        $this->resetPluginSingleton();
    }

    /**
     * Plugin::instance() memoizes itself in a private static property, so
     * without resetting it here, only the first test to call it would ever
     * actually run its constructor (registerHooks(), Admin/RestApi wiring)
     * - every later test would silently get a stale cached instance built
     * against a previous test's (already-reset) $GLOBALS state.
     */
    private function resetPluginSingleton(): void
    {
        $reflection = new \ReflectionClass(\RoboJackSparrow\Core\Plugin::class);
        $property = $reflection->getProperty('instance');
        $property->setAccessible(true);
        $property->setValue(null, null);
    }
}

<?php

declare(strict_types=1);

namespace RoboJackSparrow\Cron;

class CronManager
{
    private const INTERVALS = [
        'rjs_every_minute'     => ['interval' => 60, 'label' => 'A cada minuto'],
        'rjs_every_5_minutes'  => ['interval' => 300, 'label' => 'A cada 5 minutos'],
        'rjs_every_15_minutes' => ['interval' => 900, 'label' => 'A cada 15 minutos'],
        'rjs_every_4_hours'    => ['interval' => 14400, 'label' => 'A cada 4 horas'],
    ];

    /** Hook name => recurrence key (custom interval above, or a WP-native one). */
    private const EVENTS = [
        'rjs_worker_process'   => 'rjs_every_minute',
        'rjs_rss_collect'      => 'rjs_every_4_hours',
        'rjs_scraper_collect'  => 'rjs_every_15_minutes',
        'rjs_llm_health_check' => 'rjs_every_5_minutes',
        'rjs_daily_cleanup'    => 'daily',
    ];

    public function register(): void
    {
        add_filter('cron_schedules', [$this, 'addCustomIntervals']);

        foreach (self::EVENTS as $hook => $recurrence) {
            if (!wp_next_scheduled($hook)) {
                wp_schedule_event(time(), $recurrence, $hook);
            }
        }
    }

    public function addCustomIntervals(array $schedules): array
    {
        foreach (self::INTERVALS as $key => $definition) {
            $schedules[$key] = [
                'interval' => $definition['interval'],
                'display'  => $definition['label'],
            ];
        }

        return $schedules;
    }

    /**
     * Unschedules every RoboJackSparrow cron hook. Called on plugin
     * deactivation so no orphaned events keep firing.
     */
    public static function clearScheduled(): void
    {
        foreach (array_keys(self::EVENTS) as $hook) {
            $timestamp = wp_next_scheduled($hook);

            while ($timestamp) {
                wp_unschedule_event($timestamp, $hook);
                $timestamp = wp_next_scheduled($hook);
            }

            wp_clear_scheduled_hook($hook);
        }
    }
}

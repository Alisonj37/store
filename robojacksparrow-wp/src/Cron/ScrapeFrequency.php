<?php

declare(strict_types=1);

namespace RoboJackSparrow\Cron;

/**
 * Per-source collection frequency (RSS and site-URL scraper sources alike).
 * The actual WP-Cron ticks for rjs_rss_collect/rjs_scraper_collect run
 * every 15 minutes (the fastest option here) - CronManager just triggers
 * the check; whether a given source is actually collected on that tick is
 * decided by isDue() comparing its own scrape_frequency against
 * last_scraped_at, so each source can be slower than the tick without
 * being slower than what the admin picked.
 */
final class ScrapeFrequency
{
    private const DEFAULT = '4_hours';

    /**
     * @var array<string, string>
     */
    private const CHOICES = [
        '15_minutes' => 'A cada 15 minutos',
        '30_minutes' => 'A cada 30 minutos',
        'hourly'     => 'A cada hora',
        '4_hours'    => 'A cada 4 horas',
        '6_hours'    => 'A cada 6 horas',
        '12_hours'   => 'A cada 12 horas',
        'daily'      => 'Uma vez por dia',
    ];

    /**
     * @var array<string, int>
     */
    private const SECONDS = [
        '15_minutes' => 15 * 60,
        '30_minutes' => 30 * 60,
        'hourly'     => 60 * 60,
        '4_hours'    => 4 * 60 * 60,
        '6_hours'    => 6 * 60 * 60,
        '12_hours'   => 12 * 60 * 60,
        'daily'      => 24 * 60 * 60,
    ];

    /**
     * @return array<string, string>
     */
    public static function choices(): array
    {
        return self::CHOICES;
    }

    public static function defaultKey(): string
    {
        return self::DEFAULT;
    }

    public static function isValid(string $key): bool
    {
        return isset(self::CHOICES[$key]);
    }

    /**
     * Whether a source last scraped at $lastScrapedAt (MySQL datetime
     * string, or null/empty if never scraped) is due for collection again,
     * given its configured frequency.
     */
    public static function isDue(?string $lastScrapedAt, string $frequencyKey): bool
    {
        if ($lastScrapedAt === null || trim($lastScrapedAt) === '') {
            return true;
        }

        $lastScrapedTimestamp = strtotime($lastScrapedAt . ' UTC');
        if ($lastScrapedTimestamp === false) {
            return true;
        }

        $intervalSeconds = self::SECONDS[$frequencyKey] ?? self::SECONDS[self::DEFAULT];

        return (time() - $lastScrapedTimestamp) >= $intervalSeconds;
    }
}

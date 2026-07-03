<?php

declare(strict_types=1);

namespace RoboJackSparrow\Tests;

/**
 * Registers canned wp_remote_get()/wp_remote_post() responses keyed by
 * HTTP method + exact URL (or a URL prefix), so provider classes (LLM,
 * Scraper, Research, Image) can be tested without real network access.
 */
final class HttpFixtures
{
    /** @var array<string, array{response: array, calls: int}> */
    private static array $exact = [];

    /** @var array<int, array{method: string, prefix: string, handler: callable}> */
    private static array $prefixes = [];

    public static function reset(): void
    {
        self::$exact = [];
        self::$prefixes = [];
    }

    /**
     * @param array{response?: array{code:int}, body?: string}|\WP_Error $response
     */
    public static function set(string $method, string $url, $response): void
    {
        self::$exact[$method . ' ' . $url] = ['response' => $response, 'calls' => 0];
    }

    /**
     * @param callable(string $url, array $args, int $callNumber): (array|\WP_Error) $handler
     */
    public static function setPrefix(string $method, string $prefix, callable $handler): void
    {
        self::$prefixes[] = ['method' => $method, 'prefix' => $prefix, 'handler' => $handler];
    }

    public static function callCount(string $method, string $url): int
    {
        return self::$exact[$method . ' ' . $url]['calls'] ?? 0;
    }

    public static function handle(string $method, string $url, array $args)
    {
        $key = $method . ' ' . $url;

        if (isset(self::$exact[$key])) {
            self::$exact[$key]['calls']++;

            return self::$exact[$key]['response'];
        }

        foreach (self::$prefixes as $i => $fixture) {
            if ($fixture['method'] === $method && str_starts_with($url, $fixture['prefix'])) {
                self::$prefixes[$i]['calls'] = ($fixture['calls'] ?? 0) + 1;

                return ($fixture['handler'])($url, $args, self::$prefixes[$i]['calls']);
            }
        }

        return new \WP_Error('http_request_failed', "No fixture registered for {$method} {$url}");
    }
}

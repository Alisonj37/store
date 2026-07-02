<?php

declare(strict_types=1);

namespace RoboJackSparrow\Ai;

use RoboJackSparrow\Ai\Dto\ProviderHealth;
use wpdb;

/**
 * Le/grava o health-check dos provedores de LLM na tabela
 * {prefix}_rjs_llm_health, usada pelo LLMRouter para o score composto.
 */
class HealthMonitor
{
    private const DOWN_THRESHOLD = 5;
    private const DEGRADED_THRESHOLD = 2;

    private const FIELD_FORMATS = [
        'provider'             => '%s',
        'status'               => '%s',
        'last_check'           => '%s',
        'last_success'         => '%s',
        'last_error'           => '%s',
        'avg_latency_ms'       => '%d',
        'success_rate_24h'     => '%f',
        'consecutive_failures' => '%d',
    ];

    private wpdb $db;
    private string $table;

    public function __construct()
    {
        global $wpdb;

        $this->db = $wpdb;
        $this->table = $wpdb->prefix . 'rjs_llm_health';
    }

    public function getStatus(string $provider): ProviderHealth
    {
        $row = $this->db->get_row(
            $this->db->prepare("SELECT * FROM {$this->table} WHERE provider = %s", $provider)
        );

        if ($row === null) {
            // Never checked before: assume healthy so it gets a fair chance
            // to be selected by the router instead of being skipped forever.
            return new ProviderHealth($provider, 'healthy', 0, 100.0, 0);
        }

        return new ProviderHealth(
            $provider,
            (string) $row->status,
            (int) $row->avg_latency_ms,
            $row->success_rate_24h !== null ? (float) $row->success_rate_24h : 100.0,
            (int) $row->consecutive_failures
        );
    }

    public function recordSuccess(string $provider, int $latencyMs): void
    {
        $current = $this->getStatus($provider);

        $newAvgLatency = $current->getAvgLatencyMs() > 0
            ? (int) round(($current->getAvgLatencyMs() + $latencyMs) / 2)
            : $latencyMs;

        $this->upsert($provider, [
            'status'               => 'healthy',
            'last_check'           => current_time('mysql'),
            'last_success'         => current_time('mysql'),
            'avg_latency_ms'       => $newAvgLatency,
            'success_rate_24h'     => min(100.0, $current->getSuccessRate24h() + 1.0),
            'consecutive_failures' => 0,
        ]);
    }

    public function recordFailure(string $provider, string $errorMessage): void
    {
        $current = $this->getStatus($provider);
        $consecutiveFailures = $current->getConsecutiveFailures() + 1;

        $status = 'degraded';
        if ($consecutiveFailures >= self::DOWN_THRESHOLD) {
            $status = 'down';
        } elseif ($consecutiveFailures < self::DEGRADED_THRESHOLD) {
            $status = 'healthy';
        }

        $this->upsert($provider, [
            'status'               => $status,
            'last_check'           => current_time('mysql'),
            'last_error'           => $errorMessage,
            'success_rate_24h'     => max(0.0, $current->getSuccessRate24h() - 5.0),
            'consecutive_failures' => $consecutiveFailures,
        ]);
    }

    private function upsert(string $provider, array $data): void
    {
        $exists = (int) $this->db->get_var(
            $this->db->prepare("SELECT COUNT(*) FROM {$this->table} WHERE provider = %s", $provider)
        );

        $formats = array_map(static fn (string $field) => self::FIELD_FORMATS[$field], array_keys($data));

        if ($exists > 0) {
            $this->db->update($this->table, $data, ['provider' => $provider], $formats, ['%s']);

            return;
        }

        $data['provider'] = $provider;
        $formats[] = self::FIELD_FORMATS['provider'];
        $this->db->insert($this->table, $data, $formats);
    }
}

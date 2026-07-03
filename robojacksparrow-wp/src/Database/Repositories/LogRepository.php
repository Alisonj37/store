<?php

declare(strict_types=1);

namespace RoboJackSparrow\Database\Repositories;

use wpdb;

class LogRepository
{
    private wpdb $db;
    private string $table;

    public function __construct()
    {
        global $wpdb;

        $this->db = $wpdb;
        $this->table = $wpdb->prefix . 'rjs_logs';
    }

    /**
     * @return object[]
     */
    public function findRecent(int $limit = 50, ?string $level = null): array
    {
        if ($level !== null) {
            $rows = $this->db->get_results($this->db->prepare(
                "SELECT * FROM {$this->table} WHERE level = %s ORDER BY created_at DESC LIMIT %d",
                $level,
                $limit
            ));
        } else {
            $rows = $this->db->get_results($this->db->prepare(
                "SELECT * FROM {$this->table} ORDER BY created_at DESC LIMIT %d",
                $limit
            ));
        }

        return is_array($rows) ? $rows : [];
    }

    /**
     * @param array{level?: ?string, source?: ?string, article_id?: ?int, since?: ?string} $filters
     * @return object[]
     */
    public function findFiltered(array $filters, int $limit = 50): array
    {
        $conditions = [];
        $args = [];

        $level = $filters['level'] ?? null;
        if ($level !== null && $level !== '') {
            $conditions[] = 'level = %s';
            $args[] = $level;
        }

        $source = $filters['source'] ?? null;
        if ($source !== null && $source !== '') {
            $conditions[] = 'source = %s';
            $args[] = $source;
        }

        $articleId = $filters['article_id'] ?? null;
        if ($articleId !== null && $articleId > 0) {
            $conditions[] = 'article_id = %d';
            $args[] = $articleId;
        }

        $since = $filters['since'] ?? null;
        if ($since !== null && $since !== '') {
            $conditions[] = 'created_at >= %s';
            $args[] = $since;
        }

        $where = $conditions !== [] ? 'WHERE ' . implode(' AND ', $conditions) : '';
        $args[] = $limit;

        $rows = $this->db->get_results($this->db->prepare(
            "SELECT * FROM {$this->table} {$where} ORDER BY created_at DESC LIMIT %d",
            ...$args
        ));

        return is_array($rows) ? $rows : [];
    }

    /**
     * Distinct 'source' values seen so far (e.g. "ContentEngine::generate"),
     * used to populate the "Modulo" filter dropdown without hardcoding a
     * list that would drift from the actual logged callers.
     *
     * @return string[]
     */
    public function distinctSources(): array
    {
        $rows = $this->db->get_results("SELECT DISTINCT source FROM {$this->table} ORDER BY source ASC");

        return array_map(static fn ($row) => (string) $row->source, is_array($rows) ? $rows : []);
    }

    /**
     * @return array<string, int>
     */
    public function countByLevel(): array
    {
        $rows = $this->db->get_results("SELECT level, COUNT(*) as total FROM {$this->table} GROUP BY level");

        $counts = [];
        foreach ((is_array($rows) ? $rows : []) as $row) {
            $counts[(string) $row->level] = (int) $row->total;
        }

        return $counts;
    }
}

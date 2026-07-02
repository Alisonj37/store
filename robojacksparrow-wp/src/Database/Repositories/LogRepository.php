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

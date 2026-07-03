<?php

declare(strict_types=1);

namespace RoboJackSparrow\Database\Repositories;

use wpdb;

class ArticleRepository
{
    private wpdb $db;
    private string $table;

    public function __construct()
    {
        global $wpdb;

        $this->db = $wpdb;
        $this->table = $wpdb->prefix . 'rjs_articles';
    }

    /**
     * @return object[]
     */
    public function findRecent(int $limit = 20, int $offset = 0, ?string $status = null): array
    {
        if ($status !== null) {
            $rows = $this->db->get_results($this->db->prepare(
                "SELECT * FROM {$this->table} WHERE status = %s ORDER BY created_at DESC LIMIT %d OFFSET %d",
                $status,
                $limit,
                $offset
            ));
        } else {
            $rows = $this->db->get_results($this->db->prepare(
                "SELECT * FROM {$this->table} ORDER BY created_at DESC LIMIT %d OFFSET %d",
                $limit,
                $offset
            ));
        }

        return is_array($rows) ? $rows : [];
    }

    public function find(int $id): ?object
    {
        $row = $this->db->get_row(
            $this->db->prepare("SELECT * FROM {$this->table} WHERE id = %d", $id)
        );

        return $row instanceof \stdClass ? $row : null;
    }

    public function create(array $data): int
    {
        $data['created_at'] = current_time('mysql');
        $data['updated_at'] = current_time('mysql');

        $this->db->insert($this->table, $data);

        return (int) $this->db->insert_id;
    }

    public function update(int $id, array $data): bool
    {
        $data['updated_at'] = current_time('mysql');

        return (bool) $this->db->update($this->table, $data, ['id' => $id]);
    }

    /**
     * Used by RSS collection to avoid creating a duplicate article for a
     * feed item that was already imported on a previous run.
     */
    public function existsBySourceUrl(string $url): bool
    {
        $id = $this->db->get_var(
            $this->db->prepare("SELECT id FROM {$this->table} WHERE source_url = %s LIMIT 1", $url)
        );

        return $id !== null;
    }

    public function count(?string $status = null): int
    {
        if ($status !== null) {
            return (int) $this->db->get_var(
                $this->db->prepare("SELECT COUNT(*) FROM {$this->table} WHERE status = %s", $status)
            );
        }

        return (int) $this->db->get_var("SELECT COUNT(*) FROM {$this->table}");
    }

    /**
     * @return array<string, int>
     */
    public function countByStatus(): array
    {
        $rows = $this->db->get_results("SELECT status, COUNT(*) as total FROM {$this->table} GROUP BY status");

        $counts = [];
        foreach ((is_array($rows) ? $rows : []) as $row) {
            $counts[(string) $row->status] = (int) $row->total;
        }

        return $counts;
    }
}

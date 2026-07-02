<?php

declare(strict_types=1);

namespace RoboJackSparrow\Database\Repositories;

use wpdb;

class SourceRepository
{
    private wpdb $db;
    private string $table;

    public function __construct()
    {
        global $wpdb;

        $this->db = $wpdb;
        $this->table = $wpdb->prefix . 'rjs_sources';
    }

    /**
     * @return object[]
     */
    public function findAll(): array
    {
        $rows = $this->db->get_results("SELECT * FROM {$this->table} ORDER BY created_at DESC");

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

    public function delete(int $id): bool
    {
        return (bool) $this->db->delete($this->table, ['id' => $id]);
    }
}

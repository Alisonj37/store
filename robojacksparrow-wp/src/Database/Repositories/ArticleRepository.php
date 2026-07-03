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
     * Other already-published articles used as internal linking candidates
     * for a newly generated one. Prefers the same category when available,
     * falling back to the most recent published articles overall.
     *
     * @return object[]
     */
    public function findRelatedPublished(int $excludeArticleId, ?string $categoryName, int $limit = 3): array
    {
        if ($categoryName !== null && trim($categoryName) !== '') {
            $rows = $this->db->get_results($this->db->prepare(
                "SELECT id, source_title, wordpress_post_url FROM {$this->table}
                 WHERE status = 'published' AND wordpress_post_url IS NOT NULL
                 AND id != %d AND category_name = %s
                 ORDER BY created_at DESC LIMIT %d",
                $excludeArticleId,
                $categoryName,
                $limit
            ));

            if (is_array($rows) && $rows !== []) {
                return $rows;
            }
        }

        $rows = $this->db->get_results($this->db->prepare(
            "SELECT id, source_title, wordpress_post_url FROM {$this->table}
             WHERE status = 'published' AND wordpress_post_url IS NOT NULL AND id != %d
             ORDER BY created_at DESC LIMIT %d",
            $excludeArticleId,
            $limit
        ));

        return is_array($rows) ? $rows : [];
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

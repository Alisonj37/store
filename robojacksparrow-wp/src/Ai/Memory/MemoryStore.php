<?php

declare(strict_types=1);

namespace RoboJackSparrow\Ai\Memory;

use wpdb;

/**
 * Persists conversational memory in {prefix}_rjs_memory (see
 * Database\Schema). Backs MemoryBuffer's sliding window.
 */
class MemoryStore
{
    private wpdb $db;
    private string $table;

    public function __construct()
    {
        global $wpdb;

        $this->db = $wpdb;
        $this->table = $wpdb->prefix . 'rjs_memory';
    }

    public function insert(string $memoryKey, string $role, string $content, int $tokens = 0): void
    {
        $this->db->insert(
            $this->table,
            [
                'memory_key' => $memoryKey,
                'role'       => $role,
                'content'    => $content,
                'tokens'     => $tokens,
                'created_at' => current_time('mysql'),
            ],
            ['%s', '%s', '%s', '%d', '%s']
        );
    }

    /**
     * @return array<int, array{role: string, content: string, tokens: int}>
     */
    public function getRecent(string $memoryKey, int $limit): array
    {
        $rows = $this->db->get_results(
            $this->db->prepare(
                "SELECT role, content, tokens FROM {$this->table} WHERE memory_key = %s ORDER BY created_at ASC, id ASC LIMIT %d",
                $memoryKey,
                $limit
            ),
            ARRAY_A
        );

        return is_array($rows) ? $rows : [];
    }

    /**
     * @return array<int, array{id: int, role: string, content: string, tokens: int}>
     */
    public function getAll(string $memoryKey): array
    {
        $rows = $this->db->get_results(
            $this->db->prepare(
                "SELECT id, role, content, tokens FROM {$this->table} WHERE memory_key = %s ORDER BY created_at ASC, id ASC",
                $memoryKey
            ),
            ARRAY_A
        );

        return is_array($rows) ? $rows : [];
    }

    public function deleteOldest(string $memoryKey): void
    {
        $oldestId = $this->db->get_var(
            $this->db->prepare(
                "SELECT id FROM {$this->table} WHERE memory_key = %s ORDER BY created_at ASC, id ASC LIMIT 1",
                $memoryKey
            )
        );

        if ($oldestId !== null) {
            $this->db->delete($this->table, ['id' => (int) $oldestId], ['%d']);
        }
    }

    public function clear(string $memoryKey): void
    {
        $this->db->delete($this->table, ['memory_key' => $memoryKey], ['%s']);
    }
}

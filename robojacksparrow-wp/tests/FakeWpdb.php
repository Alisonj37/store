<?php

declare(strict_types=1);

/**
 * Global-namespace fake of WordPress's $wpdb, since the Repositories under
 * test type-hint against the real `wpdb` class name. Consolidates the
 * stateful, multi-table stub used across every phase's manual smoke test
 * into a single reusable double for the PHPUnit suite.
 *
 * Also carries a handful of extra public methods (insertPost, uploadBits,
 * etc.) that back the WP post/media/taxonomy function stubs in
 * tests/Bootstrap.php, so Publisher tests can assert against the same
 * in-memory state.
 */
class wpdb
{
    public string $prefix = 'wp_';
    public int $insert_id = 0;

    /** @var array<string, array<string, mixed>> keyed by setting_key */
    public array $settings = [];
    /** @var array<int, array<string, mixed>> keyed by id */
    public array $articles = [];
    /** @var array<int, array<string, mixed>> keyed by id */
    public array $queueRows = [];
    /** @var array<int, array<string, mixed>> keyed by id */
    public array $logs = [];
    /** @var array<int, array<string, mixed>> keyed by id */
    public array $sources = [];
    /** @var array<string, array<string, mixed>> keyed by provider */
    public array $llmHealth = [];
    /** @var array<string, array<string, mixed>> keyed by memory_key, list of rows */
    public array $memory = [];

    /** @var array<int, array<string, mixed>> keyed by post id */
    public array $posts = [];
    /** @var array<int, array<string, mixed>> keyed by post id => [meta_key => value] */
    public array $postMeta = [];
    /** @var array<int, array<int, string>> keyed by post id */
    public array $postTags = [];
    /** @var array<string, int> category name => term_id */
    public array $categories = [];
    /** @var array<int, int> post id => attachment id */
    public array $thumbnails = [];

    private array $lastPrepareArgs = [];
    private int $nextArticleId = 1;
    private int $nextQueueId = 1;
    private int $nextLogId = 1;
    private int $nextSourceId = 1;
    private int $nextSettingId = 1;
    private int $nextMemoryId = 1;
    private int $nextPostId = 101;
    private int $nextTermId = 200;
    private int $nextAttachmentId = 500;
    public string $uploadFailureMessage = '';

    // ---- Core wpdb API used by the Repositories --------------------------

    public function get_charset_collate(): string
    {
        return 'DEFAULT CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci';
    }

    public function prepare($query, ...$args)
    {
        $this->lastPrepareArgs = $args;

        return $query;
    }

    public function query($sql)
    {
        if (str_contains($sql, 'rjs_queue') && str_contains($sql, "status = 'pending'") && str_contains($sql, "WHERE status = 'processing'")) {
            return $this->reclaimStaleProcessing();
        }

        return 1;
    }

    /**
     * Backs QueueManager::reclaimStaleProcessingJobs(). Prepared args are
     * [availableAt, updatedAt, threshold] in that order.
     */
    private function reclaimStaleProcessing(): int
    {
        $availableAt = $this->lastPrepareArgs[0] ?? gmdate('Y-m-d H:i:s');
        $updatedAt = $this->lastPrepareArgs[1] ?? gmdate('Y-m-d H:i:s');
        $threshold = $this->lastPrepareArgs[2] ?? gmdate('Y-m-d H:i:s');

        $reclaimed = 0;
        foreach ($this->queueRows as $id => $row) {
            $startedAt = $row['started_at'] ?? null;
            if (($row['status'] ?? '') === 'processing' && $startedAt !== null && $startedAt <= $threshold) {
                $this->queueRows[$id]['status'] = 'pending';
                $this->queueRows[$id]['available_at'] = $availableAt;
                $this->queueRows[$id]['updated_at'] = $updatedAt;
                $reclaimed++;
            }
        }

        return $reclaimed;
    }

    public function get_var($query)
    {
        if (str_contains($query, 'rjs_settings') && str_contains($query, 'SELECT id')) {
            $key = $this->lastPrepareArgs[0] ?? null;

            return isset($this->settings[$key]) ? $this->settings[$key]['id'] : null;
        }

        if (str_contains($query, 'rjs_articles') && str_contains($query, 'COUNT(*)')) {
            return $this->countRows($this->articles, str_contains($query, 'WHERE') ? ($this->lastPrepareArgs[0] ?? null) : null, 'status');
        }

        if (str_contains($query, 'rjs_articles') && str_contains($query, 'SELECT id') && str_contains($query, 'source_url')) {
            $url = $this->lastPrepareArgs[0] ?? null;
            foreach ($this->articles as $row) {
                if (($row['source_url'] ?? null) === $url) {
                    return $row['id'];
                }
            }

            return null;
        }

        if (str_contains($query, 'rjs_queue') && str_contains($query, 'COUNT(*)')) {
            return $this->countRows($this->queueRows, str_contains($query, 'WHERE') ? ($this->lastPrepareArgs[0] ?? null) : null, 'status');
        }

        if (str_contains($query, 'rjs_memory') && str_contains($query, 'SELECT id')) {
            $key = $this->lastPrepareArgs[0] ?? null;
            foreach ($this->memory[$key] ?? [] as $row) {
                return $row['id'];
            }

            return null;
        }

        return 0;
    }

    public function get_row($query)
    {
        if (str_contains($query, 'rjs_settings') && str_starts_with(trim($query), 'SELECT is_sensitive')) {
            $key = $this->lastPrepareArgs[0] ?? null;

            return isset($this->settings[$key]) ? (object) ['is_sensitive' => $this->settings[$key]['is_sensitive']] : null;
        }

        if (str_contains($query, 'rjs_settings')) {
            $key = $this->lastPrepareArgs[0] ?? null;

            return isset($this->settings[$key]) ? (object) $this->settings[$key] : null;
        }

        if (str_contains($query, 'FOR UPDATE')) {
            // QueueManager::popNextAvailable() joins rjs_queue + rjs_articles,
            // so this must be checked before the plain rjs_articles branch.
            return $this->popNextQueueRow();
        }

        if (str_contains($query, 'rjs_articles')) {
            $id = $this->lastPrepareArgs[0] ?? null;

            return isset($this->articles[$id]) ? (object) $this->articles[$id] : null;
        }

        if (str_contains($query, 'rjs_queue')) {
            $id = $this->lastPrepareArgs[0] ?? null;

            return isset($this->queueRows[$id]) ? (object) $this->queueRows[$id] : null;
        }

        if (str_contains($query, 'rjs_sources')) {
            $id = $this->lastPrepareArgs[0] ?? null;

            return isset($this->sources[$id]) ? (object) $this->sources[$id] : null;
        }

        if (str_contains($query, 'rjs_llm_health')) {
            $provider = $this->lastPrepareArgs[0] ?? null;

            return isset($this->llmHealth[$provider]) ? (object) $this->llmHealth[$provider] : null;
        }

        return null;
    }

    public function get_results($query, $output = null)
    {
        if (str_contains($query, 'GROUP BY status') && str_contains($query, 'rjs_articles')) {
            return $this->groupByCounts($this->articles, 'status');
        }
        if (str_contains($query, 'GROUP BY status') && str_contains($query, 'rjs_queue')) {
            return $this->groupByCounts($this->queueRows, 'status');
        }
        if (str_contains($query, 'GROUP BY level')) {
            return $this->groupByCounts($this->logs, 'level');
        }
        if (str_contains($query, 'rjs_articles') && str_contains($query, "status = 'published'") && str_contains($query, 'wordpress_post_url IS NOT NULL')) {
            return $this->selectRelatedPublished($query, $this->lastPrepareArgs);
        }
        if (str_contains($query, 'rjs_articles')) {
            return $this->selectRows($this->articles, $query, 'status');
        }
        if (str_contains($query, 'rjs_queue')) {
            return $this->selectRows($this->queueRows, $query, 'status');
        }
        if (str_contains($query, 'rjs_logs')) {
            return $this->selectRows($this->logs, $query, 'level');
        }
        if (str_contains($query, 'rjs_sources')) {
            $rows = array_values($this->sources);
            usort($rows, static fn ($a, $b) => strcmp((string) $b['created_at'], (string) $a['created_at']));

            return array_map(static fn ($r) => (object) $r, $rows);
        }
        if (str_contains($query, 'rjs_memory')) {
            $key = $this->lastPrepareArgs[0] ?? null;
            $limit = $this->lastPrepareArgs[1] ?? null;
            $rows = $this->memory[$key] ?? [];
            if ($limit !== null) {
                $rows = array_slice($rows, 0, (int) $limit);
            }

            return $rows;
        }

        return [];
    }

    public function insert($table, $data, $formats = null)
    {
        if (str_contains($table, 'rjs_settings')) {
            $data['id'] = $this->nextSettingId++;
            $this->settings[$data['setting_key']] = $data;
        } elseif (str_contains($table, 'rjs_sources')) {
            $id = $this->nextSourceId++;
            $data['id'] = $id;
            $this->sources[$id] = $data;
            $this->insert_id = $id;
        } elseif (str_contains($table, 'rjs_articles')) {
            $id = $this->nextArticleId++;
            $data['id'] = $id;
            $this->articles[$id] = $data;
            $this->insert_id = $id;
        } elseif (str_contains($table, 'rjs_queue')) {
            $id = $this->nextQueueId++;
            $data['id'] = $id;
            $this->queueRows[$id] = $data;
            $this->insert_id = $id;
        } elseif (str_contains($table, 'rjs_logs')) {
            $id = $this->nextLogId++;
            $data['id'] = $id;
            $this->logs[$id] = $data;
            $this->insert_id = $id;
        } elseif (str_contains($table, 'rjs_llm_health')) {
            // Emulate a real dbDelta row: every column present (NULL/0
            // default for ones the caller didn't set), like a real
            // `SELECT *` would return - PHP object property access on a
            // partial array-turned-stdClass would otherwise warn.
            $defaults = [
                'provider' => null, 'status' => null, 'last_check' => null,
                'last_success' => null, 'last_error' => null, 'avg_latency_ms' => 0,
                'success_rate_24h' => 100.0, 'consecutive_failures' => 0,
            ];
            $this->llmHealth[$data['provider']] = array_merge($defaults, $data);
        } elseif (str_contains($table, 'rjs_memory')) {
            $data['id'] = $this->nextMemoryId++;
            $this->memory[$data['memory_key']][] = $data;
        }

        return 1;
    }

    public function update($table, $data, $where, $formats = null, $whereFormats = null)
    {
        if (str_contains($table, 'rjs_settings')) {
            $key = $where['setting_key'] ?? null;
            if ($key !== null && isset($this->settings[$key])) {
                $this->settings[$key] = array_merge($this->settings[$key], $data);
            }
        } elseif (str_contains($table, 'rjs_sources')) {
            $id = $where['id'] ?? null;
            if ($id !== null && isset($this->sources[$id])) {
                $this->sources[$id] = array_merge($this->sources[$id], $data);
            }
        } elseif (str_contains($table, 'rjs_articles')) {
            $id = $where['id'] ?? null;
            if ($id !== null && isset($this->articles[$id])) {
                $this->articles[$id] = array_merge($this->articles[$id], $data);
            }
        } elseif (str_contains($table, 'rjs_queue')) {
            $id = $where['id'] ?? null;
            if ($id !== null && isset($this->queueRows[$id])) {
                $this->queueRows[$id] = array_merge($this->queueRows[$id], $data);
            }
        } elseif (str_contains($table, 'rjs_llm_health')) {
            $provider = $where['provider'] ?? null;
            if ($provider !== null && isset($this->llmHealth[$provider])) {
                $this->llmHealth[$provider] = array_merge($this->llmHealth[$provider], $data);
            }
        }

        return 1;
    }

    public function delete($table, $where, $formats = null)
    {
        if (str_contains($table, 'rjs_sources')) {
            $id = $where['id'] ?? null;
            if ($id !== null) {
                unset($this->sources[$id]);
            }
        } elseif (str_contains($table, 'rjs_memory')) {
            if (isset($where['id'])) {
                foreach ($this->memory as $key => $rows) {
                    $this->memory[$key] = array_values(array_filter($rows, static fn ($r) => $r['id'] !== $where['id']));
                }
            } elseif (isset($where['memory_key'])) {
                unset($this->memory[$where['memory_key']]);
            }
        }

        return 1;
    }

    // ---- Queue-specific: atomic claim used by QueueManager::popNextAvailable() --

    private function popNextQueueRow(): ?object
    {
        // Matches "WHERE q.status = %s AND q.available_at <= %s" in
        // QueueManager::popNextAvailable() - a job released with a future
        // retry delay must not be immediately re-pickable.
        $now = $this->lastPrepareArgs[1] ?? gmdate('Y-m-d H:i:s');

        $pending = array_filter(
            $this->queueRows,
            static fn ($r) => $r['status'] === 'pending' && (string) $r['available_at'] <= $now
        );

        if ($pending === []) {
            return null;
        }

        uasort($pending, function ($a, $b) {
            $priorityA = $this->articles[$a['article_id']]['priority'] ?? 5;
            $priorityB = $this->articles[$b['article_id']]['priority'] ?? 5;
            if ($priorityA !== $priorityB) {
                return $priorityA <=> $priorityB;
            }

            return strcmp((string) $a['created_at'], (string) $b['created_at']);
        });

        $first = reset($pending);

        return (object) $first;
    }

    // ---- Test helpers ------------------------------------------------------

    /**
     * Resets all in-memory state. Call from TestCase::setUp() so tests
     * never leak state into one another.
     */
    public function reset(): void
    {
        $this->settings = [];
        $this->articles = [];
        $this->queueRows = [];
        $this->logs = [];
        $this->sources = [];
        $this->llmHealth = [];
        $this->memory = [];
        $this->posts = [];
        $this->postMeta = [];
        $this->postTags = [];
        $this->categories = [];
        $this->thumbnails = [];
        $this->insert_id = 0;
        $this->uploadFailureMessage = '';
        $this->forceInsertPostFailure = false;
        $this->throwTypeErrorOnAttachment = false;
        $this->nextArticleId = 1;
        $this->nextQueueId = 1;
        $this->nextLogId = 1;
        $this->nextSourceId = 1;
        $this->nextSettingId = 1;
        $this->nextMemoryId = 1;
        $this->nextPostId = 101;
        $this->nextTermId = 200;
        $this->nextAttachmentId = 500;
    }

    private function countRows(array $rows, mixed $filterValue, string $field): int
    {
        if ($filterValue === null) {
            return count($rows);
        }

        return count(array_filter($rows, static fn ($r) => $r[$field] === $filterValue));
    }

    private function groupByCounts(array $rows, string $field): array
    {
        $counts = [];
        foreach ($rows as $row) {
            $key = $row[$field];
            $counts[$key] = ($counts[$key] ?? 0) + 1;
        }

        $result = [];
        foreach ($counts as $key => $total) {
            $result[] = (object) [$field => $key, 'total' => $total];
        }

        return $result;
    }

    /**
     * Backs ArticleRepository::findRelatedPublished(): a distinct shape from
     * selectRows() (multiple AND'ed conditions, one of them a hardcoded
     * literal rather than a placeholder, plus an exclude-by-id and an
     * optional category filter) rather than the single filterField+limit
     * pattern selectRows() assumes.
     */
    private function selectRelatedPublished(string $query, array $args): array
    {
        if (str_contains($query, 'category_name = %s')) {
            [$excludeId, $categoryName, $limit] = $args;
        } else {
            [$excludeId, $limit] = $args;
            $categoryName = null;
        }

        $filtered = array_filter($this->articles, static function (array $row) use ($excludeId, $categoryName): bool {
            if (($row['status'] ?? null) !== 'published') {
                return false;
            }
            if (empty($row['wordpress_post_url'])) {
                return false;
            }
            if ((int) ($row['id'] ?? 0) === (int) $excludeId) {
                return false;
            }
            if ($categoryName !== null && ($row['category_name'] ?? null) !== $categoryName) {
                return false;
            }

            return true;
        });

        $filtered = array_values($filtered);
        usort($filtered, static fn (array $a, array $b) => strcmp((string) $b['created_at'], (string) $a['created_at']));
        $filtered = array_slice($filtered, 0, (int) $limit);

        return array_map(static fn (array $r) => (object) $r, $filtered);
    }

    private function selectRows(array $rows, string $query, string $filterField): array
    {
        $hasWhere = str_contains($query, 'WHERE');
        $args = $this->lastPrepareArgs;

        $filtered = array_values($rows);
        usort($filtered, static fn ($a, $b) => strcmp((string) $b['created_at'], (string) $a['created_at']));

        if ($hasWhere) {
            $filterValue = array_shift($args);
            $filtered = array_values(array_filter($filtered, static fn ($r) => $r[$filterField] === $filterValue));
        }

        if (count($args) >= 2) {
            [$limit, $offset] = $args;
            $filtered = array_slice($filtered, (int) $offset, (int) $limit);
        } elseif (count($args) === 1) {
            $filtered = array_slice($filtered, 0, (int) $args[0]);
        }

        return array_map(static fn ($r) => (object) $r, $filtered);
    }

    // ---- Publisher-facing helpers (backs the wp_insert_post()-family stubs) --

    public bool $forceInsertPostFailure = false;

    public function insertPost(array $data)
    {
        if ($this->forceInsertPostFailure) {
            return new WP_Error('db_insert_error', 'Simulated DB failure');
        }

        $id = $this->nextPostId++;
        $this->posts[$id] = $data;

        return $id;
    }

    public function getTermByName(string $name)
    {
        return isset($this->categories[$name]) ? (object) ['term_id' => $this->categories[$name]] : false;
    }

    public function insertTerm(string $name)
    {
        $id = $this->nextTermId++;
        $this->categories[$name] = $id;

        return ['term_id' => $id];
    }

    public function getTermById(int $termId)
    {
        foreach ($this->categories as $name => $id) {
            if ($id === $termId) {
                return (object) ['term_id' => $id, 'name' => $name];
            }
        }

        return null;
    }

    /**
     * @return object[]
     */
    public function getAllCategories(): array
    {
        $result = [];
        foreach ($this->categories as $name => $id) {
            $result[] = (object) ['term_id' => $id, 'name' => $name];
        }

        return $result;
    }

    public function setPostTags(int $postId, array $tags): void
    {
        $this->postTags[$postId] = $tags;
    }

    public function uploadBits(string $filename, string $bits): array
    {
        if ($this->uploadFailureMessage !== '') {
            return ['error' => $this->uploadFailureMessage];
        }

        return ['file' => '/tmp/fake-wp/uploads/' . $filename, 'url' => 'https://example.test/uploads/' . $filename, 'error' => false];
    }

    public bool $throwTypeErrorOnAttachment = false;

    public function insertAttachment(array $data, string $file, int $postId): int
    {
        if ($this->throwTypeErrorOnAttachment) {
            // Simulates a raw WP-core/third-party-hook failure that is NOT
            // a PublisherException (e.g. a malformed image reaching
            // wp_generate_attachment_metadata()), to prove callers degrade
            // gracefully on any Throwable, not just our own exception type.
            throw new \TypeError('Simulated malformed attachment data');
        }

        return $this->nextAttachmentId++;
    }

    public function setPostThumbnail(int $postId, int $attachmentId): void
    {
        $this->thumbnails[$postId] = $attachmentId;
    }

    public function updatePostMeta(int $postId, string $key, mixed $value): void
    {
        $this->postMeta[$postId][$key] = $value;
    }
}

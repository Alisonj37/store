<?php

declare(strict_types=1);

namespace RoboJackSparrow\Queue;

use RoboJackSparrow\Core\Logger;
use wpdb;

class QueueManager
{
    private wpdb $db;
    private Logger $logger;
    private string $queueTable;
    private string $articlesTable;

    public function __construct(Logger $logger)
    {
        global $wpdb;

        $this->db = $wpdb;
        $this->logger = $logger;
        $this->queueTable = $wpdb->prefix . 'rjs_queue';
        $this->articlesTable = $wpdb->prefix . 'rjs_articles';
    }

    public function enqueue(int $articleId, string $jobType, array $payload = [], int $delaySeconds = 0, int $maxAttempts = 3): int
    {
        $availableAt = gmdate('Y-m-d H:i:s', time() + max(0, $delaySeconds));

        $inserted = $this->db->insert(
            $this->queueTable,
            [
                'article_id'   => $articleId,
                'job_type'     => $jobType,
                'job_payload'  => wp_json_encode($payload),
                'status'       => 'pending',
                'attempts'     => 0,
                'max_attempts' => $maxAttempts,
                'available_at' => $availableAt,
                'created_at'   => current_time('mysql'),
                'updated_at'   => current_time('mysql'),
            ],
            ['%d', '%s', '%s', '%s', '%d', '%d', '%s', '%s', '%s']
        );

        if ($inserted === false) {
            $this->logger->error('Failed to enqueue job', [
                'article_id' => $articleId,
                'job_type'   => $jobType,
            ]);

            return 0;
        }

        return (int) $this->db->insert_id;
    }

    /**
     * Atomically claims the next available job (SELECT ... FOR UPDATE inside a
     * transaction) so concurrent cron runs never process the same row twice.
     */
    public function popNextAvailable(): ?Job
    {
        $this->db->query('START TRANSACTION');

        $row = $this->db->get_row(
            $this->db->prepare(
                "SELECT q.* FROM {$this->queueTable} q
                 INNER JOIN {$this->articlesTable} a ON a.id = q.article_id
                 WHERE q.status = %s AND q.available_at <= %s
                 ORDER BY a.priority ASC, q.created_at ASC
                 LIMIT 1
                 FOR UPDATE",
                'pending',
                current_time('mysql')
            )
        );

        if ($row === null) {
            $this->db->query('COMMIT');

            return null;
        }

        $updated = $this->db->update(
            $this->queueTable,
            [
                'status'     => 'processing',
                'started_at' => current_time('mysql'),
                'updated_at' => current_time('mysql'),
            ],
            ['id' => (int) $row->id],
            ['%s', '%s', '%s'],
            ['%d']
        );

        $this->db->query('COMMIT');

        if (!$updated) {
            return null;
        }

        $row->status = 'processing';

        return Job::fromRow($row);
    }

    public function markCompleted(Job $job): void
    {
        $this->db->update(
            $this->queueTable,
            [
                'status'       => 'completed',
                'completed_at' => current_time('mysql'),
                'updated_at'   => current_time('mysql'),
            ],
            ['id' => $job->getId()],
            ['%s', '%s', '%s'],
            ['%d']
        );
    }

    public function markFailed(Job $job, string $errorMessage): void
    {
        $this->db->update(
            $this->queueTable,
            [
                'status'        => 'failed',
                'attempts'      => $job->getAttempts(),
                'error_message' => $errorMessage,
                'updated_at'    => current_time('mysql'),
            ],
            ['id' => $job->getId()],
            ['%s', '%d', '%s', '%s'],
            ['%d']
        );

        $this->updateArticleStatus($job->getArticleId(), 'error', $errorMessage);
    }

    public function release(Job $job, int $delaySeconds): void
    {
        $this->db->update(
            $this->queueTable,
            [
                'status'       => 'pending',
                'attempts'     => $job->getAttempts(),
                'available_at' => gmdate('Y-m-d H:i:s', time() + max(0, $delaySeconds)),
                'updated_at'   => current_time('mysql'),
            ],
            ['id' => $job->getId()],
            ['%s', '%d', '%s', '%s'],
            ['%d']
        );
    }

    public function updateArticleStatus(int $articleId, string $status, ?string $errorMessage = null): void
    {
        $data = [
            'status'     => $status,
            'updated_at' => current_time('mysql'),
        ];
        $formats = ['%s', '%s'];

        if ($errorMessage !== null) {
            $data['error_message'] = $errorMessage;
            $formats[] = '%s';
        }

        $this->db->update($this->articlesTable, $data, ['id' => $articleId], $formats, ['%d']);
    }
}

<?php

declare(strict_types=1);

namespace RoboJackSparrow\Tests\Unit\Queue;

use RoboJackSparrow\Core\Logger;
use RoboJackSparrow\Queue\Job;
use RoboJackSparrow\Queue\QueueManager;
use RoboJackSparrow\Queue\Worker;
use RoboJackSparrow\Tests\TestCase;
use RuntimeException;

final class QueueManagerWorkerTest extends TestCase
{
    public function testEnqueueThenPopNextAvailableRoundTrips(): void
    {
        $this->wpdb->articles[1] = ['id' => 1, 'status' => 'pending', 'priority' => 5, 'created_at' => '2026-01-01 00:00:00'];

        $queue = new QueueManager(new Logger());
        $id = $queue->enqueue(1, 'generate_content', ['foo' => 'bar']);

        $this->assertSame(1, $id);
        $this->assertSame('generate_content', $this->wpdb->queueRows[1]['job_type']);

        $job = $queue->popNextAvailable();

        $this->assertInstanceOf(Job::class, $job);
        $this->assertSame('generate_content', $job->getType());
        $this->assertSame(['foo' => 'bar'], $job->getPayload());
    }

    public function testPopNextAvailableReturnsNullWhenQueueIsEmpty(): void
    {
        $queue = new QueueManager(new Logger());

        $this->assertNull($queue->popNextAvailable());
    }

    public function testMarkFailedAlsoUpdatesTheParentArticleStatus(): void
    {
        $this->wpdb->articles[1] = ['id' => 1, 'status' => 'generating_content'];
        $this->wpdb->queueRows[1] = ['id' => 1, 'article_id' => 1, 'job_type' => 'generate_content', 'job_payload' => '{}', 'status' => 'processing', 'attempts' => 3, 'max_attempts' => 3, 'created_at' => 'x'];

        $queue = new QueueManager(new Logger());
        $job = Job::fromRow((object) $this->wpdb->queueRows[1]);

        $queue->markFailed($job, 'boom');

        $this->assertSame('failed', $this->wpdb->queueRows[1]['status']);
        $this->assertSame('error', $this->wpdb->articles[1]['status']);
        $this->assertSame('boom', $this->wpdb->articles[1]['error_message']);
    }

    public function testWorkerDispatchesToARegisteredHandlerAndMarksCompleted(): void
    {
        $this->wpdb->articles[1] = ['id' => 1, 'status' => 'pending', 'priority' => 5, 'created_at' => '2026-01-01 00:00:00'];
        $this->wpdb->queueRows[1] = ['id' => 1, 'article_id' => 1, 'job_type' => 'generate_content', 'job_payload' => '{}', 'status' => 'pending', 'attempts' => 0, 'max_attempts' => 3, 'available_at' => '2020-01-01 00:00:00', 'created_at' => '2026-01-01 00:00:00'];

        \add_filter('rjs_dispatch_job_generate_content', fn ($handled, $job) => true, 10, 2);

        $queue = new QueueManager(new Logger());
        $worker = new Worker($queue, new Logger(), batchSize: 1);
        $worker->processNextBatch();

        $this->assertSame('completed', $this->wpdb->queueRows[1]['status']);
    }

    public function testWorkerReleasesWithBackoffOnFirstFailureAndMarksFailedAfterMaxAttempts(): void
    {
        $this->wpdb->articles[1] = ['id' => 1, 'status' => 'pending', 'priority' => 5, 'created_at' => '2026-01-01 00:00:00'];
        $this->wpdb->queueRows[1] = ['id' => 1, 'article_id' => 1, 'job_type' => 'unregistered_type', 'job_payload' => '{}', 'status' => 'pending', 'attempts' => 0, 'max_attempts' => 2, 'available_at' => '2020-01-01 00:00:00', 'created_at' => '2026-01-01 00:00:00'];

        $queue = new QueueManager(new Logger());
        $worker = new Worker($queue, new Logger(), batchSize: 1);

        // No handler registered for 'unregistered_type' -> Worker treats it as a failure.
        $worker->processNextBatch();

        $this->assertSame('pending', $this->wpdb->queueRows[1]['status'], 'first failure should release for retry, not fail outright');
        $this->assertSame(1, $this->wpdb->queueRows[1]['attempts']);

        // Make it immediately available again and process once more (2nd/last attempt).
        $this->wpdb->queueRows[1]['available_at'] = '2020-01-01 00:00:00';
        $worker->processNextBatch();

        $this->assertSame('failed', $this->wpdb->queueRows[1]['status'], 'max_attempts=2 reached -> job must be marked failed');
        $this->assertSame(2, $this->wpdb->queueRows[1]['attempts']);
        $this->assertSame('error', $this->wpdb->articles[1]['status']);
    }

    public function testPopNextAvailableComparesAvailableAtInUtcRegardlessOfSiteTimezone(): void
    {
        // Simulates a WordPress site configured for e.g. America/Sao_Paulo
        // (UTC-3). available_at is always written in UTC (gmdate()); if the
        // comparison side used the site's local time instead, a job
        // enqueued with no delay would look 3 hours in the future and
        // never be picked up.
        $GLOBALS['__rjs_site_utc_offset_hours'] = -3;

        $this->wpdb->articles[1] = ['id' => 1, 'status' => 'pending', 'priority' => 5, 'created_at' => '2026-01-01 00:00:00'];

        $queue = new QueueManager(new Logger());
        $queue->enqueue(1, 'generate_content');

        $job = $queue->popNextAvailable();

        $this->assertNotNull($job, 'a job enqueued with no delay must be immediately available regardless of the site timezone offset');
    }

    public function testBatchSizeBoundsAttemptedJobsNotJustSuccessfulOnes(): void
    {
        // Three jobs, all with a type nothing handles -> all fail fast.
        $this->wpdb->articles = [
            1 => ['id' => 1, 'status' => 'pending', 'priority' => 5, 'created_at' => '2026-01-01 00:00:00'],
            2 => ['id' => 2, 'status' => 'pending', 'priority' => 5, 'created_at' => '2026-01-01 00:00:01'],
            3 => ['id' => 3, 'status' => 'pending', 'priority' => 5, 'created_at' => '2026-01-01 00:00:02'],
        ];
        $this->wpdb->queueRows = [
            1 => ['id' => 1, 'article_id' => 1, 'job_type' => 'unregistered_type', 'job_payload' => '{}', 'status' => 'pending', 'attempts' => 0, 'max_attempts' => 5, 'available_at' => '2020-01-01 00:00:00', 'created_at' => '2026-01-01 00:00:00'],
            2 => ['id' => 2, 'article_id' => 2, 'job_type' => 'unregistered_type', 'job_payload' => '{}', 'status' => 'pending', 'attempts' => 0, 'max_attempts' => 5, 'available_at' => '2020-01-01 00:00:00', 'created_at' => '2026-01-01 00:00:01'],
            3 => ['id' => 3, 'article_id' => 3, 'job_type' => 'unregistered_type', 'job_payload' => '{}', 'status' => 'pending', 'attempts' => 0, 'max_attempts' => 5, 'available_at' => '2020-01-01 00:00:00', 'created_at' => '2026-01-01 00:00:02'],
        ];

        $queue = new QueueManager(new Logger());
        $worker = new Worker($queue, new Logger(), batchSize: 2);
        $worker->processNextBatch();

        // All 3 end up back in 'pending' status either way (released for
        // retry vs. never popped) - what must be bounded is how many were
        // actually *attempted* (their attempts counter incremented).
        $touched = array_filter($this->wpdb->queueRows, static fn ($r) => $r['attempts'] > 0);
        $this->assertCount(2, $touched, 'batchSize=2 must bound total attempts even when every job fails fast, leaving the 3rd untouched');
        $this->assertSame(0, $this->wpdb->queueRows[3]['attempts'], 'the 3rd job must never have been popped at all');
    }

    public function testHandlerReturningFalseIsTreatedAsFailureNotSuccess(): void
    {
        $this->wpdb->articles[1] = ['id' => 1, 'status' => 'pending', 'priority' => 5, 'created_at' => '2026-01-01 00:00:00'];
        $this->wpdb->queueRows[1] = ['id' => 1, 'article_id' => 1, 'job_type' => 'generate_content', 'job_payload' => '{}', 'status' => 'pending', 'attempts' => 0, 'max_attempts' => 3, 'available_at' => '2020-01-01 00:00:00', 'created_at' => '2026-01-01 00:00:00'];

        // A registered handler that determined the job failed and returned
        // false - must not be silently treated as success.
        \add_filter('rjs_dispatch_job_generate_content', fn ($handled, $job) => false, 10, 2);

        $queue = new QueueManager(new Logger());
        $worker = new Worker($queue, new Logger(), batchSize: 1);
        $worker->processNextBatch();

        $this->assertSame('pending', $this->wpdb->queueRows[1]['status'], 'a handler returning false must be retried, not marked completed');
    }

    public function testVoidHandlerIsTreatedAsFailureNotSuccess(): void
    {
        $this->wpdb->articles[1] = ['id' => 1, 'status' => 'pending', 'priority' => 5, 'created_at' => '2026-01-01 00:00:00'];
        $this->wpdb->queueRows[1] = ['id' => 1, 'article_id' => 1, 'job_type' => 'generate_content', 'job_payload' => '{}', 'status' => 'pending', 'attempts' => 0, 'max_attempts' => 3, 'available_at' => '2020-01-01 00:00:00', 'created_at' => '2026-01-01 00:00:00'];

        // A handler registered without a return statement (implicit null) -
        // must not be confused with "no handler registered at all" nor
        // silently accepted as success.
        \add_filter('rjs_dispatch_job_generate_content', function ($handled, $job) {
            // intentionally no return
        }, 10, 2);

        $queue = new QueueManager(new Logger());
        $worker = new Worker($queue, new Logger(), batchSize: 1);
        $worker->processNextBatch();

        $this->assertSame('pending', $this->wpdb->queueRows[1]['status'], 'a void handler must be retried, not marked completed');
    }

    public function testReclaimStaleProcessingJobsResetsJobsStuckPastTheThreshold(): void
    {
        $stale = gmdate('Y-m-d H:i:s', time() - 3600); // 1h ago: past the 15-minute threshold
        $recent = gmdate('Y-m-d H:i:s', time() - 60); // 1 minute ago: still within the threshold

        $this->wpdb->queueRows = [
            1 => ['id' => 1, 'article_id' => 1, 'job_type' => 'x', 'job_payload' => '{}', 'status' => 'processing', 'attempts' => 0, 'max_attempts' => 3, 'available_at' => 'x', 'started_at' => $stale, 'created_at' => 'x'],
            2 => ['id' => 2, 'article_id' => 2, 'job_type' => 'x', 'job_payload' => '{}', 'status' => 'processing', 'attempts' => 0, 'max_attempts' => 3, 'available_at' => 'x', 'started_at' => $recent, 'created_at' => 'x'],
        ];

        $queue = new QueueManager(new Logger());
        $reclaimed = $queue->reclaimStaleProcessingJobs();

        $this->assertSame(1, $reclaimed);
        $this->assertSame('pending', $this->wpdb->queueRows[1]['status'], 'a job stuck in processing past the threshold must be reclaimed');
        $this->assertSame('processing', $this->wpdb->queueRows[2]['status'], 'a recently-started job must not be reclaimed yet');
    }
}

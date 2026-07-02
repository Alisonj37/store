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
}

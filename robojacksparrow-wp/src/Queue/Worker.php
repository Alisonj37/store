<?php

declare(strict_types=1);

namespace RoboJackSparrow\Queue;

use RoboJackSparrow\Core\Logger;
use RuntimeException;
use Throwable;

class Worker
{
    /** Exponential backoff in seconds: 1min, 5min, 15min. */
    private const BACKOFF_SECONDS = [60, 300, 900];

    public function __construct(
        private QueueManager $queue,
        private Logger $logger,
        private int $batchSize = 5,
        private int $memoryLimitBytes = 256 * 1024 * 1024,
        private int $timeLimitSeconds = 300
    ) {
    }

    /**
     * Processes the next batch of jobs. Triggered every minute by WP-Cron
     * (see CronManager). Safe to run on shared hosting: bounded by batch
     * size, wall-clock time and memory usage.
     */
    public function processNextBatch(): void
    {
        if (function_exists('set_time_limit')) {
            @set_time_limit($this->timeLimitSeconds);
        }
        ignore_user_abort(true);

        // Reclaim jobs stuck in 'processing' by a worker run that never
        // finished (host OOM-killed the process, hard execution-time kill),
        // so they aren't lost forever.
        $this->queue->reclaimStaleProcessingJobs();

        $startTime = time();
        $processed = 0;

        while ($processed < $this->batchSize) {
            if ((time() - $startTime) > $this->timeLimitSeconds) {
                $this->logger->info('Worker time limit reached', ['limit' => $this->timeLimitSeconds]);
                break;
            }

            if (memory_get_usage(true) > $this->memoryLimitBytes) {
                $this->logger->warning('Worker memory limit approached');
                break;
            }

            $job = $this->queue->popNextAvailable();

            if ($job === null) {
                break;
            }

            try {
                $this->dispatch($job);
                $this->queue->markCompleted($job);
            } catch (Throwable $e) {
                $this->handleFailure($job, $e);
            }

            // Counts attempted jobs, not just successful ones: batchSize
            // exists to bound work per cron tick even when jobs fail fast
            // (e.g. a misconfigured provider), not just to bound successes.
            $processed++;
        }

        $this->logger->info('Worker batch completed', ['processed' => $processed]);
    }

    /**
     * Dispatches a job to its handler via a per-type filter, so concrete job
     * handlers (JobRegistry, or third-party addons) can register themselves
     * without Worker knowing about them upfront:
     *
     *   add_filter('rjs_dispatch_job_generate_content', function ($handled, Job $job) {
     *       // ... handle it ...
     *       return true;
     *   }, 10, 2);
     *
     * has_filter() distinguishes "nothing registered for this job type" from
     * "a handler ran"; a handler must explicitly return `true` to count as
     * successful, so a handler that returns false/null/void (e.g. forgot a
     * return statement) is treated as a failure rather than silently as
     * success, and is retried instead of being marked completed.
     */
    private function dispatch(Job $job): void
    {
        $hook = 'rjs_dispatch_job_' . $job->getType();

        if (!has_filter($hook)) {
            throw new RuntimeException(sprintf('No handler registered for job type "%s"', $job->getType()));
        }

        $handled = apply_filters($hook, null, $job);

        if ($handled !== true) {
            throw new RuntimeException(sprintf(
                'Handler for job type "%s" did not report success (returned %s)',
                $job->getType(),
                var_export($handled, true)
            ));
        }
    }

    private function handleFailure(Job $job, Throwable $e): void
    {
        $attempts = $job->getAttempts() + 1;

        $this->logger->error('Job failed', [
            'job_id'   => $job->getId(),
            'job_type' => $job->getType(),
            'attempts' => $attempts,
            'error'    => $e->getMessage(),
        ]);

        if ($attempts >= $job->getMaxAttempts()) {
            $this->queue->markFailed(
                new Job($job->getId(), $job->getArticleId(), $job->getType(), $job->getPayload(), $attempts, $job->getMaxAttempts(), 'failed'),
                $e->getMessage()
            );

            return;
        }

        $delay = self::BACKOFF_SECONDS[min($attempts - 1, count(self::BACKOFF_SECONDS) - 1)];

        $this->queue->release(
            new Job($job->getId(), $job->getArticleId(), $job->getType(), $job->getPayload(), $attempts, $job->getMaxAttempts(), 'pending'),
            $delay
        );
    }
}

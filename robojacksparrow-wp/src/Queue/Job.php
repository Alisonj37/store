<?php

declare(strict_types=1);

namespace RoboJackSparrow\Queue;

final class Job
{
    public function __construct(
        private int $id,
        private int $articleId,
        private string $type,
        private array $payload,
        private int $attempts,
        private int $maxAttempts,
        private string $status
    ) {
    }

    public static function fromRow(object $row): self
    {
        return new self(
            id: (int) $row->id,
            articleId: (int) $row->article_id,
            type: (string) $row->job_type,
            payload: json_decode((string) $row->job_payload, true) ?: [],
            attempts: (int) $row->attempts,
            maxAttempts: (int) $row->max_attempts,
            status: (string) $row->status
        );
    }

    public function getId(): int
    {
        return $this->id;
    }

    public function getArticleId(): int
    {
        return $this->articleId;
    }

    public function getType(): string
    {
        return $this->type;
    }

    public function getPayload(): array
    {
        return $this->payload;
    }

    public function getAttempts(): int
    {
        return $this->attempts;
    }

    public function getMaxAttempts(): int
    {
        return $this->maxAttempts;
    }

    public function getStatus(): string
    {
        return $this->status;
    }
}

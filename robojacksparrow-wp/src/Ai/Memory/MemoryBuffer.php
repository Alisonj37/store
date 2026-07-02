<?php

declare(strict_types=1);

namespace RoboJackSparrow\Ai\Memory;

/**
 * Sliding-window conversational memory used by ContentEngine so each
 * section-generation call has the context of previously generated
 * sections, without unbounded growth in message count or tokens.
 */
class MemoryBuffer
{
    public function __construct(
        private MemoryStore $store,
        private int $maxWindowSize = 10,
        private int $maxTokens = 4000
    ) {
    }

    public function push(string $contextKey, string $role, string $content, int $tokens = 0): void
    {
        $this->store->insert($contextKey, $role, $content, $tokens);
        $this->enforceWindowLimit($contextKey);
    }

    /**
     * @return array<int, array{role: string, content: string}>
     */
    public function getContext(string $contextKey): array
    {
        $recent = $this->store->getRecent($contextKey, $this->maxWindowSize);

        return array_map(
            static fn (array $entry) => ['role' => $entry['role'], 'content' => $entry['content']],
            $recent
        );
    }

    public function clear(string $contextKey): void
    {
        $this->store->clear($contextKey);
    }

    private function enforceWindowLimit(string $contextKey): void
    {
        $messages = $this->store->getAll($contextKey);

        while (count($messages) > $this->maxWindowSize) {
            $this->store->deleteOldest($contextKey);
            array_shift($messages);
        }

        $totalTokens = array_sum(array_column($messages, 'tokens'));
        while ($totalTokens > $this->maxTokens && count($messages) > 1) {
            $this->store->deleteOldest($contextKey);
            $removed = array_shift($messages);
            $totalTokens -= (int) ($removed['tokens'] ?? 0);
        }
    }
}

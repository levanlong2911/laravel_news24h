<?php

declare(strict_types=1);

namespace App\Services\AI\FilmOS\ExecutionGraph;

/**
 * In-memory checkpoint store cho tests.
 * Serialize/unserialize để đảm bảo snapshot semantics thực sự
 * (không phải object reference — giống behavior của CacheCheckpointStore).
 */
final class InMemoryCheckpointStore implements CheckpointStore
{
    /** @var array<string, string> executionId → serialized graph */
    private array $store = [];

    public function save(string $executionId, ExecutionGraph $graph): void
    {
        $this->store[$executionId] = serialize($graph);
    }

    public function load(string $executionId): ?ExecutionGraph
    {
        if (!isset($this->store[$executionId])) {
            return null;
        }
        return unserialize($this->store[$executionId]);
    }

    public function clear(string $executionId): void
    {
        unset($this->store[$executionId]);
    }

    /** Số lần save() được gọi — hữu ích trong tests để đếm checkpoint calls. */
    public function saveCount(string $executionId): int
    {
        return isset($this->store[$executionId]) ? 1 : 0;
    }
}

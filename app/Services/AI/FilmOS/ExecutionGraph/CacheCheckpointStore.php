<?php

declare(strict_types=1);

namespace App\Services\AI\FilmOS\ExecutionGraph;

use Illuminate\Support\Facades\Cache;

/**
 * Laravel Cache–backed checkpoint store cho production.
 * TTL mặc định 24h — đủ cho một production session.
 */
final class CacheCheckpointStore implements CheckpointStore
{
    public function __construct(
        private readonly int $ttlSeconds = 86400, // 24h
    ) {}

    public function save(string $executionId, ExecutionGraph $graph): void
    {
        Cache::put(
            $this->key($executionId),
            serialize($graph),
            now()->addSeconds($this->ttlSeconds),
        );
    }

    public function load(string $executionId): ?ExecutionGraph
    {
        $data = Cache::get($this->key($executionId));
        return $data !== null ? unserialize($data) : null;
    }

    public function clear(string $executionId): void
    {
        Cache::forget($this->key($executionId));
    }

    private function key(string $executionId): string
    {
        return "filmos_exec_checkpoint_{$executionId}";
    }
}

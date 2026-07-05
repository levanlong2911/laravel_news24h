<?php

namespace App\Services\AI\AFOS\Passes\Cache;

use App\Services\AI\AFOS\Passes\Pipeline\PipelineState;
use App\Services\AI\AFOS\Passes\Pipeline\StageFingerprint;

/**
 * InMemoryCompilerCache — process-lifetime cache using a PHP array.
 *
 * Zero I/O, zero dependencies. Suitable for:
 *   - Benchmark runs (reuse stage output across N similar shots)
 *   - Tests (verify cache hit/miss behaviour)
 *   - Single-process repeated compilation (e.g. batch processing)
 *
 * Cache hit flow:
 *   1. Caller computes StageFingerprint::of($stage, $state)
 *   2. get($fp, $currentState) → restored PipelineState with live $bag
 *   3. Skip stage.run() — zero compute cost
 *
 * Cache miss flow:
 *   1. Stage runs normally
 *   2. put($fp, $newState) — store result
 *
 * The stored state uses its original DiagnosticBag (from the run that
 * populated the cache). On restoration, withBag() transplants the live bag
 * so diagnostics from this run accumulate correctly.
 */
final class InMemoryCompilerCache implements CompilerCache
{
    /** @var array<string, PipelineState> hash → post-stage state */
    private array $store  = [];
    private int   $hits   = 0;
    private int   $misses = 0;

    public function get(StageFingerprint $fp, PipelineState $currentState): ?PipelineState
    {
        if (!isset($this->store[$fp->hash])) {
            $this->misses++;
            return null;
        }

        $this->hits++;

        // Transplant the live bag onto the cached state so diagnostics accumulate
        return $this->store[$fp->hash]->withBag($currentState->bag);
    }

    public function put(StageFingerprint $fp, PipelineState $afterState): void
    {
        $this->store[$fp->hash] = $afterState;
    }

    public function stats(): CacheStats
    {
        return new CacheStats($this->hits, $this->misses, count($this->store));
    }

    public function flush(): void
    {
        $this->store  = [];
        $this->hits   = 0;
        $this->misses = 0;
    }
}

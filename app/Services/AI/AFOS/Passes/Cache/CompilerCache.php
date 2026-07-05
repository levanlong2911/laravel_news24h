<?php

namespace App\Services\AI\AFOS\Passes\Cache;

use App\Services\AI\AFOS\Passes\Pipeline\PipelineState;
use App\Services\AI\AFOS\Passes\Pipeline\StageFingerprint;

/**
 * CompilerCache — cache store for stage output PipelineStates.
 *
 * Keyed by StageFingerprint::$hash (deterministic hash of stage version + inputs).
 * Cache hit → stage is skipped; cached state is restored with live DiagnosticBag.
 *
 * Implementations:
 *   InMemoryCompilerCache  — process-lifetime, zero I/O, for benchmarks + tests
 *   (future) RedisCompilerCache    — persistent across processes
 *   (future) DiskCompilerCache     — serialised PipelineState to JSON/msgpack
 */
interface CompilerCache
{
    /**
     * Return cached output state, or null on cache miss.
     * The returned state has $currentBag transplanted — diagnostics stay live.
     */
    public function get(StageFingerprint $fp, PipelineState $currentState): ?PipelineState;

    /** Store the post-execution state for this fingerprint. */
    public function put(StageFingerprint $fp, PipelineState $afterState): void;

    public function stats(): CacheStats;

    public function flush(): void;
}

<?php

namespace App\Services\AI\AFOS\Passes\Pipeline;

/**
 * CompilerMetrics — aggregate telemetry for one compilation run.
 *
 * Built from StageProfile[] after compileWithSnapshot() completes.
 * Available as PromptIRSnapshot::metrics() for benchmark and monitoring.
 *
 * Future:
 *   $cacheHits / $cacheMisses — populated once CacheManager is integrated.
 *   $skippedStages            — stages skipped by cache or optimizer.
 */
final class CompilerMetrics
{
    public function __construct(
        public readonly int   $totalStages,
        public readonly int   $executedStages,
        public readonly int   $cacheHits,
        public readonly int   $cacheMisses,
        public readonly int   $skippedStages,
        public readonly float $totalMs,
        public readonly float $avgStageMs,
        public readonly int   $peakMemoryBytes,
        public readonly int   $totalMemoryDelta,
        public readonly int   $totalErrors,
        public readonly int   $totalWarnings,
        public readonly int   $totalHints,
        /** @var string|null Name of the slowest stage */
        public readonly ?string $bottleneckStage   = null,
        public readonly float   $bottleneckMs       = 0.0,
        /** Estimated total wall-clock ms (sum of StageCost::estimatedMs across all stages). */
        public readonly float   $estimatedMs        = 0.0,
        /** Estimated total LLM tokens (0 for pure-CPU pipelines). */
        public readonly int     $estimatedTokens    = 0,
        /** Estimated total API cost in USD (0.0 for pure-CPU pipelines). */
        public readonly float   $estimatedCostUSD   = 0.0,
    ) {}

    /** @param StageProfile[] $profiles */
    public static function fromProfiles(
        array      $profiles,
        ?StageCost $estimated     = null,
        int        $skippedStages = 0,
    ): self {
        if (empty($profiles) && $skippedStages === 0) {
            return new self(
                totalStages: 0, executedStages: 0, cacheHits: 0, cacheMisses: 0,
                skippedStages: 0, totalMs: 0.0, avgStageMs: 0.0, peakMemoryBytes: 0,
                totalMemoryDelta: 0, totalErrors: 0, totalWarnings: 0, totalHints: 0,
                estimatedMs: $estimated?->estimatedMs ?? 0.0,
                estimatedTokens: $estimated?->estimatedTokens ?? 0,
                estimatedCostUSD: $estimated?->estimatedCostUSD ?? 0.0,
            );
        }

        $totalMs    = array_sum(array_map(fn(StageProfile $p) => $p->durationMs, $profiles));
        $executed   = count($profiles);
        $avgMs      = round($totalMs / $executed, 3);
        $peakMemory = memory_get_peak_usage();
        $memDelta   = array_sum(array_map(fn(StageProfile $p) => $p->memoryDelta, $profiles));
        $errors     = array_sum(array_map(fn(StageProfile $p) => $p->errorCount, $profiles));
        $warnings   = array_sum(array_map(fn(StageProfile $p) => $p->warningCount, $profiles));
        $hints      = array_sum(array_map(fn(StageProfile $p) => $p->hintCount, $profiles));

        // Bottleneck = slowest stage
        $slowest = array_reduce(
            $profiles,
            fn(?StageProfile $carry, StageProfile $p) => ($carry === null || $p->durationMs > $carry->durationMs) ? $p : $carry,
        );

        return new self(
            totalStages:      $executed + $skippedStages,
            executedStages:   $executed,
            cacheHits:        0,
            cacheMisses:      $executed,
            skippedStages:    $skippedStages,
            totalMs:          round($totalMs, 3),
            avgStageMs:       $avgMs,
            peakMemoryBytes:  $peakMemory,
            totalMemoryDelta: $memDelta,
            totalErrors:      $errors,
            totalWarnings:    $warnings,
            totalHints:       $hints,
            bottleneckStage:  $slowest?->stageName,
            bottleneckMs:     $slowest?->durationMs ?? 0.0,
            estimatedMs:      $estimated?->estimatedMs ?? 0.0,
            estimatedTokens:  $estimated?->estimatedTokens ?? 0,
            estimatedCostUSD: $estimated?->estimatedCostUSD ?? 0.0,
        );
    }

    public function toArray(): array
    {
        return [
            'total_stages'       => $this->totalStages,
            'executed_stages'    => $this->executedStages,
            'cache_hits'         => $this->cacheHits,
            'cache_misses'       => $this->cacheMisses,
            'skipped_stages'     => $this->skippedStages,
            'total_ms'           => $this->totalMs,
            'avg_stage_ms'       => $this->avgStageMs,
            'peak_memory_bytes'  => $this->peakMemoryBytes,
            'total_memory_delta' => $this->totalMemoryDelta,
            'total_errors'       => $this->totalErrors,
            'total_warnings'     => $this->totalWarnings,
            'total_hints'        => $this->totalHints,
            'bottleneck_stage'    => $this->bottleneckStage,
            'bottleneck_ms'       => $this->bottleneckMs,
            'estimated_ms'        => $this->estimatedMs,
            'estimated_tokens'    => $this->estimatedTokens,
            'estimated_cost_usd'  => $this->estimatedCostUSD,
        ];
    }
}

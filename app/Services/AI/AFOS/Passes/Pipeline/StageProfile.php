<?php

namespace App\Services\AI\AFOS\Passes\Pipeline;

/**
 * StageProfile — per-stage telemetry captured during one compilation run.
 *
 * Records timing, memory delta, and diagnostic counts emitted by this stage.
 * Attached to PromptIRSnapshot::$profiles. NOT in toArray() — ephemeral.
 *
 * Memory: before/after memory_get_usage(); delta = after − before.
 * Diagnostics: counts added by this stage only (delta from before → after run).
 */
final class StageProfile
{
    public readonly int $memoryDelta;

    public function __construct(
        public readonly string $stageName,
        public readonly float  $durationMs,
        public readonly bool   $succeeded    = true,
        public readonly int    $memoryBefore = 0,
        public readonly int    $memoryAfter  = 0,
        public readonly int    $errorCount   = 0,
        public readonly int    $warningCount = 0,
        public readonly int    $hintCount    = 0,
    ) {
        $this->memoryDelta = $memoryAfter - $memoryBefore;
    }

    public function memoryDeltaKb(): float
    {
        return round($this->memoryDelta / 1024, 1);
    }

    public function toArray(): array
    {
        return [
            'stage'        => $this->stageName,
            'ms'           => $this->durationMs,
            'succeeded'    => $this->succeeded,
            'memory_delta' => $this->memoryDelta,
            'errors'       => $this->errorCount,
            'warnings'     => $this->warningCount,
            'hints'        => $this->hintCount,
        ];
    }
}

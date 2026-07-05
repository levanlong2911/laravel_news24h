<?php

namespace App\Services\AI\AFOS\Cost;

use App\Services\AI\AFOS\Backend\BackendCapability;

/**
 * CostModel — computes shot-level utility for Experience Engine.
 *
 * utility(quality, latency, cost) = quality - λ_latency × latency - λ_cost × cost
 *
 * λ values are tunable by Experience Engine (via config or DB) without code changes.
 * Default λ values favour quality heavily — adjust after baseline data is collected.
 *
 * Phase A: used for logging only. Phase B: used by Generative Planning to rank
 * N candidate plans by utility, not just quality.
 */
final class CostModel
{
    public function __construct(
        private readonly float $lambdaLatency = 0.02,  // penalty per second of latency
        private readonly float $lambdaCost    = 0.15,  // penalty per USD spent
    ) {}

    public static function fromConfig(): self
    {
        return new self(
            lambdaLatency: (float) config('afos.cost_model.lambda_latency', 0.02),
            lambdaCost:    (float) config('afos.cost_model.lambda_cost',    0.15),
        );
    }

    /**
     * Compute utility score for a single render.
     *
     * @param float $quality     0.0–1.0 (from QA pipeline: IR Fidelity × perceptual score)
     * @param float $latencySec  actual wall-clock render time in seconds
     * @param float $costUsd     actual billing cost in USD
     */
    public function utility(float $quality, float $latencySec, float $costUsd): float
    {
        return max(0.0, round(
            $quality - ($this->lambdaLatency * $latencySec) - ($this->lambdaCost * $costUsd),
            4
        ));
    }

    /**
     * Estimate utility before render (planning phase).
     * Uses backend capability defaults for latency and cost.
     */
    public function estimateUtility(float $quality, BackendCapability $backend, float $durationSec): float
    {
        return $this->utility(
            quality:    $quality,
            latencySec: $backend->avgLatencySec,
            costUsd:    $backend->costPerSecondUsd * $durationSec,
        );
    }

    /**
     * Compare two render options. Returns true if option A has higher utility.
     */
    public function aBeatsB(
        float $qualityA, float $latencyA, float $costA,
        float $qualityB, float $latencyB, float $costB,
    ): bool {
        return $this->utility($qualityA, $latencyA, $costA)
             > $this->utility($qualityB, $latencyB, $costB);
    }
}

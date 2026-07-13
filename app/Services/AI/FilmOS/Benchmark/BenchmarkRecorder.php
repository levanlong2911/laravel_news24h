<?php

declare(strict_types=1);

namespace App\Services\AI\FilmOS\Benchmark;

use App\Services\AI\FilmOS\Planning\PlanningIR;
use App\Services\AI\FilmOS\Runtime\ProviderResult;
use App\Services\AI\FilmOS\Runtime\RuntimeEvent;

/**
 * Converts a completed ProviderResult into a BenchmarkResult for persistence.
 *
 * Fills observable fields from ProviderResult (cost, latency, duration, requestId, assetUrl).
 * Leaves annotation-based fields (qualityScore, roi, score) at 0.0 — C.6 will compute them
 * from aggregated BenchmarkRepository data after human annotation or automated evaluation.
 */
final class BenchmarkRecorder
{
    public function record(
        ProviderResult $result,
        PlanningIR     $planningIr,
        string         $plannerName = 'default',
    ): BenchmarkResult {
        if ($result->status !== RuntimeEvent::DOWNLOAD_COMPLETED) {
            throw new \LogicException(sprintf(
                'BenchmarkRecorder expects DOWNLOAD_COMPLETED, got %s (traceId: %s)',
                $result->status->value,
                $result->traceId,
            ));
        }

        return new BenchmarkResult(
            traceId:        $result->traceId,
            provider:       $result->provider,
            plannerName:    $plannerName,
            goalId:         $planningIr->goalId,
            score:          0.0,         // pending C.6
            roi:            0.0,         // pending C.6
            cost:           $result->cost,
            latencySeconds: $result->latency,
            qualityScore:   0.0,         // pending human annotation / C.6
            attributes:     [
                'requestId' => $result->requestId,
                'assetUrl'  => $result->assetUrl,
                'duration'  => $result->duration,
            ],
        );
    }
}

<?php

declare(strict_types=1);

namespace App\Services\AI\FilmOS\Pipeline;

use App\Services\AI\FilmOS\Benchmark\BenchmarkRecorder;
use App\Services\AI\FilmOS\Benchmark\BenchmarkRepository;
use App\Services\AI\FilmOS\Planning\PlanningIR;
use App\Services\AI\FilmOS\Runtime\RuntimeEvent;

/**
 * Renders a single shot and records benchmark data.
 *
 * Responsibility: call FilmOSShotRuntime (compile → rule → learn → render),
 * then BenchmarkRecorder, then return RenderedShot.
 * Does NOT orchestrate the render sub-steps — that belongs to FilmOSShotRuntime.
 */
final class ShotRenderService
{
    public function __construct(
        private readonly FilmOSShotRuntime   $shotRuntime,
        private readonly BenchmarkRecorder   $benchmarkRecorder,
        private readonly BenchmarkRepository $benchmarkRepository,
    ) {}

    public function render(PlanningIR $ir): RenderedShot
    {
        $result = $this->shotRuntime->run($ir);

        if ($result->status === RuntimeEvent::DOWNLOAD_COMPLETED) {
            $benchmarkResult = $this->benchmarkRecorder->record($result, $ir);
            $this->benchmarkRepository->save($benchmarkResult);
        }

        return new RenderedShot(
            shotId:    $ir->shotId,
            shotOrder: $ir->shotOrder,
            status:    $result->status,
            assetUrl:  $result->assetUrl ?: null,
            error:     $result->status !== RuntimeEvent::DOWNLOAD_COMPLETED
                           ? $result->status->value
                           : null,
        );
    }
}

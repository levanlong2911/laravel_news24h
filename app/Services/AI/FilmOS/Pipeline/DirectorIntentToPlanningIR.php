<?php

declare(strict_types=1);

namespace App\Services\AI\FilmOS\Pipeline;

use App\Services\AI\FilmOS\Intent\DirectorIntent;
use App\Services\AI\FilmOS\Planning\PlanningIR;

/**
 * Converts a legacy DirectorIntent into a FilmOS PlanningIR.
 *
 * Rules (must never be violated):
 *   - Map semantic fields ONLY: no prompt generation, no config reads,
 *     no provider calls, no benchmark writes.
 *   - goalId uses the 'legacy:' namespace so benchmark knows these
 *     records come from the migration path, not from native FilmOS goals.
 *   - Camera semantic is delegated to LegacyCameraMapper.
 *
 * Delete in C.8 once the upstream planning pipeline produces PlanningIR natively.
 */
final class DirectorIntentToPlanningIR
{
    public function __construct(
        private readonly LegacyCameraMapper $cameraMapper,
        private readonly DescriptionBuilder $descriptionBuilder,
    ) {}

    /** @param array<string, DirectorIntent> $intents */
    public function convertBatch(array $intents, string $productionId): array
    {
        $planningIRs = [];
        $ordinal     = 0;
        foreach ($intents as $shotId => $intent) {
            $planningIRs[$shotId] = $this->convert($intent, $productionId, $ordinal);
            $ordinal++;
        }
        return $planningIRs;
    }

    public function convert(DirectorIntent $intent, string $productionId, int $shotOrder): PlanningIR
    {
        return new PlanningIR(
            traceId:     $productionId . '_' . $intent->shotId,
            version:     1,
            shotId:      $intent->shotId,
            shotOrder:   $shotOrder,
            goalId:      'legacy:' . $intent->shotId,
            renderHints: [
                'description'    => $this->descriptionBuilder->build($intent),
                'camera'         => $this->cameraMapper->map($intent->execution->styleRule),
                'visualStrategy' => $intent->execution->visualStrategy->value,
                'aspectRatio'    => '9:16',
                'negativePrompt' => 'text overlay, logo, watermark, blurry, low quality, distorted',
            ],
            constraints: ['duration' => 5],
            attributes:  [
                'cinematicFunction' => $intent->meaning->function->value,
                'decisionDagId'     => $intent->decisionDagId,
                'productionId'      => $productionId,
            ],
        );
    }
}

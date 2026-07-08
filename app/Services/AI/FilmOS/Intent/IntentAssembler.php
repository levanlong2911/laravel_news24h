<?php

declare(strict_types=1);

namespace App\Services\AI\FilmOS\Intent;

use App\Services\AI\FilmOS\Kernel\ShotPriority;
use App\Services\AI\FilmOS\Meaning\MeaningGraph;
use App\Services\AI\FilmOS\Planning\PlannedShot;
use App\Services\AI\FilmOS\Planning\VisualStrategy;

/**
 * Maps a PlannedShot + MeaningGraph → DirectorIntent for Layer 4.
 */
final class IntentAssembler
{
    public function assemble(
        string       $productionId,
        string       $dagId,
        PlannedShot  $shot,
        MeaningGraph $meaning,
        array        $facts,
    ): DirectorIntent {
        $shotId    = sprintf('shot_%03d_%s', $shot->position, $shot->subGoalId);
        $execution = $shot->execution;

        $visualStrategy = $execution['visualStrategy'] ?? VisualStrategy::OBSERVATIONAL;
        $beat           = $this->inferBeat($shot->subGoalId);
        $priority       = $this->inferPriority($shot->subGoalId);

        return new DirectorIntent(
            productionId: $productionId,
            shotId:       $shotId,
            decisionDagId: $dagId,
            meaning: new MeaningContext(
                graph:              $meaning,
                function:           $meaning->cinematicFunction,
                tensionLevel:       $meaning->tensionLevel,
                meaningConfidence:  $meaning->confidence,
            ),
            execution: new ExecutionContext(
                mustShow:         $this->visualHints($shot->subGoalId, 'show'),
                mustAvoid:        $this->visualHints($shot->subGoalId, 'avoid'),
                beat:             $beat,
                beatFactIds:      $this->factIdsForShot($shot, $facts),
                visualStrategy:   $visualStrategy,
                styleRule:        [
                    'lens'      => $execution['lens']      ?? 50,
                    'stability' => $execution['stability'] ?? 'LOCKED',
                    'movement'  => $execution['movement']  ?? 'STATIC',
                    'dof'       => $execution['dof']       ?? 'MEDIUM',
                ],
                softConstraints:  [],
                sourceConfidence: $meaning->confidence,
            ),
            evaluation: new EvaluationContext(
                priority:             $priority,
                acceptanceThreshold:  0.75,
                requiresFactVeto:     true,
                requiredFactIds:      $this->factIdsForShot($shot, $facts),
            ),
        );
    }

    private function inferBeat(string $subGoalId): NarrativeBeat
    {
        return match ($subGoalId) {
            'hotel_exterior'    => NarrativeBeat::CONTEXT,
            'cockroach_closeup' => NarrativeBeat::EVIDENCE,
            'health_notice'     => NarrativeBeat::RESPONSE,
            'travel_advisory'   => NarrativeBeat::ADVISORY,
            default             => NarrativeBeat::CONTEXT,
        };
    }

    private function inferPriority(string $subGoalId): ShotPriority
    {
        return match ($subGoalId) {
            'cockroach_closeup', 'hotel_exterior' => ShotPriority::CRITICAL,
            'health_notice'                       => ShotPriority::IMPORTANT,
            default                               => ShotPriority::FILLER,
        };
    }

    private function visualHints(string $subGoalId, string $type): array
    {
        $hints = [
            'hotel_exterior'    => ['show' => ['hotel exterior', 'Bali architecture'], 'avoid' => ['human_face']],
            'cockroach_closeup' => ['show' => ['cockroach', 'bedsheet'],              'avoid' => ['human_face']],
            'health_notice'     => ['show' => ['health document', 'official notice'], 'avoid' => []],
            'travel_advisory'   => ['show' => ['advisory text overlay'],               'avoid' => []],
        ];

        return $hints[$subGoalId][$type] ?? [];
    }

    private function factIdsForShot(PlannedShot $shot, array $facts): array
    {
        $map = [
            'hotel_exterior'    => ['F4'],
            'cockroach_closeup' => ['F1'],
            'health_notice'     => ['F2'],
            'travel_advisory'   => ['F3'],
        ];

        return $map[$shot->subGoalId] ?? ['F1'];
    }
}

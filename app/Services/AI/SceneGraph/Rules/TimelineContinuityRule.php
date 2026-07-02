<?php

namespace App\Services\AI\SceneGraph\Rules;

use App\Services\AI\SceneGraph\ShotSceneGraph;

/**
 * Validates that timeline phases are continuous (no gaps, no overlaps).
 *
 * Floating-point phase boundaries may drift by up to TOLERANCE seconds —
 * anything beyond that is a planner bug that should surface as an error.
 */
final class TimelineContinuityRule implements SceneRule
{
    private const TOLERANCE = 0.01;

    public function validate(ShotSceneGraph $graph): array
    {
        $phases = $graph->timeline->phases;

        if (empty($phases)) {
            return [[
                'field'    => 'timeline.phases',
                'expected' => 'at least one phase',
                'actual'   => '[]',
            ]];
        }

        $errors = [];
        $count  = count($phases);

        for ($i = 0; $i < $count - 1; $i++) {
            $gap = abs($phases[$i + 1]->start - $phases[$i]->end);
            if ($gap > self::TOLERANCE) {
                $errors[] = [
                    'field'    => "timeline.phases[{$i}→" . ($i + 1) . ']',
                    'expected' => 'continuous (gap < ' . self::TOLERANCE . 's)',
                    'actual'   => "gap {$gap}s: phase {$i} ends at {$phases[$i]->end}, phase " . ($i + 1) . " starts at {$phases[$i + 1]->start}",
                ];
            }
        }

        return $errors;
    }

    public function name(): string
    {
        return 'timeline_continuity';
    }
}

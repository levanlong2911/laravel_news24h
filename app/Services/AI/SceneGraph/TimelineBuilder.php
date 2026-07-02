<?php

namespace App\Services\AI\SceneGraph;

/**
 * Assigns start_ms, end_ms, and sequence_id to every shot in the scene array.
 * Pure function — no I/O, no AI cost.
 *
 * sequence_id format: S{02d}_SH{02d}
 * Example: scene 2, shot 4 → "S02_SH04"
 * Python uses this for human-readable log references.
 *
 * start_ms/end_ms: cumulative milliseconds from video start.
 * total_duration_ms = end_ms of the last shot.
 */
final class TimelineBuilder
{
    /**
     * @param  array[] $scenes  Raw scene arrays (shots not yet annotated)
     * @return array[] $scenes  Same structure with start_ms, end_ms, sequence_id on each shot
     */
    public static function annotate(array $scenes): array
    {
        $cumulativeMs = 0;

        foreach ($scenes as $si => $scene) {
            $sceneNum = $si + 1;

            foreach ($scene['shots'] as $shi => $shot) {
                $shotNum  = $shi + 1;
                $durMs    = (int) round(($shot['dur'] ?? 0.0) * 1000);

                $scenes[$si]['shots'][$shi]['start_ms']    = $cumulativeMs;
                $scenes[$si]['shots'][$shi]['end_ms']      = $cumulativeMs + max(1, $durMs);
                $scenes[$si]['shots'][$shi]['sequence_id'] = sprintf('S%02d_SH%02d', $sceneNum, $shotNum);

                $cumulativeMs += max(1, $durMs);
            }
        }

        return $scenes;
    }

    /** Returns total_duration_ms from the last shot end_ms across all scenes. */
    public static function totalDurationMs(array $annotatedScenes): int
    {
        $last = 0;
        foreach ($annotatedScenes as $scene) {
            foreach ($scene['shots'] as $shot) {
                $last = max($last, $shot['end_ms'] ?? 0);
            }
        }
        return $last;
    }
}

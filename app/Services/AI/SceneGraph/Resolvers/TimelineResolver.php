<?php

namespace App\Services\AI\SceneGraph\Resolvers;

use App\Services\AI\SceneGraph\Enums\Emotion;
use App\Services\AI\SceneGraph\Nodes\EmotionPoint;
use App\Services\AI\SceneGraph\Nodes\TimelineNode;

/**
 * Resolves the enriched timeline into a typed TimelineNode with emotion curve.
 *
 * The emotion curve is generated from the phase structure:
 *   Phase 0    → low intensity (setup)
 *   Phase ~70% → peak intensity (climax of action)
 *   Last phase → medium intensity (resolution)
 *
 * Sprint 6 (Emotional Engine) will replace this with a proper Bezier curve
 * fitted to action beats and viewer takeaway targets.
 */
final class TimelineResolver
{
    public static function resolve(TimelineNode $timeline, Emotion $emotion): TimelineNode
    {
        $emotionCurve = self::buildEmotionCurve($timeline, $emotion);
        return new TimelineNode($timeline->phases, $emotionCurve);
    }

    /** @return EmotionPoint[] */
    private static function buildEmotionCurve(TimelineNode $timeline, Emotion $emotion): array
    {
        $total = $timeline->totalDuration();
        if ($total <= 0.0) {
            return [];
        }

        $peakPhaseIdx = max(0, (int) round(count($timeline->phases) * 0.70) - 1);
        $peakTime     = isset($timeline->phases[$peakPhaseIdx])
            ? $timeline->phases[$peakPhaseIdx]->start / $total
            : 0.7;

        $setup   = $emotion->storyPhase()->value === 'setup'   ? 0.5 : 0.2;
        $climax  = $emotion->storyPhase()->value === 'climax'  ? 1.0 : 0.75;
        $resolve = $emotion->storyPhase()->value === 'resolve' ? 0.3 : 0.4;

        return [
            new EmotionPoint(time: 0.0,       emotion: $emotion, intensity: $setup),
            new EmotionPoint(time: $peakTime,  emotion: $emotion, intensity: $climax),
            new EmotionPoint(time: 1.0,        emotion: $emotion, intensity: $resolve),
        ];
    }
}

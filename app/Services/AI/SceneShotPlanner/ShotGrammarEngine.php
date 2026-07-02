<?php

namespace App\Services\AI\SceneShotPlanner;

use App\DTOs\ShotDTO;
use App\DTOs\VisualMomentDTO;
use App\Services\AI\PromptCompiler\Libraries\MotionRulesLibrary;
use App\Services\AI\PromptCompiler\Libraries\ShotGrammarLibrary;

/**
 * Rule-based engine: VisualMomentDTO[] → ShotDTO[]
 *
 * No AI cost. Zero Claude calls. Pure PHP.
 *
 * Algorithm:
 *   1. Derive density from beat information_type
 *   2. Target shots = density_weight × beat_duration
 *   3. Distribute shots across moments weighted by importance
 *   4. Map ShotPurpose sequence onto (moment, shot) pairs
 *   5. Fix consecutive same-purpose violations
 *   6. Build ShotDTO per pair via DSLBuilder
 */
final class ShotGrammarEngine
{
    /**
     * @param  VisualMomentDTO[] $moments  All moments for one beat (code-assigned indexes)
     * @return ShotDTO[]
     */
    public function expand(
        string $informationType,
        string $visualPriority,
        string $beatEmotion,
        float  $beatDuration,
        array  $moments,
    ): array {
        if ($moments === []) {
            return [];
        }

        $density      = ShotGrammarLibrary::densityFor($informationType);
        $targetShots  = ShotGrammarEngine::calcTargetShots($density, $beatDuration, count($moments));
        $baseSequence = ShotGrammarLibrary::sequence($informationType);

        $shotsPerMoment = $this->distributeShots($moments, $targetShots);
        $pairs          = $this->buildPairs($moments, $baseSequence, $shotsPerMoment);
        $pairs          = $this->fixConsecutive($pairs);

        $totalShots = count($pairs);
        $dur        = $totalShots > 0 ? round($beatDuration / $totalShots, 2) : $beatDuration;

        $shots = [];
        foreach ($pairs as $order => [$purpose, $moment]) {
            $motionLevel = MotionRulesLibrary::motionLevel($informationType, $purpose);
            $dsl         = DSLBuilder::build(
                purpose:       $purpose,
                beatEmotion:   $beatEmotion,
                visualPriority:$visualPriority,
                motionLevel:   $motionLevel,
                dur:           $dur,
                visualIntent:  $moment->visualIntent,
                subject:       $moment->subject,
                action:        $moment->action,
            );
            $dsl['shot_order'] = $order + 1;
            $shots[] = ShotDTO::fromArray($dsl);
        }

        return $shots;
    }

    /** Target shots = density_weight × beat_duration, at least 1 per moment. */
    public static function calcTargetShots(string $density, float $beatDuration, int $momentCount): int
    {
        $formula = ShotGrammarLibrary::targetShots($density, $beatDuration);
        return max($momentCount, $formula);
    }

    /**
     * Distribute target_shots across moments weighted by importance.
     * Last moment absorbs rounding remainder.
     *
     * @param  VisualMomentDTO[] $moments
     * @return int[]  shots_per_moment indexed same as $moments
     */
    private function distributeShots(array $moments, int $targetShots): array
    {
        $weights = array_map(
            fn (VisualMomentDTO $m) => ShotGrammarLibrary::IMPORTANCE_WEIGHT[$m->importance] ?? 1,
            $moments,
        );
        $totalWeight = max(1, array_sum($weights));
        $count       = count($moments);

        $result    = [];
        $allocated = 0;
        foreach ($moments as $i => $moment) {
            if ($i === $count - 1) {
                $result[$i] = max(1, $targetShots - $allocated);
            } else {
                $share      = max(1, (int) round($weights[$i] / $totalWeight * $targetShots));
                $result[$i] = $share;
                $allocated += $share;
            }
        }

        return $result;
    }

    /**
     * Assign a ShotPurpose from the base sequence to each (moment × shot) pair.
     * Cycles through the base sequence if more shots are needed.
     *
     * @param  VisualMomentDTO[] $moments
     * @param  string[]          $baseSequence
     * @param  int[]             $shotsPerMoment
     * @return array{string, VisualMomentDTO}[]
     */
    private function buildPairs(array $moments, array $baseSequence, array $shotsPerMoment): array
    {
        $pairs  = [];
        $seqLen = count($baseSequence);
        $seqIdx = 0;

        foreach ($moments as $i => $moment) {
            $count = $shotsPerMoment[$i] ?? 1;
            for ($j = 0; $j < $count; $j++) {
                $pairs[] = [$baseSequence[$seqIdx % $seqLen], $moment];
                $seqIdx++;
            }
        }

        return $pairs;
    }

    /**
     * Ensure no two consecutive shots share the same ShotPurpose.
     * Fixes violations by substituting TRANSITION (or ESTABLISH as fallback).
     *
     * @param  array{string, VisualMomentDTO}[] $pairs
     * @return array{string, VisualMomentDTO}[]
     */
    private function fixConsecutive(array $pairs): array
    {
        for ($i = 1; $i < count($pairs); $i++) {
            if ($pairs[$i][0] === $pairs[$i - 1][0]) {
                $pairs[$i][0] = ($pairs[$i][0] !== ShotGrammarLibrary::TRANSITION)
                    ? ShotGrammarLibrary::TRANSITION
                    : ShotGrammarLibrary::ESTABLISH;
            }
        }
        return $pairs;
    }
}

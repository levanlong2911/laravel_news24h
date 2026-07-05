<?php

namespace App\Services\AI\ScenePlanner;

/**
 * RhythmPlanner — assigns beat-level timing variation based on content category and emotion.
 *
 * "Cinema is not about what you show — it is about WHEN you show it."
 *
 * Uniform beat durations feel mechanical. Rhythm variation creates the psychological
 * impression of inevitability: micro-fast hook → building tension → fast reveal →
 * long payoff. The brain prefers variation and assigns "intentionality" to varied rhythm.
 *
 * Patterns are collections of relative weights per beat. ScenePlanner normalises
 * these weights to the clip duration and applies them to the existing CinematicBeatPlan
 * beat array via applyRhythmTiming() — no need to re-run CinematicBeatPlanner.
 *
 * Pattern selection: category + emotion → rhythm profile.
 * Called AFTER CinematicBeatPlanner (to read category) but BEFORE CameraEnergyPlanner
 * (so that all downstream planners see the final timings).
 */
final class RhythmPlanner
{
    /**
     * Rhythm profiles for 5-second clips.
     * Format: [hook_weight, escalation_weight, reveal_weight, payoff_weight]
     * Weights are proportional; ScenePlanner normalises to clip duration.
     */
    private const PROFILES_5S = [
        //                     hook  esc   rev   pay
        'cinematic'    => [0.8, 1.2, 1.5, 1.5],  // natural pacing — default
        'action_burst' => [0.5, 1.0, 0.8, 2.7],  // staccato then long payoff hold
        'suspense'     => [0.4, 0.3, 2.5, 1.8],  // micro-tension punches → slow reveal
        'aerial'       => [1.0, 1.5, 1.5, 1.0],  // even pacing — drone naturally slower
        'viral'        => [0.4, 0.6, 0.5, 3.5],  // 3 quick punches then HOLD
        'product'      => [0.6, 1.2, 1.8, 1.4],  // deliberate build, extended reveal
    ];

    /**
     * Rhythm profiles for 10-second clips (5 beats including resolution).
     * Format: [hook, escalation, reveal, payoff, resolution]
     */
    private const PROFILES_10S = [
        //                     hook  esc   rev   pay   res
        'cinematic'    => [1.0, 2.5, 3.0, 2.5, 1.0],
        'action_burst' => [0.5, 1.5, 1.0, 5.0, 2.0],
        'suspense'     => [0.7, 0.6, 4.0, 3.0, 1.7],
        'aerial'       => [1.5, 2.0, 2.0, 2.5, 2.0],
        'viral'        => [0.5, 0.7, 0.8, 6.0, 2.0],
        'product'      => [1.0, 2.0, 3.0, 2.5, 1.5],
    ];

    private const BEAT_NAMES_4 = ['hook', 'escalation', 'reveal', 'payoff'];
    private const BEAT_NAMES_5 = ['hook', 'escalation', 'reveal', 'payoff', 'resolution'];

    /**
     * @param  string $category  Subject category from CinematicBeatPlan (aerial_vehicle, …)
     * @param  array  $dsl       Shot DSL — needs 'dur', 'emo'
     * @return array             {pattern, beats[], duration_sec}
     */
    public function plan(string $category, array $dsl): array
    {
        $duration = (float) ($dsl['dur'] ?? 5.0);
        $emoCode  = strtolower($dsl['emo'] ?? 'craft');
        $is10s    = $duration > 5.0;

        $profile   = $this->selectProfile($category, $emoCode);
        $weights   = $is10s
            ? (self::PROFILES_10S[$profile] ?? self::PROFILES_10S['cinematic'])
            : (self::PROFILES_5S[$profile]  ?? self::PROFILES_5S['cinematic']);

        $beatNames = $is10s ? self::BEAT_NAMES_5 : self::BEAT_NAMES_4;
        $total     = array_sum($weights);
        $beats     = [];
        $cursor    = 0.0;

        foreach ($beatNames as $i => $beatName) {
            $start    = round($cursor, 2);
            $cursor  += ($weights[$i] / $total) * $duration;
            $end      = round(min($cursor, $duration), 2);

            $beats[] = [
                'beat'  => $beatName,
                'start' => $start,
                'end'   => $end,
            ];
        }

        return [
            'pattern'      => $profile,
            'beats'        => $beats,
            'duration_sec' => $duration,
        ];
    }

    private function selectProfile(string $category, string $emoCode): string
    {
        return match ($category) {
            'aerial_vehicle'   => in_array($emoCode, ['power', 'drama', 'tense', 'hook'], true)
                ? 'action_burst' : 'aerial',
            'athletic_action'  => 'action_burst',
            'landscape_nature' => 'suspense',
            'product_craft'    => 'product',
            default            => in_array($emoCode, ['epic', 'hook', 'power', 'joy'], true)
                ? 'viral' : 'cinematic',
        };
    }
}

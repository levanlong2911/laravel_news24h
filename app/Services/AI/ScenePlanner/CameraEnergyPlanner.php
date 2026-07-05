<?php

namespace App\Services\AI\ScenePlanner;

/**
 * CameraEnergyPlanner — injects velocity curves into cinematic beat directives.
 *
 * The most important insight: camera velocity must NOT be constant.
 * Constant velocity = constant visual stimulus = brain adaptation = scroll.
 *
 * Each beat receives a velocity percentage and a kinetic phrase that gets
 * prepended to the camera directive. The contrast between beats (e.g. 280% → 35%)
 * is what creates the "dopamine hit" — shock, momentum, then sudden stillness.
 *
 * Velocity DSL tokens (model-agnostic semantic):
 *   burst   250%+   — explosive snap, shock
 *   rush    180–249% — high-speed sustained push
 *   push    100–179% — accelerated, energized
 *   natural 50–99%  — organic, controlled
 *   brake   20–49%  — decelerating, held, stillness after speed
 *   hover   <20%    — near-static (rare, product/craft only)
 *
 * KlingSerializer (KlingRenderer) translates each token to Kling-specific wording.
 * This decouples semantic intent from model-specific language — future models
 * get their own translation without touching this planner.
 *
 * Pipeline position (Sprint 6+):
 *   CinematicBeatPlanner → CameraEnergyPlanner → enhanced beat timeline
 */
final class CameraEnergyPlanner
{
    // Velocity profile per beat for each category+emotion combination.
    // Index order: [hook, escalation, reveal, payoff, resolution]

    private const PROFILES = [
        // Aerial in awe/epic: shock dive → banking speed → HARD BRAKE → slow scale reveal
        'aerial_awe'   => [280, 220, 35, 65, 20],

        // Aerial in power/hook: everything fast — reveal stays energized
        'aerial_power' => [300, 280, 150, 80, 35],

        // Athletic: snap → aggressive push → FREEZE → wide reveal
        'athletic'     => [250, 200, 50, 120, 40],

        // Nature/landscape: altitude burst → sweep → hover stillness → pull back
        'landscape'    => [200, 160, 55, 80,  20],

        // Product/craft: macro drop → slow orbit → beauty stillness → pull back
        'product'      => [180, 55,  35, 80,  25],

        // Generic fallback
        'generic'      => [200, 160, 60, 100, 30],
    ];

    private const BEAT_ORDER = ['hook', 'escalation', 'reveal', 'payoff', 'resolution'];

    /**
     * @param  array  $beats     CinematicBeatPlanner beats — [{beat, start, end, camera, subject, intensity}]
     * @param  string $category  Subject category from CinematicBeatPlanner (aerial_vehicle, athletic_action, …)
     * @param  string $emoCode   Emotion code (AWE, POWER, EPIC, HOOK, …)
     * @return array             {energy_beats[], profile, velocity_curve[]}
     */
    public function plan(array $beats, string $category, string $emoCode): array
    {
        $profileKey = $this->resolveProfile($category, strtolower($emoCode));
        $profile    = self::PROFILES[$profileKey];

        $energyBeats = [];
        foreach ($beats as $beat) {
            $beatName = $beat['beat'];
            $orderIdx = array_search($beatName, self::BEAT_ORDER, true);
            $velocity = ($orderIdx !== false && isset($profile[$orderIdx]))
                ? $profile[$orderIdx]
                : 100;

            // Emit a model-agnostic DSL token — KlingSerializer does the wording.
            // Do NOT modify $beat['camera'] here; that is the serializer's job.
            $energyBeats[] = array_merge($beat, [
                'velocity_pct'   => $velocity,
                'velocity_token' => $this->velocityToken($velocity),
            ]);
        }

        return [
            'energy_beats'   => $energyBeats,
            'profile'        => $profileKey,
            'velocity_curve' => array_column($energyBeats, 'velocity_pct'),
        ];
    }

    // ── Private helpers ───────────────────────────────────────────────────────

    private function resolveProfile(string $category, string $emoCode): string
    {
        $isAwe = in_array($emoCode, ['awe', 'epic', 'reveal', 'calm', 'joy'], true);

        return match (true) {
            $category === 'aerial_vehicle'  && $isAwe  => 'aerial_awe',
            $category === 'aerial_vehicle'  && !$isAwe => 'aerial_power',
            $category === 'athletic_action'            => 'athletic',
            $category === 'landscape_nature'           => 'landscape',
            $category === 'product_craft'              => 'product',
            default                                    => 'generic',
        };
    }

    /**
     * Maps velocity percentage to a model-agnostic DSL token.
     * KlingSerializer (KlingRenderer) translates each token to Kling-specific wording.
     */
    private function velocityToken(int $velocityPct): string
    {
        if ($velocityPct >= 250) return 'burst';
        if ($velocityPct >= 180) return 'rush';
        if ($velocityPct >= 80)  return 'push';
        if ($velocityPct >= 40)  return 'brake';
        if ($velocityPct >= 10)  return 'hover';
        return 'static';
    }
}

<?php

namespace App\Services\AI\ScenePlanner;

/**
 * SecondaryMotionPlanner — injects beat-aware environment and object motion cues.
 *
 * Model video AI responds strongly to specific physical motion descriptions
 * (water droplets, foam spray, cloth flutter, sun glints) because these are
 * heavily represented in its training data. A static background kills the
 * sense of life in the video even if the main subject is moving.
 *
 * Each output entry maps a beat name to a motion description that goes into
 * the `environment` field of that beat's timeline segment. ScenePlanner then
 * injects these into the beat timeline via injectSecondaryMotion().
 *
 * Pipeline position (Sprint 6+):
 *   CameraEnergyPlanner → SecondaryMotionPlanner → enrich beat environment fields
 */
final class SecondaryMotionPlanner
{
    // ── Beat motion templates by subject category ─────────────────────────────
    // Each key is a beat name; value is the motion cue for that beat.
    // {actor} is NOT used here — these describe the environment, not the subject.

    /** Aerial vehicles over water: yacht, ship, aircraft. */
    private const MOTION_AERIAL_WATER = [
        'hook'       => 'Drone downwash creates circular pressure ripple expanding outward on water surface — turbulence radiates from center',
        'escalation' => 'Bow wave foam sprays from hull at banking speed — water droplets suspended in golden light — curved wake arcs behind',
        'reveal'     => 'Wake fans in chevron pattern from hull — sun glints pulse rhythmically on wave faces — hull reflection shimmers below',
        'payoff'     => 'Full wake trail stretches toward horizon — seabirds wheel in thermal disturbance overhead — surface foam disperses',
        'resolution' => 'Ocean surface calms — wake diffuses into gentle swells — distant foam dissolves into deep blue',
    ];

    /** Aerial vehicles over land: aircraft, rocket, race car, drone. */
    private const MOTION_AERIAL_LAND = [
        'hook'       => 'Ground disturbance from downwash — dust and debris scatter outward in circular pattern',
        'escalation' => 'Exhaust or rotor wash flattens vegetation below — loose particles stream in the slipstream',
        'reveal'     => 'Subject shadow moves across ground — terrain features pass in sharp detail',
        'payoff'     => 'Landscape scale revealed — shadows of clouds move across terrain — distant motion adds depth',
        'resolution' => 'Environmental disturbance settles — natural ground motion resumes',
    ];

    /** Athletic action: sports, physical performance. */
    private const MOTION_ATHLETIC = [
        'hook'       => 'Cold breath vapor bursts in sharp exhale — jersey fabric tightens across shoulder pads',
        'escalation' => 'Cleats dig into turf — snow or grass spray from plant foot — jersey strains at shoulder seam during wind-up',
        'reveal'     => 'Finger pressure visible on equipment grip — wrist tendons tighten — micro-motion of hands and wrists',
        'payoff'     => 'Crowd erupts — flags and banners snap — snow or confetti disturbed by crowd wave — breath vapor clouds from thousands',
        'resolution' => 'Exhaled breath dissipates in cold air — stadium energy softens — scattered particles settle',
    ];

    /** Landscape and nature: mountains, oceans, forests, weather. */
    private const MOTION_LANDSCAPE = [
        'hook'       => 'Cloud vapor tears apart as drone descends through the layer — wisps trail in the wake',
        'escalation' => 'Wind-bent vegetation streams past at speed — water glints and rock faces flash by below',
        'reveal'     => 'Foreground grasses move in steady wind — distant waterfall catches light and fractures — micro-motion everywhere at different scales',
        'payoff'     => 'Wind patterns visible across vast terrain — cloud shadows race across landscape — scale of natural forces evident',
        'resolution' => 'Natural rhythm reasserts — wind, water, and light shift at their own unhurried pace',
    ];

    /** Product and craft: watches, jewelry, food, machinery. */
    private const MOTION_PRODUCT = [
        'hook'       => 'Surface micro-texture fills frame at extreme macro — material grain and imperfections resolve in sharp detail',
        'escalation' => 'Specular highlight traces across surface as camera orbits — reflections shift and rotate with the angle',
        'reveal'     => 'Beauty light creates sharp specular point — dust particles float in the beam above the product — surface glows',
        'payoff'     => 'Product catches ambient light from all angles — environmental reflections visible on polished surface — depth of field shifts',
        'resolution' => 'Final light condition holds — product rests in composed stillness — background softly out of focus',
    ];

    /** Generic fallback. */
    private const MOTION_GENERIC = [
        'hook'       => 'Immediate environmental disturbance — motion registers throughout the frame from the first moment',
        'escalation' => 'Secondary elements respond to primary action — background energy builds in reaction',
        'reveal'     => 'Environmental depth — background motion provides spatial context for the subject',
        'payoff'     => 'Full spatial scale — secondary motion reveals depth and distance relationships',
        'resolution' => 'Environment settles into natural cadence — ambient motion continues',
    ];

    /**
     * Generates per-beat environment motion cues based on subject category.
     *
     * @param  string $category   From CinematicBeatPlanner (aerial_vehicle, athletic_action, …)
     * @param  array  $dsl        Shot DSL (for additional context hints like light, environment)
     * @return array              {beat_name: motion_string, …}
     */
    public function plan(string $category, array $dsl = []): array
    {
        $templates = $this->resolveTemplates($category, $dsl);

        return [
            'beat_motion' => $templates,
            'category'    => $category,
        ];
    }

    // ── Private helpers ───────────────────────────────────────────────────────

    private function resolveTemplates(string $category, array $dsl): array
    {
        if ($category === 'aerial_vehicle') {
            // Water environments get the richer water-motion set
            return $this->isWaterEnvironment($dsl)
                ? self::MOTION_AERIAL_WATER
                : self::MOTION_AERIAL_LAND;
        }

        return match ($category) {
            'athletic_action' => self::MOTION_ATHLETIC,
            'landscape_nature'=> self::MOTION_LANDSCAPE,
            'product_craft'   => self::MOTION_PRODUCT,
            default           => self::MOTION_GENERIC,
        };
    }

    /**
     * Determines whether the scene is set over water, enabling the richer
     * water-motion template. Checks scene_title and camera_goal for keywords.
     */
    private function isWaterEnvironment(array $dsl): bool
    {
        $text = strtolower(
            ($dsl['scene_title']  ?? '') . ' ' .
            ($dsl['camera_goal'] ?? '') . ' ' .
            ($dsl['sub']['actor'] ?? '')
        );

        $waterKeywords = ['ocean', 'sea', 'lake', 'river', 'water', 'yacht', 'ship', 'boat', 'vessel', 'marine'];
        foreach ($waterKeywords as $kw) {
            if (str_contains($text, $kw)) {
                return true;
            }
        }
        return false;
    }
}

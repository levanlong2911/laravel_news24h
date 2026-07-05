<?php

namespace App\Services\AI\ScenePlanner;

/**
 * CameraMotivationPlanner — per-beat camera motivation (the WHY behind each move).
 *
 * A camera description tells the model what happens. A camera motivation tells the
 * model what the camera is trying to achieve. The difference in output quality is
 * substantial — models trained on human cinematography understand intentionality.
 *
 * Motivations are written as purpose clauses that follow the camera verb:
 *   "Snap zoom locks onto the quarterback's eyes [motivation]"
 *   → "to compress the world to a single point of will before the action"
 *
 * The reveal beat has no motivation here — the RevealPlan mechanism IS the motivation.
 * ("Camera pierces cloud base" is already purposeful; adding motivation would be redundant.)
 *
 * Motivations are embedded into BeatFusionEngine V2 camera sentences as the second
 * clause: "[camera_verb] [motivation], [atmosphere_active] — [eye_implicit]."
 */
final class CameraMotivationPlanner
{
    private const MOTIVATIONS = [
        'aerial_vehicle' => [
            'hook'       => 'before the subject below can be named or identified',
            'escalation' => 'as identity resolves through the clearing atmosphere below',
            'reveal'     => '', // RevealPlan mechanism handles motivation at this beat
            'payoff'     => 'to place the vessel against the true scale of its world',
            'resolution' => 'as the ocean reclaims its own proportion and the vessel recedes',
        ],
        'athletic_action' => [
            'hook'       => 'to compress the entire world to a single point of will',
            'escalation' => 'as the body commits toward an action that cannot be recalled',
            'reveal'     => '', // RevealPlan mechanism handles motivation at this beat
            'payoff'     => 'to place the moment against the full magnitude of its stage',
        ],
        'landscape_nature' => [
            'hook'       => 'before the earth reveals its scale or declares its name',
            'escalation' => 'as geological structure assembles itself across the widening frame',
            'reveal'     => '', // RevealPlan mechanism handles motivation at this beat
            'payoff'     => 'to hold geological time and natural scale in a single frame',
        ],
        'product_craft' => [
            'hook'       => 'before the object reveals form or declares its function',
            'escalation' => 'as purpose becomes legible from the emerging silhouette and edge',
            'reveal'     => '', // RevealPlan mechanism handles motivation at this beat
            'payoff'     => 'to present the object in its ideal and final state of being',
        ],
        'generic' => [
            'hook'       => 'before the subject is named or its context declared',
            'escalation' => 'as action builds toward its decisive and irreversible point',
            'reveal'     => '', // RevealPlan mechanism handles motivation at this beat
            'payoff'     => 'to reveal the full scale and context of the completed action',
        ],
    ];

    /**
     * @param  string $category From CinematicBeatPlan::$category
     * @param  array  $beats    CinematicBeatPlan::$beats
     * @return array            {beats: [{beat, motivation}]}
     */
    public function plan(string $category, array $beats): array
    {
        $profile = self::MOTIVATIONS[$category] ?? self::MOTIVATIONS['generic'];
        $result  = [];

        foreach ($beats as $beat) {
            $beatName = $beat['beat'] ?? '';
            if ($beatName === '') {
                continue;
            }
            $motivation = $profile[$beatName] ?? $profile['payoff'] ?? '';
            $result[]   = ['beat' => $beatName, 'motivation' => $motivation];
        }

        return ['beats' => $result];
    }
}

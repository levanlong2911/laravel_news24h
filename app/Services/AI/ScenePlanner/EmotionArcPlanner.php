<?php

namespace App\Services\AI\ScenePlanner;

/**
 * EmotionArcPlanner — maps the cinematic beat arc to its emotional journey.
 *
 * Cinema is not a sequence of images — it is a sequence of emotional states.
 * The camera, light, depth, and rhythm all serve a single master: the feeling
 * the viewer is meant to experience at each beat.
 *
 * This planner makes the emotional subtext explicit so that downstream layers
 * (BeatFusionEngine, future Semantic Prompt Optimizer) can ensure every element
 * of a beat points at the same emotional destination.
 *
 * Arc per category:
 *   aerial_vehicle:   wonder → recognition → declaration → awe
 *   athletic_action:  tension → anticipation → release → awe
 *   landscape_nature: mystery → recognition → wonder → awe
 *   product_craft:    intrigue → recognition → satisfaction → desire
 *
 * The 'signature' field is a one-line descriptor of WHAT triggers the emotion —
 * used by BeatFusionEngine to colour the fused sentence with emotional specificity.
 */
final class EmotionArcPlanner
{
    private const EMOTION_ARCS = [
        'aerial_vehicle' => [
            'hook'       => ['state' => 'wonder',      'signature' => 'identity withheld — velocity and scale given before form'],
            'escalation' => ['state' => 'recognition', 'signature' => 'form assembles from cloud and light as the world below resolves'],
            'reveal'     => ['state' => 'declaration', 'signature' => 'vessel named against sky and ocean at full declaration'],
            'payoff'     => ['state' => 'awe',          'signature' => 'human scale made insignificant against infinite environment'],
            'resolution' => ['state' => 'resolution',  'signature' => 'world returns to its own proportion — vessel absorbed'],
        ],
        'athletic_action' => [
            'hook'       => ['state' => 'tension',      'signature' => 'will and intent compressed to a single face before action'],
            'escalation' => ['state' => 'anticipation', 'signature' => 'body loading toward commitment — crowd holding breath'],
            'reveal'     => ['state' => 'release',      'signature' => 'action committed and irreversible — the throw is already made'],
            'payoff'     => ['state' => 'awe',           'signature' => 'the magnitude of the moment declared against stadium scale'],
        ],
        'landscape_nature' => [
            'hook'       => ['state' => 'mystery',     'signature' => 'earth without name or scale — only surface and time'],
            'escalation' => ['state' => 'recognition', 'signature' => 'geological structure assembling — the world identifying itself'],
            'reveal'     => ['state' => 'wonder',      'signature' => 'natural scale fully declared — the landscape claims the frame'],
            'payoff'     => ['state' => 'awe',          'signature' => 'geological time made visible — one frame holds millions of years'],
        ],
        'product_craft' => [
            'hook'       => ['state' => 'intrigue',    'signature' => 'material whispers quality and care before form is given'],
            'escalation' => ['state' => 'recognition', 'signature' => 'form emerging — function and purpose becoming legible'],
            'reveal'     => ['state' => 'satisfaction','signature' => 'precision declared — craftsmanship confirmed at the defining detail'],
            'payoff'     => ['state' => 'desire',      'signature' => 'object in its ideal and final presentation state'],
        ],
        'generic' => [
            'hook'       => ['state' => 'tension',      'signature' => 'subject present before declared — world narrowed to one point'],
            'escalation' => ['state' => 'anticipation', 'signature' => 'action loading toward its decisive moment'],
            'reveal'     => ['state' => 'release',      'signature' => 'moment of commitment and declaration'],
            'payoff'     => ['state' => 'resolution',   'signature' => 'action placed in its full world context'],
        ],
    ];

    /**
     * @param  string $category From CinematicBeatPlan::$category
     * @param  array  $beats    CinematicBeatPlan::$beats
     * @return array            {beats: [{beat, state, signature}]}
     */
    public function plan(string $category, array $beats): array
    {
        $arc    = self::EMOTION_ARCS[$category] ?? self::EMOTION_ARCS['generic'];
        $result = [];

        foreach ($beats as $beat) {
            $beatName = $beat['beat'] ?? '';
            if ($beatName === '') {
                continue;
            }
            $entry    = $arc[$beatName] ?? $arc['payoff'] ?? ['state' => 'resolution', 'signature' => ''];
            $result[] = array_merge(['beat' => $beatName], $entry);
        }

        return ['beats' => $result];
    }
}

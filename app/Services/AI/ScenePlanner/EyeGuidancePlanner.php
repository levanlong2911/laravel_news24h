<?php

namespace App\Services\AI\ScenePlanner;

/**
 * EyeGuidancePlanner — per-beat eye anchor chain.
 *
 * Hollywood's most closely held craft: the director decides WHERE the viewer
 * looks before deciding what the camera does. The eye doesn't wander — it's
 * guided through a deliberate sequence of anchor points.
 *
 * The chain is not random. It follows cognitive priority:
 *   1. Emotion anchor  — face/eyes establish emotional context before action
 *   2. Action anchor   — hands/body reveal WHAT is about to happen
 *   3. Contact anchor  — the decisive moment (release, impact, reveal)
 *   4. Scale anchor    — environmental context that frames the meaning
 *
 * For aerial subjects, the chain shifts to visual curiosity anchors:
 *   1. Velocity cue    — speed/scale before identity (brain registers motion first)
 *   2. Form anchor     — silhouette becoming legible
 *   3. Detail anchor   — defining structural feature
 *   4. Scale anchor    — subject vs world
 *
 * Injected into the 'camera' field per beat — appended after existing camera
 * instructions since eye guidance is a directorial overlay, not a camera move.
 */
final class EyeGuidancePlanner
{
    private const BEAT_ANCHORS = [
        'aerial_vehicle' => [
            'hook'       => [
                'anchor'      => 'velocity_blur',
                'instruction' => 'Viewer eye drawn to velocity trail — speed and scale before any identity.',
            ],
            'escalation' => [
                'anchor'      => 'silhouette_edge',
                'instruction' => 'Viewer eye drawn to vessel silhouette edge emerging from atmosphere.',
            ],
            'reveal'     => [
                'anchor'      => 'hull_waterline',
                'instruction' => 'Viewer eye anchored at hull waterline — defining structural line of the vessel.',
            ],
            'payoff'     => [
                'anchor'      => 'environmental_scale',
                'instruction' => 'Viewer eye sweeps to horizon — ocean scale overwhelms and completes the beat.',
            ],
            'resolution' => [
                'anchor'      => 'vanishing_point',
                'instruction' => 'Viewer eye follows vessel toward horizon — scene breathes and closes.',
            ],
        ],
        'athletic_action' => [
            'hook'       => [
                'anchor'      => 'eyes',
                'instruction' => 'Viewer eye locked on athlete eyes — emotion and intent before any action context.',
            ],
            'escalation' => [
                'anchor'      => 'hands',
                'instruction' => 'Viewer eye tracks to hands — grip and power loading readable in frame.',
            ],
            'reveal'     => [
                'anchor'      => 'release_point',
                'instruction' => 'Viewer eye snaps to ball release — the decisive contact point of the action.',
            ],
            'payoff'     => [
                'anchor'      => 'environmental_scale',
                'instruction' => 'Viewer eye opens to stadium scale — action placed in full world context.',
            ],
        ],
        'landscape_nature' => [
            'hook'       => [
                'anchor'      => 'texture_grain',
                'instruction' => 'Viewer eye reads surface texture — curiosity forced before scale is revealed.',
            ],
            'escalation' => [
                'anchor'      => 'structural_edge',
                'instruction' => 'Viewer eye finds geological edge — scale beginning to register in frame.',
            ],
            'reveal'     => [
                'anchor'      => 'horizon_break',
                'instruction' => 'Viewer eye pulled to horizon — where land meets sky, scale fully declared.',
            ],
            'payoff'     => [
                'anchor'      => 'environmental_scale',
                'instruction' => 'Viewer eye sweeps full terrain — geological time and scale complete.',
            ],
        ],
        'product_craft' => [
            'hook'       => [
                'anchor'      => 'surface_texture',
                'instruction' => 'Viewer eye reads material surface — quality and craftsmanship before identity.',
            ],
            'escalation' => [
                'anchor'      => 'object_edge',
                'instruction' => 'Viewer eye traces object silhouette — form and mass becoming legible.',
            ],
            'reveal'     => [
                'anchor'      => 'signature_detail',
                'instruction' => 'Viewer eye snaps to defining detail — brand signature or key mechanism.',
            ],
            'payoff'     => [
                'anchor'      => 'full_object',
                'instruction' => 'Viewer eye takes in complete product — function and beauty declared in full frame.',
            ],
        ],
        'generic' => [
            'hook'       => [
                'anchor'      => 'subject_presence',
                'instruction' => 'Viewer eye drawn to subject presence — scale and motion before declaration.',
            ],
            'escalation' => [
                'anchor'      => 'action_loading',
                'instruction' => 'Viewer eye tracks to the loading point of action — what is about to happen.',
            ],
            'reveal'     => [
                'anchor'      => 'contact_detail',
                'instruction' => 'Viewer eye snaps to the defining moment — the contact or peak of the action.',
            ],
            'payoff'     => [
                'anchor'      => 'environmental_scale',
                'instruction' => 'Viewer eye opens to full environmental context — action placed in world.',
            ],
        ],
    ];

    /**
     * @param  string $category From CinematicBeatPlan::$category
     * @param  array  $beats    CinematicBeatPlan::$beats
     * @return array            {beats: [{beat, eye_anchor, instruction}]}
     */
    public function plan(string $category, array $beats): array
    {
        $profile = self::BEAT_ANCHORS[$category] ?? self::BEAT_ANCHORS['generic'];
        $result  = [];

        foreach ($beats as $beat) {
            $beatName = $beat['beat'] ?? '';
            if ($beatName === '') {
                continue;
            }
            $anchor   = $profile[$beatName] ?? $profile['payoff'] ?? ['anchor' => 'subject', 'instruction' => ''];
            $result[] = [
                'beat'        => $beatName,
                'eye_anchor'  => $anchor['anchor'],
                'instruction' => $anchor['instruction'],
            ];
        }

        return ['beats' => $result];
    }
}

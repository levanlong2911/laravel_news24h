<?php

namespace App\Services\AI\ScenePlanner;

/**
 * VisualContrastPlanner — per-beat brightness and color temperature contrast.
 *
 * The brain doesn't notice continuity — it notices change. Alternating dark/bright
 * and warm/cool between beats creates visual rhythm that keeps the viewer engaged
 * even when the camera motion is slow or the subject is static.
 *
 * The contrast pattern is deliberately NOT uniform:
 *   aerial_vehicle:   dark cool → warm bright → warm bright → golden wide
 *   athletic_action:  amber still → bright surge → cold freeze → warm wide
 *   landscape_nature: cool still → cool bright → warm flood → golden wide
 *   product_craft:    dark neutral → cool clean → warm hero → bright clean
 *
 * The cold-freeze at the athletic reveal beat is intentional — peak action moments
 * feel "frozen in clinical light" in the best sports cinematography (slow-motion
 * bleached effect). This is the only beat where warm and cold swap unexpectedly.
 *
 * Each beat stores two fields:
 *   tone          — full descriptive string (legacy / debug use)
 *   light_phrase  — compact natural-language slot for BeatFusionEngine fusion sentences
 *                   (e.g. "dark amber stadium light", "cold bright clinical light")
 */
final class VisualContrastPlanner
{
    // Each entry: [tone (full description), light_phrase (compact fusion slot)]
    private const TONE_PROFILES = [
        'aerial_vehicle' => [
            'hook' => [
                'tone'         => 'Dark cool tone — cloud diffusion mutes the palette, velocity before warmth.',
                'light_phrase' => 'dark cool cloud-diffused light',
            ],
            'escalation' => [
                'tone'         => 'Warm brightening tone — golden light emerging through clearing atmosphere.',
                'light_phrase' => 'warming golden light through clearing atmosphere',
            ],
            'reveal' => [
                'tone'         => 'Bright warm golden — subject in full afternoon light, colour saturated.',
                'light_phrase' => 'bright warm golden afternoon light',
            ],
            'payoff' => [
                'tone'         => 'Bright warm still — golden hour wide frame, richest light of the shot.',
                'light_phrase' => 'rich golden hour light',
            ],
            'resolution' => [
                'tone'         => 'Warm fading tone — light softening as scene breathes to close.',
                'light_phrase' => 'softening warm light',
            ],
        ],
        'athletic_action' => [
            'hook' => [
                'tone'         => 'Dark amber tone — stadium shadow pools, pre-action stillness in low light.',
                'light_phrase' => 'dark amber stadium light',
            ],
            'escalation' => [
                'tone'         => 'Bright warm surge — floodlights at full power, action loading in hot light.',
                'light_phrase' => 'bright warm floodlights at full power',
            ],
            'reveal' => [
                'tone'         => 'Cold bright freeze — peak instant in clinical white light, colour desaturates at impact.',
                'light_phrase' => 'cold bright clinical light',
            ],
            'payoff' => [
                'tone'         => 'Warm bright wide — stadium energy in warm celebration light.',
                'light_phrase' => 'warm celebration light',
            ],
        ],
        'landscape_nature' => [
            'hook' => [
                'tone'         => 'Cool neutral tone — geological light, no warm bias, time unknown.',
                'light_phrase' => 'cool neutral geological light',
            ],
            'escalation' => [
                'tone'         => 'Cool bright — high altitude light, crisp and clean, scale registering.',
                'light_phrase' => 'crisp high-altitude light',
            ],
            'reveal' => [
                'tone'         => 'Warm light flooding — golden hour warmth entering frame as depth opens.',
                'light_phrase' => 'flooding golden hour warmth',
            ],
            'payoff' => [
                'tone'         => 'Warm golden wide — nature in full warm illumination, scene resolved.',
                'light_phrase' => 'warm golden wide light',
            ],
        ],
        'product_craft' => [
            'hook' => [
                'tone'         => 'Dark neutral tone — material in controlled low light, function withheld.',
                'light_phrase' => 'dark controlled low light',
            ],
            'escalation' => [
                'tone'         => 'Cool neutral — studio lighting clean, form emerging in unbiased light.',
                'light_phrase' => 'clean neutral studio light',
            ],
            'reveal' => [
                'tone'         => 'Bright warm — hero lighting on product, key detail glowing in warm focus.',
                'light_phrase' => 'bright warm hero light',
            ],
            'payoff' => [
                'tone'         => 'Bright neutral clean — final product in pure presentation light.',
                'light_phrase' => 'bright neutral presentation light',
            ],
        ],
        'generic' => [
            'hook' => [
                'tone'         => 'Dark cool tone — tension established in low ambient light.',
                'light_phrase' => 'dark cool ambient light',
            ],
            'escalation' => [
                'tone'         => 'Warm brightening — energy building, light intensifying with action.',
                'light_phrase' => 'warming intensifying light',
            ],
            'reveal' => [
                'tone'         => 'Bright warm peak — full illumination at the moment of declaration.',
                'light_phrase' => 'bright warm peak light',
            ],
            'payoff' => [
                'tone'         => 'Bright warm wide — resolution in warm full light, scene complete.',
                'light_phrase' => 'bright warm wide light',
            ],
        ],
    ];

    /**
     * @param  string $category From CinematicBeatPlan::$category
     * @param  array  $beats    CinematicBeatPlan::$beats
     * @return array            {beats: [{beat, tone, light_phrase}]}
     */
    public function plan(string $category, array $beats): array
    {
        $profile = self::TONE_PROFILES[$category] ?? self::TONE_PROFILES['generic'];
        $result  = [];

        foreach ($beats as $beat) {
            $beatName = $beat['beat'] ?? '';
            if ($beatName === '') {
                continue;
            }
            $entry    = $profile[$beatName] ?? $profile['payoff'] ?? ['tone' => '', 'light_phrase' => ''];
            $result[] = [
                'beat'         => $beatName,
                'tone'         => $entry['tone']         ?? '',
                'light_phrase' => $entry['light_phrase'] ?? '',
            ];
        }

        return ['beats' => $result];
    }
}

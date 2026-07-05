<?php

namespace App\Services\AI\ScenePlanner;

/**
 * RevealPlanner — determines HOW the reveal beat exposes the subject.
 *
 * "The best reveal is the one the viewer almost predicted — but didn't."
 *
 * The mechanism of reveal separates cinematic work from a wide shot: camera
 * descending through cloud layer, rack focus pulling from crowd blur to athlete,
 * golden flare clearing to expose a yacht — these are specific physical events
 * that Kling can render, unlike abstract instructions like "reveal dramatically".
 *
 * Mechanism selected by: category × emotion → one of 8 physical reveal types.
 * ScenePlanner::injectRevealMechanism() prepends the camera_instruction to the
 * CinematicBeatPlanner camera text at the reveal beat.
 *
 * 8 mechanism types:
 *   through_cloud    — drone descends through cloud/mist layer
 *   through_occluder — camera passes behind foreground element (wave, rock, tree)
 *   light_bloom      — sun flare or bright wash clears to declare subject
 *   focus_pull       — rack focus from ambient blur to sharp subject
 *   parallax_pass    — lateral move removes foreground, exposing depth
 *   orbit_reveal     — camera orbit exposes the hidden defining face of subject
 *   fog_clear        — atmospheric veil lifts to declare the scene
 *   silhouette_break — backlit dark form catches rim light, bursts into full color
 */
final class RevealPlanner
{
    private const MECHANISM_INSTRUCTIONS = [
        'through_cloud'    => 'Camera pierces cloud base — white mist dissolves to declare subject below —',
        'through_occluder' => 'Camera clears foreground barrier — occlusion breaks to full subject exposure —',
        'light_bloom'      => 'Golden flare washes frame — as light clears, subject emerges in full definition —',
        'focus_pull'       => 'Rack focus pulls from ambient blur — subject sharpens into decisive clarity —',
        'parallax_pass'    => 'Lateral track removes foreground layer — subject revealed at depth —',
        'orbit_reveal'     => 'Camera orbit completes — defining face of subject exposed at last —',
        'fog_clear'        => 'Atmospheric veil lifts — full scene declares itself in clean light —',
        'silhouette_break' => 'Backlit silhouette catches rim light — color and texture flood in from edge to center —',
    ];

    private const DESCRIPTIONS = [
        'through_cloud'    => 'Drone descends through cloud base — subject materialises below',
        'through_occluder' => 'Camera clears foreground element — subject emerges from cover',
        'light_bloom'      => 'Bright flare washes and clears — subject declared in full light',
        'focus_pull'       => 'World sharpens from blur — subject snaps into clarity at reveal beat',
        'parallax_pass'    => 'Lateral track removes foreground barrier — subject depth revealed',
        'orbit_reveal'     => 'Camera orbit exposes defining angle — subject fully declared',
        'fog_clear'        => 'Atmospheric clearing — subject declared in unobstructed light',
        'silhouette_break' => 'Dark form transitions — backlight bursts into full color and detail',
    ];

    /**
     * @param  string $category Subject category from CinematicBeatPlan
     * @param  array  $dsl      Shot DSL — needs 'emo'
     * @return array            {mechanism, trigger_beat, camera_instruction, description}
     */
    public function plan(string $category, array $dsl): array
    {
        $emoCode   = strtolower($dsl['emo'] ?? 'craft');
        $mechanism = $this->selectMechanism($category, $emoCode);

        return [
            'mechanism'          => $mechanism,
            'trigger_beat'       => 'reveal',
            'camera_instruction' => self::MECHANISM_INSTRUCTIONS[$mechanism] ?? '',
            'description'        => self::DESCRIPTIONS[$mechanism] ?? '',
        ];
    }

    private function selectMechanism(string $category, string $emoCode): string
    {
        return match ($category) {
            'aerial_vehicle' => in_array($emoCode, ['awe', 'calm', 'epic', 'reveal'], true)
                ? 'through_cloud'
                : 'parallax_pass',

            'athletic_action' => in_array($emoCode, ['calm', 'joy', 'reveal'], true)
                ? 'silhouette_break'
                : 'focus_pull',

            'landscape_nature' => in_array($emoCode, ['calm', 'awe', 'epic', 'reveal'], true)
                ? 'fog_clear'
                : 'through_occluder',

            'product_craft' => in_array($emoCode, ['craft', 'calm', 'reveal'], true)
                ? 'orbit_reveal'
                : 'light_bloom',

            default => 'focus_pull',
        };
    }
}

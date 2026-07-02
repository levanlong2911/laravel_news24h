<?php

namespace App\Services\AI\ScenePlanner;

/**
 * Stateful: tracks visual identity across shots within a scene.
 *
 * Two types of data per shot:
 *
 *   identity      — does NOT change between shots (jersey, role, gender, etc.)
 *                   Locked from shot 1 of each scene.
 *
 *   dynamic_state — CAN change (body position, object in hand, expression)
 *                   Updated per shot so the next shot gets the previous shot's
 *                   end state as its continuity context.
 *
 * This solves the "person changes between shots" problem:
 * by explicitly locking identity fields and passing them to every subsequent
 * shot's CONTINUITY section, the AI model has no reason to invent a new face,
 * jersey, or build.
 *
 * Stateful design: ScenePlanner is a Laravel singleton within the request, so
 * ContinuityPlanner also persists across enrich() calls for the same pipeline run.
 * State is keyed by scene_id (assumed globally unique per video project).
 */
final class ContinuityPlanner
{
    /** sceneId → {identity, dynamic_state} from most recently processed shot */
    private array $sceneState = [];

    /** Light code → human-readable weather name for continuity */
    private const LIGHT_WEATHER_NAME = [
        'W1' => 'snow',    'W2' => 'clear sunny',   'G1' => 'golden hour',
        'N1' => 'rainy',   'N2' => 'moonlit night',  'D1' => 'dramatic overcast',
        'S1' => 'soft indoor', 'S2' => 'soft ambient', 'C1' => 'clinical',
        'C2' => 'industrial',
    ];

    /** Light code → time of day */
    private const LIGHT_TIME = [
        'W1' => 'evening',   'W2' => 'afternoon',   'G1' => 'golden hour',
        'N1' => 'night',     'N2' => 'night',        'D1' => 'twilight',
        'S1' => 'daytime',   'S2' => 'daytime',      'C1' => '',  'C2' => '',
    ];

    /** Light code → dominant colour palette */
    private const LIGHT_PALETTE = [
        'W1' => 'warm amber', 'W2' => 'warm golden', 'G1' => 'warm orange',
        'N1' => 'cool neon',  'N2' => 'cool blue',   'D1' => 'high contrast',
        'S1' => 'soft neutral', 'S2' => 'soft warm',  'C1' => 'neutral cool',
        'C2' => 'cool industrial',
    ];

    /** Light code → field/surface condition */
    private const LIGHT_FIELD_CONDITION = [
        'W1' => 'frozen',  'W2' => 'dry',   'G1' => 'dry',
        'N1' => 'wet',     'N2' => 'damp',  'D1' => 'normal',
        'S1' => 'indoor',  'S2' => 'indoor', 'C1' => 'indoor',
        'C2' => 'indoor',
    ];

    /**
     * Build the continuity plan for the current shot, applying locked identity
     * from shot 1 and exposing the previous shot's dynamic state for injection.
     *
     * @param  array $dsl          Shot DSL (must include scene_id, shot_order)
     * @param  array $actionResult Full result from ActionPlanner::plan()
     * @param  array $physics      Result from PhysicsPlanner::plan()
     * @param  array $director     Result from DirectorPlanner::plan()
     * @return array{
     *   character: array{identity: array, dynamic_state: array},
     *   environment: array,
     *   camera: array,
     *   constraints: array,
     *   previous_state: array|null,
     * }
     */
    public function plan(array $dsl, array $actionResult, array $physics, array $director): array
    {
        $sceneId   = $dsl['scene_id']   ?? 'default';
        $shotOrder = (int) ($dsl['shot_order'] ?? 1);

        $identity    = $this->buildIdentity($dsl, $actionResult);
        $dynState    = $this->buildDynamicState($actionResult);
        $environment = $this->buildEnvironment($dsl, $physics);
        $camera      = $this->buildCamera($dsl, $director);
        $constraints = $this->buildConstraints($identity, $environment);

        if ($shotOrder <= 1 || !isset($this->sceneState[$sceneId])) {
            // First shot: lock identity, record dynamic state
            $this->sceneState[$sceneId] = [
                'identity'      => $identity,
                'dynamic_state' => $dynState,
            ];
            return [
                'character'      => ['identity' => $identity, 'dynamic_state' => $dynState],
                'environment'    => $environment,
                'camera'         => $camera,
                'constraints'    => $constraints,
                'previous_state' => null,
            ];
        }

        // Subsequent shots: use locked identity from shot 1
        $lockedIdentity   = $this->sceneState[$sceneId]['identity'];
        $prevDynamicState = $this->sceneState[$sceneId]['dynamic_state'];

        // Update state so the NEXT shot gets this shot's end state
        $this->sceneState[$sceneId]['dynamic_state'] = $dynState;

        return [
            'character'      => ['identity' => $lockedIdentity, 'dynamic_state' => $dynState],
            'environment'    => $environment,
            'camera'         => $camera,
            'constraints'    => $constraints,
            'previous_state' => $prevDynamicState,
        ];
    }

    // ── Private builders ────────────────────────────────────────────────────

    /**
     * Identity = properties that must NOT change between shots.
     * Currently derived from what's available in the DSL; Sprint 3 will
     * enrich these fields from VisualMomentDTO (jersey color, number, etc.).
     */
    private function buildIdentity(array $dsl, array $actionResult): array
    {
        return [
            'role'    => $actionResult['primary_actor'] ?? $dsl['sub']['actor'] ?? '',
            'gender'  => '',          // to be filled from VisualMomentDTO in Sprint 3
            'jersey'  => '',          // to be filled from VisualMomentDTO
            'helmet'  => '',          // to be filled from VisualMomentDTO
            'number'  => '',          // to be filled from VisualMomentDTO
            'posture' => '',          // to be filled from VisualMomentDTO
        ];
    }

    /**
     * Dynamic state = body position / object in hand at END of this shot.
     * This becomes the "previous state" that shot N+1 references in its
     * CONTINUITY section to maintain physical plausibility.
     */
    private function buildDynamicState(array $actionResult): array
    {
        $timeline  = $actionResult['timeline'] ?? [];
        $lastPhase = !empty($timeline) ? end($timeline) : [];

        return [
            'action_phase'   => $lastPhase['subject'] ?? '',
            'action_type'    => $actionResult['action_type'] ?? '',
            'object_in_hand' => $actionResult['object_in_hand'] ?? '',
        ];
    }

    /**
     * Environment = scene conditions that persist across shots.
     * Uses both DSL light code and PhysicsPlanner output for richer description.
     */
    private function buildEnvironment(array $dsl, array $physics): array
    {
        $lightCode  = $dsl['light'] ?? '';
        $emoCode    = $dsl['emo']   ?? '';
        $crowdDense = in_array($emoCode, ['POWER', 'JOY', 'EPIC', 'HOOK'], true) ? 'packed' : 'seated';

        // Use the first atmosphere phrase from physics for a descriptive weather label
        $weatherDesc = $physics['atmosphere'][0] ?? '';

        return [
            'weather'         => self::LIGHT_WEATHER_NAME[$lightCode] ?? 'clear',
            'weather_desc'    => $weatherDesc,
            'time'            => self::LIGHT_TIME[$lightCode]            ?? '',
            'palette'         => self::LIGHT_PALETTE[$lightCode]         ?? '',
            'field_condition' => self::LIGHT_FIELD_CONDITION[$lightCode] ?? 'normal',
            'crowd_density'   => $crowdDense,
        ];
    }

    /**
     * Constraints = flags that tell the renderer what MUST be preserved
     * when injecting the CONTINUITY section into a subsequent shot.
     *
     * Veo, Kling, and Seedance all respect "must keep" directives differently,
     * but having structured booleans lets each renderer decide how to phrase them.
     */
    private function buildConstraints(array $identity, array $environment): array
    {
        return [
            'must_keep_face'    => ($identity['role'] ?? '') !== '',
            'must_keep_jersey'  => ($identity['jersey'] ?? '') !== '',
            'must_keep_weather' => ($environment['weather'] ?? '') !== '',
            'must_keep_palette' => ($environment['palette'] ?? '') !== '',
            'must_keep_camera'  => true,   // always maintain camera consistency
        ];
    }

    /**
     * Camera continuity = lens and position metadata to keep across shots
     * so subsequent shots don't feel like a different camera setup.
     */
    private function buildCamera(array $dsl, array $director): array
    {
        return [
            'lens'         => $director['lens']          ?? '50mm',
            'height'       => $director['camera_height'] ?? 'eye-level',
            'angle'        => 'front_left',              // default; expandable from cam_code
            'camera_style' => $director['stabilization'] ?? 'steady',
            'movement'     => $dsl['move']               ?? 'STATIC',
        ];
    }
}

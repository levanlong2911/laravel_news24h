<?php

namespace App\Services\AI\ScenePlanner;

/**
 * Rule-based: shot DSL + action result → visual composition metadata.
 *
 * Composition is the spatial organisation of elements in the frame:
 * where the subject sits, what draws the eye, how depth layers separate.
 * This is a major driver of perceived visual quality and is often missing
 * from AI video pipelines that only describe WHAT is happening, not
 * WHERE things are in the frame and how depth is organised.
 *
 * Output keys:
 *   foreground      — what's in the closest depth plane
 *   midground       — what's at natural depth
 *   background      — what's blurred behind the action
 *   negative_space  — open area that gives the subject room to breathe
 *   subject_position — where the subject sits (center/left_third/right_third/lower_third/full_frame)
 *   rule_of_thirds  — whether the composition is offset (true) or centered (false)
 *   leading_lines   — physical lines in the frame that guide the eye
 *
 * Downstream renderers translate these into model-specific framing instructions.
 * Kling uses them in the CAMERA section. Veo can use them in composition metadata.
 */
final class CompositionPlanner
{
    /** Camera code → base subject position and negative space */
    private const CAM_BASE = [
        'AERIAL'   => ['position' => 'center',        'negative_space' => 'wide open field extending to all edges of frame'],
        'TRACKING' => ['position' => 'leading_third', 'negative_space' => 'open space ahead of the subject motion direction'],
        'ORBITAL'  => ['position' => 'center',        'negative_space' => 'rotating environment creates dynamic space around subject'],
        'WIDE'     => ['position' => 'lower_third',   'negative_space' => 'sky and stadium fill the upper two thirds of frame'],
        'MEDIUM'   => ['position' => 'center',        'negative_space' => 'balanced space on both sides of the subject'],
        'CLOSE'    => ['position' => 'center',        'negative_space' => 'tight frame — subject nearly fills the composition'],
        'MACRO'    => ['position' => 'full_frame',    'negative_space' => 'none — texture fills the entire frame'],
        'POV'      => ['position' => 'center',        'negative_space' => 'frame edges open — first-person sightline perspective'],
    ];

    /** Emotion code → position override and rule-of-thirds preference */
    private const EMO_ENERGY = [
        'EPIC'   => ['position' => 'lower_third', 'thirds' => true],
        'AWE'    => ['position' => 'lower_third', 'thirds' => true],
        'REVEAL' => ['position' => 'lower_third', 'thirds' => true],
        'POWER'  => ['position' => 'left_third',  'thirds' => true],
        'JOY'    => ['position' => 'center',      'thirds' => true],
        'CRAFT'  => ['position' => 'left_third',  'thirds' => true],
        'HOOK'   => ['position' => 'center',      'thirds' => false],
        'TENSE'  => ['position' => 'center',      'thirds' => false],
        'DRAMA'  => ['position' => 'center',      'thirds' => false],
        'CALM'   => ['position' => 'center',      'thirds' => true],
        'FEAR'   => ['position' => 'center',      'thirds' => false],
    ];

    /** Camera code → foreground plane description */
    private const CAM_FOREGROUND = [
        'AERIAL'   => 'subject small but sharp from directly above, environment spreading outward',
        'TRACKING' => 'subject in sharp focus leading the frame, motion blur trailing behind',
        'ORBITAL'  => 'subject centered in sharp relief, environment sweeping around them',
        'WIDE'     => 'field markings and turf texture in the foreground frame the distant action',
        'MEDIUM'   => 'subject fills the center foreground plane, naturally framed',
        'CLOSE'    => 'subject face or key detail in sharp foreground isolation',
        'MACRO'    => 'extreme surface texture fills the entire foreground plane',
        'POV'      => 'near elements in the subject sightline — what they reach toward',
    ];

    /** Camera code → midground plane description */
    private const CAM_MIDGROUND = [
        'AERIAL'   => 'field structure, players, boundary lines at mid-depth',
        'TRACKING' => 'other players and team structure provide natural midground context',
        'ORBITAL'  => 'surrounding scene passes through midground as camera sweeps',
        'WIDE'     => 'players and game action visible as natural depth midground',
        'MEDIUM'   => 'nearby players and game environment at comfortable reading depth',
        'CLOSE'    => 'blurred shapes in midground suggest the surrounding scene',
        'MACRO'    => 'no distinct midground — pure compression behind the detail',
        'POV'      => 'game action visible at natural depth from subject point of view',
    ];

    /** Camera code → background plane description */
    private const CAM_BACKGROUND = [
        'AERIAL'   => 'full stadium, surrounding city, or open field visible in background distance',
        'TRACKING' => 'crowd and stadium in soft focus — energy and color visible as blur',
        'ORBITAL'  => 'stadium wraps as a rotating blur of color and scale in background',
        'WIDE'     => 'full stadium scale visible — crowd, skyline, sky horizon',
        'MEDIUM'   => 'crowd and stadium blur creates the environmental atmosphere',
        'CLOSE'    => 'background completely blurred — pure bokeh energy behind subject',
        'MACRO'    => 'background is pure abstraction — shapes and light only',
        'POV'      => 'full scene visible at subject sightline depth, naturalistic',
    ];

    /** Action type → leading lines description */
    private const ACTION_LEADING_LINES = [
        'fb_throw'       => 'throwing arm extension angle points toward receiver downfield — eye follows the throw line',
        'fb_catch'       => 'hands raised upward — eye leads to the incoming ball arc above',
        'fb_run'         => 'forward body lean and stride direction drives the eye through the gap',
        'fb_kick'        => 'kicking leg extension line aims toward the uprights — eye follows the trajectory',
        'fb_celebrate'   => 'raised arms frame the crowd energy above and behind the subject',
        'fb_tackle'      => 'defender drive angle converges on ball carrier — collision point is the visual anchor',
        'fb_dive'        => 'fully extended body line stretches diagonally toward the target',
        'bb_dunk'        => 'vertical rise from ground to rim dominates — eye pulled upward to the finish',
        'bb_shoot'       => 'shooting arc from fingertips upward toward the basket — eye follows the arc',
        'bb_handle'      => 'crossover motion creates a sharp diagonal line across the frame',
        'sc_goal'        => 'kick trajectory line from boot toward the goal opening — eye follows the ball',
        'sc_save'        => 'goalkeeper dive arc toward the shot direction — extension is the leading line',
        'drive_drift'    => 'car arc trajectory through the corner radius — smoke trail extends the line',
        'drive_launch'   => 'straight forward acceleration line from launch point — vanishing point perspective',
        'generic_action' => 'subject body line directs attention toward the focal point of the action',
    ];

    /** Action type → primary and secondary eye anchor points (Sprint 5) */
    private const ACTION_EYE_ANCHOR = [
        'fb_throw'       => ['primary' => 'throwing hand at release point',        'secondary' => 'ball mid-arc trajectory'],
        'fb_catch'       => ['primary' => 'receiver hands at catch zone',           'secondary' => 'ball arriving from above'],
        'fb_run'         => ['primary' => 'downfield gap opening ahead of runner',  'secondary' => 'lead foot plant strike'],
        'fb_kick'        => ['primary' => 'foot-to-ball contact point',             'secondary' => 'ball arc toward uprights'],
        'fb_celebrate'   => ['primary' => 'face — emotion peak expression',         'secondary' => 'raised arms silhouette against crowd'],
        'fb_tackle'      => ['primary' => 'collision contact zone',                 'secondary' => 'ball carrier eyes'],
        'fb_dive'        => ['primary' => 'fingertips at target',                   'secondary' => 'body in full extension'],
        'bb_dunk'        => ['primary' => 'hands at rim contact',                   'secondary' => 'ball at apex above rim'],
        'bb_shoot'       => ['primary' => 'ball leaving fingertips',                'secondary' => 'arc trajectory peak'],
        'bb_handle'      => ['primary' => 'dominant hand at crossover contact',     'secondary' => 'defender reaction'],
        'sc_goal'        => ['primary' => 'ball crossing goal line',                'secondary' => 'goalkeeper reaction expression'],
        'sc_save'        => ['primary' => 'goalkeeper hands at block point',        'secondary' => 'ball deflection direction'],
        'drive_drift'    => ['primary' => 'front wheel at corner apex',             'secondary' => 'smoke trail curve exit'],
        'drive_launch'   => ['primary' => 'front bumper at launch',                 'secondary' => 'vanishing point ahead'],
        'generic_action' => ['primary' => 'subject face',                           'secondary' => 'primary action contact point'],
    ];

    /**
     * Camera code → anchor strength [0.0–1.0].
     * AERIAL/WIDE have low strength (hard to pinpoint in vast frame).
     * CLOSE/MACRO have near-1.0 (subject nearly fills frame — anchor is obvious).
     */
    private const CAM_EYE_STRENGTH = [
        'AERIAL'   => 0.3,
        'TRACKING' => 0.6,
        'ORBITAL'  => 0.5,
        'WIDE'     => 0.3,
        'MEDIUM'   => 0.75,
        'CLOSE'    => 0.9,
        'MACRO'    => 1.0,
        'POV'      => 0.7,
    ];

    public function plan(array $dsl, array $actionResult): array
    {
        $camCode    = $dsl['cam']  ?? 'MEDIUM';
        $emoCode    = $dsl['emo']  ?? 'CRAFT';
        $actionType = $actionResult['action_type'] ?? 'generic_action';

        $camBase   = self::CAM_BASE[$camCode]   ?? self::CAM_BASE['MEDIUM'];
        $emoEnergy = self::EMO_ENERGY[$emoCode] ?? [];

        $position     = $emoEnergy['position'] ?? $camBase['position'];
        $ruleOfThirds = $emoEnergy['thirds']   ?? ($position !== 'center' && $position !== 'full_frame');

        $eyeAnchorBase = self::ACTION_EYE_ANCHOR[$actionType] ?? self::ACTION_EYE_ANCHOR['generic_action'];
        $eyeStrength   = self::CAM_EYE_STRENGTH[$camCode]     ?? 0.75;

        return [
            'foreground'       => self::CAM_FOREGROUND[$camCode]           ?? '',
            'midground'        => self::CAM_MIDGROUND[$camCode]            ?? '',
            'background'       => self::CAM_BACKGROUND[$camCode]           ?? '',
            'negative_space'   => $camBase['negative_space'],
            'subject_position' => $position,
            'rule_of_thirds'   => $ruleOfThirds,
            'leading_lines'    => self::ACTION_LEADING_LINES[$actionType]  ?? '',
            'eye_anchor'       => [
                'primary'   => $eyeAnchorBase['primary'],
                'secondary' => $eyeAnchorBase['secondary'],
                'strength'  => $eyeStrength,
            ],
        ];
    }
}

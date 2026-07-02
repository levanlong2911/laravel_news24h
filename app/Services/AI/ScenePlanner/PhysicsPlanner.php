<?php

namespace App\Services\AI\ScenePlanner;

/**
 * Rule-based: shot DSL + action result → physics{} nested secondary motion.
 *
 * Sprint 3 redesign — four semantic layers, decoupled from light_code:
 *
 *   atmosphere[]   — weather and atmospheric effects (driven by weather type, not light code)
 *   interaction[]  — physical contact between subject and environment (driven by action type)
 *   background[]   — what the crowd and background elements are doing (driven by emotion)
 *   micro_motion[] — subtle small movements attached to character body (driven by weather type)
 *
 * Design principle: snow drifting is caused by weather=snow, not by light_code=W1.
 * light_code is only used to DERIVE weather_type; it does not directly produce physics phrases.
 * This keeps physics semantically correct and independent of the lighting classification.
 *
 * The "interaction" layer is the most cinematically valuable: it describes what physically
 * touches what, what deforms, what reacts. This is what makes video feel real.
 */
final class PhysicsPlanner
{
    /** light_code → weather_type (semantic weather, not a lighting description) */
    private const LIGHT_TO_WEATHER = [
        'W1' => 'snow',        'W2' => 'clear_warm',   'G1' => 'golden_clear',
        'N1' => 'rain',        'N2' => 'moonlit',       'D1' => 'overcast',
        'S1' => 'indoor',      'S2' => 'indoor',        'C1' => 'indoor',  'C2' => 'indoor',
    ];

    /** weather_type → atmosphere[] */
    private const WEATHER_ATMOSPHERE = [
        'snow'        => ['light snow drifting across the field', 'snowflakes suspended in the stadium floodlights'],
        'rain'        => ['rain streaks cutting diagonally through the air', 'wet ground surface reflecting ambient light'],
        'clear_warm'  => ['warm air shimmering near bright outdoor surfaces'],
        'golden_clear'=> ['golden dust motes floating in angled afternoon light', 'long shadows extending from every figure'],
        'overcast'    => ['flat atmospheric haze diffused through grey overcast sky'],
        'moonlit'     => ['low ground mist rolling slowly across the field surface'],
        'indoor'      => [],
    ];

    /** weather_type → micro_motion[] (small character body effects from weather) */
    private const WEATHER_MICRO_MOTION = [
        'snow'        => ['cold breath vapor visible on each exhale', 'jersey fabric moves with body momentum in the cold air'],
        'rain'        => ['uniform soaked and darkened, dripping from the helmet brim', 'wet skin and fabric catching light'],
        'clear_warm'  => ['sweat beads catching warm stadium light', 'fabric moves fluidly with warm air currents'],
        'golden_clear'=> ['hair and loose fabric lift slightly in the afternoon breeze'],
        'overcast'    => ['fabric hangs heavy, no wind effect — still and deliberate'],
        'moonlit'     => ['uniform still damp from earlier conditions'],
        'indoor'      => [],
    ];

    /** action_type → interaction[] (physical contact physics — the most cinematic layer) */
    private const ACTION_INTERACTION = [
        'fb_throw' => [
            'football compresses into the quarterback\'s palm grip on the release',
            'plant foot cleats dig into the field surface for stability',
            'jersey stretches at the shoulder seam during full torso rotation',
        ],
        'fb_catch' => [
            'fingers close hard around the football on first contact',
            'body absorbs ball momentum — arms drawing it in tight',
            'plant foot cleats bite to redirect speed after the catch',
        ],
        'fb_run' => [
            'cleats bite hard into the turf with each driving stride',
            'forward body lean transfers explosive energy into every step',
        ],
        'fb_kick' => [
            'boot makes clean, solid contact at the center of the football',
            'ball visibly deforms momentarily on impact before leaving the foot',
            'plant foot presses into the turf to stabilize the full kick',
        ],
        'fb_celebrate' => [
            'teammates collide with full-force celebration contact',
            'hands clap and bodies connect in shared physical joy',
        ],
        'fb_tackle' => [
            'pads crack together with the full sound of the collision',
            'arms lock and wrap tightly around the ball carrier\'s body',
            'cleats tear strips of turf as both players drive to the ground',
        ],
        'fb_dive' => [
            'fingertips stretch to the absolute physical limit of reach',
            'body impacts the turf with controlled, absorbing force at full extension',
            'arms pull the ball in as landing momentum compresses the body',
        ],
        'bb_dunk' => [
            'hand envelops the rim as the ball is slammed forcefully through',
            'wrist snaps down and releases at the moment of full extension',
            'sneakers absorb landing impact — sound of the finish echoes',
        ],
        'bb_shoot' => [
            'fingertips apply the final release spin with a precise wrist snap',
            'full shooting extension reached at the apex of the jump',
        ],
        'bb_handle' => [
            'palm cups the ball through each dribble contact cycle',
            'crossover creates sharp ground contact and directional force change',
        ],
        'sc_goal' => [
            'boot strikes the ball with a clean, resonant thump of solid contact',
            'net deforms and bulges backward in a ripple from the ball impact',
            'turf kicks up from the plant foot as the body rotates through',
        ],
        'sc_save' => [
            'hands spread wide to maximize contact area on the ball',
            'body absorbs the impact with the ground after full-extension dive',
            'fingers either close tight or deflect with a precise redirecting angle',
        ],
        'drive_drift' => [
            'rear tires scrub hard against the road surface in a controlled slide',
            'steering wheel fights back with the full force of countersteer resistance',
            'car chassis visibly rolls and shifts laterally with the G-force load',
        ],
        'drive_launch' => [
            'tires bite hard into the surface at the launch point',
            'body presses back into the seat under maximum acceleration force',
            'suspension compresses under full power transfer to the ground',
        ],
        'generic_action' => [],
    ];

    /** emotion_code → background[] (crowd and background environment behavior) */
    private const EMO_BACKGROUND = [
        'POWER'  => ['crowd rising from seats as the play unfolds', 'flags and banners wave with the crowd energy'],
        'JOY'    => ['crowd celebrating with raised arms and wide smiles', 'noise rippling visibly through the stands in waves'],
        'EPIC'   => ['crowd as a vast sea of color and motion in the background', 'stadium lights sweeping over the crowd in scale'],
        'TENSE'  => ['crowd holding their collective breath in silence', 'stillness in the stands — even flags hang motionless'],
        'AWE'    => ['crowd looking skyward in a collective upward reaction', 'a visible wave of reaction moving through the stands'],
        'DRAMA'  => ['coaches on the sideline gesturing urgently', 'crowd murmur building into a growing roar'],
        'REVEAL' => ['crowd reacting slowly as the full scale of the moment becomes clear'],
        'CALM'   => ['crowd settled and attentive in the background — peaceful observation'],
        'HOOK'   => ['immediate crowd energy surging toward the play', 'noise and motion from the stands invading the shot'],
        'CRAFT'  => ['crowd watching with focused, appreciative attention'],
        'FEAR'   => ['eerie near-stillness — the crowd barely moves', 'only a distant, hushed murmur from the stands'],
    ];

    /**
     * Action physics_triggers → additional elements added to specific layers.
     * Crowd triggers handled by EMO_BACKGROUND — left empty here to avoid duplication.
     */
    private const TRIGGER_ADDITIONS = [
        'dust_spray'   => ['atmosphere' => 'dust spray from explosive lateral movement on dry ground'],
        'impact_dust'  => ['atmosphere' => 'dust cloud rising from the point of impact'],
        'impact_spray' => ['atmosphere' => 'turf and surface spray from high-force contact'],
        'turf_spray'   => ['atmosphere' => 'turf spray displaced by hard ground impact'],
        'diving_spray' => ['atmosphere' => 'surface material spray from full-extension dive'],
        'net_ripple'   => ['background' => 'net rippling in full waves from the ball impact'],
        'snow_spray'   => ['atmosphere' => 'snow spray displaced by sudden explosive movement'],
        'water_spray'  => ['atmosphere' => 'water spray from wet surface contact'],
        'tire_smoke'   => ['atmosphere' => 'tire smoke billowing from spinning rear wheels'],
        // Handled elsewhere — empty to prevent duplication
        'jersey_flutter' => [], 'breath_vapor' => [], 'crowd_anticipation' => [],
        'crowd_erupts' => [], 'crowd_reaction' => [], 'crowd_gasp' => [],
    ];

    /**
     * @return array{
     *   atmosphere: string[],
     *   interaction: string[],
     *   background: string[],
     *   micro_motion: string[],
     * }
     */
    public function plan(array $dsl, array $actionResult = []): array
    {
        $lightCode  = $dsl['light'] ?? '';
        $emoCode    = $dsl['emo']   ?? 'CRAFT';
        $actionType = $actionResult['action_type'] ?? 'generic_action';
        $triggers   = $actionResult['physics_triggers'] ?? [];

        // Derive semantic weather from light_code — not used directly
        $weatherType = self::LIGHT_TO_WEATHER[$lightCode] ?? 'clear_warm';

        $atmosphere   = self::WEATHER_ATMOSPHERE[$weatherType]  ?? [];
        $interaction  = self::ACTION_INTERACTION[$actionType]   ?? [];
        $background   = self::EMO_BACKGROUND[$emoCode]          ?? [];
        $microMotion  = self::WEATHER_MICRO_MOTION[$weatherType] ?? [];

        // Apply action-specific trigger additions
        foreach ($triggers as $trigger) {
            $add = self::TRIGGER_ADDITIONS[$trigger] ?? [];
            if (($add['atmosphere']   ?? '') !== '') $atmosphere[]  = $add['atmosphere'];
            if (($add['interaction']  ?? '') !== '') $interaction[] = $add['interaction'];
            if (($add['background']   ?? '') !== '') $background[]  = $add['background'];
            if (($add['micro_motion'] ?? '') !== '') $microMotion[] = $add['micro_motion'];
        }

        return [
            'atmosphere'  => $atmosphere,
            'interaction' => $interaction,
            'background'  => $background,
            'micro_motion'=> $microMotion,
        ];
    }
}

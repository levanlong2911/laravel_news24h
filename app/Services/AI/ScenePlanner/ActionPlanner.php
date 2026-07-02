<?php

namespace App\Services\AI\ScenePlanner;

/**
 * Rule-based: shot DSL → rich action plan object.
 *
 * Decomposes a single-sentence action into event-driven phases with
 * semantic metadata. The rich return object is a stable interface:
 * swapping this for a Claude-backed fallback (Sprint 3 edge cases) only
 * requires replacing plan() body — no changes to any downstream planner.
 *
 * camera_beats[]  replaced camera_suggestions[]  (Sprint 3 upgrade):
 *   Each beat has a time (0.0–1.0 fraction of clip), a weight (priority),
 *   and a move code. MotionPlanner uses these to place camera descriptions
 *   at cinematically correct moments instead of guessing a fixed 60% peak.
 *
 *   Special move codes:
 *     ESTABLISH — use CAM_ESTABLISH lookup (first beat only, time 0.0)
 *     FOLLOW    — "camera follows [context]" description
 *
 *   All other move codes map to DSL move codes (P1, P2, D1, D2, O1, O2, H1, T1, T2, STATIC)
 *   and use MotionPlanner's MOVE_PEAKS lookup table for the description.
 */
final class ActionPlanner
{
    /** Keyword → template name. First match wins. */
    private const PATTERNS = [
        // ── Football ────────────────────────────────────────────────────────
        ['match' => ['throw', 'launch', 'deep pass', 'spiral', 'heave', 'fling', 'airs it out'],  'template' => 'fb_throw'],
        ['match' => ['catch', 'reception', 'grab', 'haul in', 'snag', 'reel in'],                 'template' => 'fb_catch'],
        ['match' => ['run', 'rush', 'scramble', 'sprint', 'dash', 'break', 'carry', 'truck'],     'template' => 'fb_run'],
        ['match' => ['kick', 'punt', 'field goal', 'boot', 'placekick'],                           'template' => 'fb_kick'],
        ['match' => ['score', 'touchdown', 'end zone', 'celebrate', 'spike', 'dance'],             'template' => 'fb_celebrate'],
        ['match' => ['sack', 'tackle', 'blitz', 'strip', 'hit', 'block'],                         'template' => 'fb_tackle'],
        ['match' => ['dive', 'leap', 'lunge', 'stretch', 'lay out', 'tip'],                       'template' => 'fb_dive'],
        // ── Basketball ──────────────────────────────────────────────────────
        ['match' => ['dunk', 'slam', 'jam', 'posterize'],                                          'template' => 'bb_dunk'],
        ['match' => ['shoot', 'three-point', 'jumper', 'pull-up', 'fadeaway'],                    'template' => 'bb_shoot'],
        ['match' => ['crossover', 'dribble', 'handles', 'behind the back'],                       'template' => 'bb_handle'],
        // ── Soccer ──────────────────────────────────────────────────────────
        ['match' => ['goal', 'strike', 'volley', 'header', 'bicycle kick'],                       'template' => 'sc_goal'],
        ['match' => ['save', 'goalkeeper', 'dive', 'stop'],                                       'template' => 'sc_save'],
        // ── Driving ─────────────────────────────────────────────────────────
        ['match' => ['drift', 'oversteer', 'slide', 'counter steer'],                             'template' => 'drive_drift'],
        ['match' => ['accelerate', 'burnout', 'full throttle', 'launch control'],                 'template' => 'drive_launch'],
        // ── Generic ─────────────────────────────────────────────────────────
        ['match' => [], 'template' => 'generic_action'],
    ];

    /**
     * Templates: phases (timing + subject description), physics_triggers (hints to PhysicsPlanner),
     * camera_beats (timed camera choreography hints to MotionPlanner).
     *
     * Phase ratios must sum to 1.0 per template.
     * camera_beats time values are 0.0–1.0 fractions of total clip duration.
     * camera_beats weight: 1.0 = always apply; lower = apply if no conflict.
     */
    private const TEMPLATES = [
        'fb_throw' => [
            'phases' => [
                ['ratio' => 0.12, 'subject' => 'surveys the field from the pocket'],
                ['ratio' => 0.20, 'subject' => 'steps up in the pocket, eyes downfield'],
                ['ratio' => 0.18, 'subject' => 'plants right foot, weight shifts forward'],
                ['ratio' => 0.15, 'subject' => 'explosive torso rotation, arm cocks back'],
                ['ratio' => 0.08, 'subject' => 'ball released with full arm extension'],
                ['ratio' => 0.27, 'subject' => 'full follow-through, eyes track ball downfield'],
            ],
            'camera_beats'     => [
                ['time' => 0.00, 'weight' => 1.0, 'move' => 'ESTABLISH'],
                ['time' => 0.62, 'weight' => 0.9, 'move' => 'P1'],
                ['time' => 0.95, 'weight' => 0.8, 'move' => 'FOLLOW', 'context' => 'ball arc downfield'],
            ],
            'physics_triggers' => ['jersey_flutter', 'breath_vapor', 'crowd_anticipation'],
        ],
        'fb_catch' => [
            'phases' => [
                ['ratio' => 0.20, 'subject' => 'runs precise route, scanning for the ball'],
                ['ratio' => 0.25, 'subject' => 'breaks into open space, separation from defender'],
                ['ratio' => 0.20, 'subject' => 'hands reach up, eyes locked on the incoming ball'],
                ['ratio' => 0.20, 'subject' => 'secures the catch, tucks ball against body'],
                ['ratio' => 0.15, 'subject' => 'accelerates upfield with the ball secured'],
            ],
            'camera_beats'     => [
                ['time' => 0.00, 'weight' => 1.0, 'move' => 'ESTABLISH'],
                ['time' => 0.45, 'weight' => 0.7, 'move' => 'P2'],
                ['time' => 0.80, 'weight' => 0.9, 'move' => 'P1'],
            ],
            'physics_triggers' => ['jersey_flutter', 'crowd_erupts'],
        ],
        'fb_run' => [
            'phases' => [
                ['ratio' => 0.15, 'subject' => 'reads the blocking scheme, identifies the gap'],
                ['ratio' => 0.20, 'subject' => 'bursts through the gap with explosive first step'],
                ['ratio' => 0.30, 'subject' => 'sprints in open field at maximum velocity'],
                ['ratio' => 0.20, 'subject' => 'cuts and accelerates past the last defender'],
                ['ratio' => 0.15, 'subject' => 'drives forward, fighting for every yard'],
            ],
            'camera_beats'     => [
                ['time' => 0.00, 'weight' => 1.0, 'move' => 'ESTABLISH'],
                ['time' => 0.35, 'weight' => 0.7, 'move' => 'D1'],
                ['time' => 0.80, 'weight' => 0.9, 'move' => 'P1'],
            ],
            'physics_triggers' => ['dust_spray', 'jersey_flutter'],
        ],
        'fb_kick' => [
            'phases' => [
                ['ratio' => 0.20, 'subject' => 'approaches the ball with measured, deliberate steps'],
                ['ratio' => 0.15, 'subject' => 'plants non-kicking foot precisely beside the ball'],
                ['ratio' => 0.15, 'subject' => 'explosive hip rotation through the point of contact'],
                ['ratio' => 0.10, 'subject' => 'clean contact — ball leaves the foot'],
                ['ratio' => 0.40, 'subject' => 'full follow-through, watches ball arc toward the uprights'],
            ],
            'camera_beats'     => [
                ['time' => 0.00, 'weight' => 1.0, 'move' => 'ESTABLISH'],
                ['time' => 0.45, 'weight' => 0.9, 'move' => 'P1'],
                ['time' => 0.62, 'weight' => 0.8, 'move' => 'T1'],
            ],
            'physics_triggers' => ['breath_vapor', 'crowd_anticipation'],
        ],
        'fb_celebrate' => [
            'phases' => [
                ['ratio' => 0.15, 'subject' => 'crosses the goal line — realization of the score'],
                ['ratio' => 0.25, 'subject' => 'raises both arms in triumph, faces the crowd'],
                ['ratio' => 0.25, 'subject' => 'teammates converge, pile on for the celebration'],
                ['ratio' => 0.20, 'subject' => 'points to the crowd, soaking in the moment'],
                ['ratio' => 0.15, 'subject' => 'holds the celebration pose, crowd energy peaks'],
            ],
            'camera_beats'     => [
                ['time' => 0.00, 'weight' => 1.0, 'move' => 'ESTABLISH'],
                ['time' => 0.40, 'weight' => 0.7, 'move' => 'P2'],
                ['time' => 0.85, 'weight' => 0.8, 'move' => 'O1'],
            ],
            'physics_triggers' => ['crowd_erupts', 'jersey_flutter'],
        ],
        'fb_tackle' => [
            'phases' => [
                ['ratio' => 0.20, 'subject' => 'reads the play, locks onto the ball carrier'],
                ['ratio' => 0.20, 'subject' => 'drives hard through the line with explosive burst'],
                ['ratio' => 0.20, 'subject' => 'launches into the tackle, arms wrapping tight'],
                ['ratio' => 0.25, 'subject' => 'full-force contact — drives ball carrier to the ground'],
                ['ratio' => 0.15, 'subject' => 'secures the tackle, stands over the downed player'],
            ],
            'camera_beats'     => [
                ['time' => 0.00, 'weight' => 1.0, 'move' => 'ESTABLISH'],
                ['time' => 0.60, 'weight' => 0.9, 'move' => 'H1'],
                ['time' => 0.90, 'weight' => 0.7, 'move' => 'P2'],
            ],
            'physics_triggers' => ['impact_dust', 'jersey_flutter', 'crowd_reaction'],
        ],
        'fb_dive' => [
            'phases' => [
                ['ratio' => 0.20, 'subject' => 'reads the trajectory, commits to the dive'],
                ['ratio' => 0.20, 'subject' => 'explosive push-off, body fully extended'],
                ['ratio' => 0.20, 'subject' => 'airborne, fully stretched toward the target'],
                ['ratio' => 0.25, 'subject' => 'makes contact at the extreme reach of the dive'],
                ['ratio' => 0.15, 'subject' => 'lands and secures — immediate reaction to the play'],
            ],
            'camera_beats'     => [
                ['time' => 0.00, 'weight' => 1.0, 'move' => 'ESTABLISH'],
                ['time' => 0.55, 'weight' => 0.8, 'move' => 'T2'],
                ['time' => 0.85, 'weight' => 0.9, 'move' => 'P1'],
            ],
            'physics_triggers' => ['impact_spray', 'turf_spray', 'crowd_gasp'],
        ],
        'bb_dunk' => [
            'phases' => [
                ['ratio' => 0.15, 'subject' => 'receives the alley-oop pass, times the approach'],
                ['ratio' => 0.25, 'subject' => 'two-step gather, coiling power in the legs'],
                ['ratio' => 0.20, 'subject' => 'launches off one foot, rising above the defense'],
                ['ratio' => 0.15, 'subject' => 'reaches apex, arm extends through the rim'],
                ['ratio' => 0.25, 'subject' => 'thunderous finish — hangs on the rim, crowd explodes'],
            ],
            'camera_beats'     => [
                ['time' => 0.00, 'weight' => 1.0, 'move' => 'ESTABLISH'],
                ['time' => 0.65, 'weight' => 0.9, 'move' => 'T1'],
                ['time' => 0.90, 'weight' => 0.8, 'move' => 'P1'],
            ],
            'physics_triggers' => ['crowd_erupts', 'jersey_flutter'],
        ],
        'bb_shoot' => [
            'phases' => [
                ['ratio' => 0.20, 'subject' => 'creates separation off the dribble'],
                ['ratio' => 0.20, 'subject' => 'elevates into the shooting motion'],
                ['ratio' => 0.20, 'subject' => 'reaches the peak of the jump, set point'],
                ['ratio' => 0.15, 'subject' => 'releases the ball with perfect arc'],
                ['ratio' => 0.25, 'subject' => 'watches the ball on its path — swishes through'],
            ],
            'camera_beats'     => [
                ['time' => 0.00, 'weight' => 1.0, 'move' => 'ESTABLISH'],
                ['time' => 0.70, 'weight' => 0.9, 'move' => 'T1'],
                ['time' => 0.95, 'weight' => 0.7, 'move' => 'FOLLOW', 'context' => 'ball arc toward the basket'],
            ],
            'physics_triggers' => ['crowd_anticipation'],
        ],
        'bb_handle' => [
            'phases' => [
                ['ratio' => 0.20, 'subject' => 'attacks the defender with controlled dribble'],
                ['ratio' => 0.30, 'subject' => 'explosive crossover, changing direction sharply'],
                ['ratio' => 0.25, 'subject' => 'blows by the defender into open space'],
                ['ratio' => 0.25, 'subject' => 'finishes at the rim or kicks to open teammate'],
            ],
            'camera_beats'     => [
                ['time' => 0.00, 'weight' => 1.0, 'move' => 'ESTABLISH'],
                ['time' => 0.50, 'weight' => 0.8, 'move' => 'D1'],
                ['time' => 0.85, 'weight' => 0.9, 'move' => 'P1'],
            ],
            'physics_triggers' => ['dust_spray', 'jersey_flutter'],
        ],
        'sc_goal' => [
            'phases' => [
                ['ratio' => 0.20, 'subject' => 'receives the ball in stride, one touch to control'],
                ['ratio' => 0.20, 'subject' => 'cuts toward goal, finding the angle'],
                ['ratio' => 0.20, 'subject' => 'plants foot, winds up for the strike'],
                ['ratio' => 0.15, 'subject' => 'clean contact — ball driven toward goal'],
                ['ratio' => 0.25, 'subject' => 'ball hits the back of the net — eruption of emotion'],
            ],
            'camera_beats'     => [
                ['time' => 0.00, 'weight' => 1.0, 'move' => 'ESTABLISH'],
                ['time' => 0.55, 'weight' => 0.9, 'move' => 'P1'],
                ['time' => 0.80, 'weight' => 0.8, 'move' => 'FOLLOW', 'context' => 'ball to the back of the net'],
            ],
            'physics_triggers' => ['crowd_erupts', 'net_ripple'],
        ],
        'sc_save' => [
            'phases' => [
                ['ratio' => 0.20, 'subject' => 'reads the shot direction, sets position'],
                ['ratio' => 0.20, 'subject' => 'explosive launch to the correct side'],
                ['ratio' => 0.25, 'subject' => 'fully extended dive, hand reaching the ball'],
                ['ratio' => 0.20, 'subject' => 'deflects or catches — save made'],
                ['ratio' => 0.15, 'subject' => 'lands, immediately back on feet — commanding'],
            ],
            'camera_beats'     => [
                ['time' => 0.00, 'weight' => 1.0, 'move' => 'ESTABLISH'],
                ['time' => 0.45, 'weight' => 0.8, 'move' => 'T2'],
                ['time' => 0.85, 'weight' => 0.9, 'move' => 'P1'],
            ],
            'physics_triggers' => ['diving_spray', 'crowd_gasp'],
        ],
        'drive_drift' => [
            'phases' => [
                ['ratio' => 0.15, 'subject' => 'approaches the corner at speed, foot on throttle'],
                ['ratio' => 0.20, 'subject' => 'flicks the wheel — rear wheels break loose'],
                ['ratio' => 0.25, 'subject' => 'car slides sideways, smoke pouring from rear tires'],
                ['ratio' => 0.25, 'subject' => 'counter-steers to hold the angle through the corner'],
                ['ratio' => 0.15, 'subject' => 'straightens, throttle exits — clean line out'],
            ],
            'camera_beats'     => [
                ['time' => 0.00, 'weight' => 1.0, 'move' => 'ESTABLISH'],
                ['time' => 0.50, 'weight' => 0.9, 'move' => 'O1'],
                ['time' => 0.85, 'weight' => 0.7, 'move' => 'D1'],
            ],
            'physics_triggers' => ['tire_smoke', 'dust_spray'],
        ],
        'drive_launch' => [
            'phases' => [
                ['ratio' => 0.10, 'subject' => 'engine revs to the limiter — holding at the line'],
                ['ratio' => 0.20, 'subject' => 'clutch drops — tires bite — car launches'],
                ['ratio' => 0.35, 'subject' => 'all four wheels driving hard, car pulls away'],
                ['ratio' => 0.35, 'subject' => 'full acceleration — gap opens rapidly'],
            ],
            'camera_beats'     => [
                ['time' => 0.00, 'weight' => 1.0, 'move' => 'ESTABLISH'],
                ['time' => 0.30, 'weight' => 0.8, 'move' => 'P2'],
                ['time' => 0.85, 'weight' => 0.9, 'move' => 'FOLLOW', 'context' => 'car receding into the distance'],
            ],
            'physics_triggers' => ['tire_smoke', 'dust_spray'],
        ],
        'generic_action' => [
            'phases' => [
                ['ratio' => 0.15, 'subject' => 'reads the situation, prepares for the action'],
                ['ratio' => 0.25, 'subject' => 'builds into the primary action with intent'],
                ['ratio' => 0.30, 'subject' => 'executes with full intensity at peak moment'],
                ['ratio' => 0.30, 'subject' => 'completes the action, follow-through resolves'],
            ],
            'camera_beats'     => [
                ['time' => 0.00, 'weight' => 1.0, 'move' => 'ESTABLISH'],
                ['time' => 0.65, 'weight' => 0.9, 'move' => 'P1'],
            ],
            'physics_triggers' => [],
        ],
    ];

    /** Action type → object carried/used by the subject */
    private const ACTION_OBJECT = [
        'fb_throw' => 'football', 'fb_catch' => 'football', 'fb_run' => 'football',
        'fb_kick' => 'football',  'fb_celebrate' => '', 'fb_tackle' => '', 'fb_dive' => '',
        'bb_dunk' => 'basketball', 'bb_shoot' => 'basketball', 'bb_handle' => 'basketball',
        'sc_goal' => 'ball', 'sc_save' => '', 'drive_drift' => '', 'drive_launch' => '',
        'generic_action' => '',
    ];

    /**
     * @return array{
     *   action_type: string,
     *   primary_actor: string,
     *   object_in_hand: string,
     *   timeline: array{start: float, end: float, subject: string}[],
     *   camera_beats: array{time: float, weight: float, move: string, context?: string}[],
     *   physics_triggers: string[],
     * }
     */
    public function plan(array $dsl): array
    {
        $action   = strtolower(trim($dsl['sub']['action'] ?? ''));
        $intent   = strtolower(trim($dsl['camera_goal'] ?? ''));
        $combined = $action . ' ' . $intent;

        $templateName = $this->detectTemplate($combined);
        $template     = self::TEMPLATES[$templateName];

        $dur      = (float) ($dsl['dur'] ?? 5.0);
        $klingDur = $dur <= 5.0 ? 5.0 : 10.0;

        return [
            'action_type'    => $templateName,
            'primary_actor'  => $dsl['sub']['actor'] ?? '',
            'object_in_hand' => self::ACTION_OBJECT[$templateName] ?? '',
            'timeline'       => $this->buildPhases($template['phases'], $klingDur),
            'camera_beats'   => $template['camera_beats'],
            'physics_triggers' => $template['physics_triggers'],
        ];
    }

    private function detectTemplate(string $text): string
    {
        foreach (self::PATTERNS as $pattern) {
            foreach ($pattern['match'] as $keyword) {
                if (str_contains($text, $keyword)) {
                    return $pattern['template'];
                }
            }
        }
        return 'generic_action';
    }

    /** @param  array{ratio: float, subject: string}[] $phases */
    private function buildPhases(array $phases, float $totalDur): array
    {
        $result = [];
        $t      = 0.0;
        $last   = count($phases) - 1;

        foreach ($phases as $i => $phase) {
            $end = ($i === $last)
                ? $totalDur
                : round($t + $phase['ratio'] * $totalDur, 2);

            $result[] = ['start' => $t, 'end' => $end, 'subject' => $phase['subject']];
            $t = $end;
        }

        return $result;
    }
}

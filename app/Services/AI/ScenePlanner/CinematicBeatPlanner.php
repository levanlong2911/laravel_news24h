<?php

namespace App\Services\AI\ScenePlanner;

/**
 * CinematicBeatPlanner — transforms a shot into a 4-beat cinematic arc.
 *
 * Replaces the flat "camera follows subject" timeline with a structured
 * Hook → Escalation → Reveal → Payoff narrative that drives viewer retention.
 *
 * Output feeds back into the timeline[] consumed by KlingRenderer ACTION section.
 * Each beat becomes a timed segment with camera directive + subject description.
 *
 * Pipeline position (Sprint 6+):
 *   ActionPlanner → MotionPlanner → CinematicBeatPlanner → override timeline[]
 *
 * Subject category is detected from actionType (ActionPlanner output) first,
 * then keyword-matched against camera_goal + actor text as fallback.
 */
final class CinematicBeatPlanner
{
    // ── Keyword tables for subject category detection ─────────────────────────

    private const AERIAL_KEYWORDS = [
        'yacht', 'superyacht', 'ship', 'vessel', 'boat', 'aircraft', 'airplane',
        'plane', 'helicopter', 'rocket', 'drone', 'spaceship', 'satellite',
        'jet', 'fighter jet', 'formula', 'supercar', 'race car',
    ];

    private const ATHLETIC_KEYWORDS = [
        'quarterback', 'athlete', 'runner', 'sprinter', 'gymnast', 'soccer',
        'football player', 'basketball player', 'tennis', 'swimmer', 'diver',
        'skater', 'fighter', 'boxer', 'martial artist', 'cyclist', 'player',
    ];

    private const NATURE_KEYWORDS = [
        'mountain', 'glacier', 'volcano', 'canyon', 'waterfall', 'forest',
        'desert', 'jungle', 'cliff', 'coastline', 'river', 'lake',
        'landscape', 'aurora', 'storm', 'lightning',
    ];

    private const PRODUCT_KEYWORDS = [
        'watch', 'jewelry', 'jewellery', 'coffee', 'food', 'wine', 'drink',
        'perfume', 'machine', 'robot', 'weapon', 'clock', 'product',
    ];

    // ── Beat timings ──────────────────────────────────────────────────────────

    private const TIMINGS_5S = [
        ['beat' => 'hook',       'start' => 0.0, 'end' => 0.8],
        ['beat' => 'escalation', 'start' => 0.8, 'end' => 2.0],
        ['beat' => 'reveal',     'start' => 2.0, 'end' => 3.5],
        ['beat' => 'payoff',     'start' => 3.5, 'end' => 5.0],
    ];

    private const TIMINGS_10S = [
        ['beat' => 'hook',       'start' => 0.0, 'end' => 1.0],
        ['beat' => 'escalation', 'start' => 1.0, 'end' => 3.5],
        ['beat' => 'reveal',     'start' => 3.5, 'end' => 6.5],
        ['beat' => 'payoff',     'start' => 6.5, 'end' => 9.0],
        ['beat' => 'resolution', 'start' => 9.0, 'end' => 10.0],
    ];

    // ── Beat template tables ──────────────────────────────────────────────────
    // {actor} is replaced at runtime. intensity: float 0–1.

    /** Aerial/vehicle subject in awe, epic, reveal, calm emotions. */
    private const TPL_AERIAL_AWE = [
        'hook' => [
            'camera'    => 'Drone dives from cloud cover at extreme velocity — altitude shock registers immediately',
            'subject'   => '{actor} appears below as drone breaks through cloud layer',
            'intensity' => 1.0,
        ],
        'escalation' => [
            'camera'    => 'Hard 60-degree banking turn — sun flare slices across subject at full force',
            'subject'   => '{actor} banking reveal as golden light traces its full length',
            'intensity' => 0.9,
        ],
        'reveal' => [
            'camera'    => 'Slow close orbit at surface height — capturing fine detail in intimate proximity',
            'subject'   => '{actor} surface texture, defining lines, and wake trail fully visible at eye-level',
            'intensity' => 0.7,
        ],
        'payoff' => [
            'camera'    => 'Camera pulls back 300m — subject shrinks against the vast surrounding environment',
            'subject'   => '{actor} becomes a point of beauty against infinite horizon — scale overwhelms',
            'intensity' => 1.0,
        ],
        'resolution' => [
            'camera'    => 'Camera holds wide — scene breathes and settles into final composition',
            'subject'   => '{actor} rests in natural environment — scene complete',
            'intensity' => 0.5,
        ],
    ];

    /** Aerial/vehicle subject in power, hook, drama, tense emotions. */
    private const TPL_AERIAL_POWER = [
        'hook' => [
            'camera'    => 'Camera drops fast targeting {actor} — speed forces immediate visual lock',
            'subject'   => '{actor} fills frame instantly — velocity and power telegraphed from first frame',
            'intensity' => 1.0,
        ],
        'escalation' => [
            'camera'    => 'Aggressive low-altitude tracking pursuit — camera matches subject velocity',
            'subject'   => '{actor} at full power — wake, exhaust, and turbulence trailing visibly',
            'intensity' => 1.0,
        ],
        'reveal' => [
            'camera'    => 'Close tracking — performance detail captured at peak velocity',
            'subject'   => '{actor} at maximum output — defining feature dominates frame',
            'intensity' => 0.9,
        ],
        'payoff' => [
            'camera'    => 'Wide pull-back reveal — full environmental scale surrounds {actor}',
            'subject'   => '{actor} remains dominant despite widening frame — power contextualized in scale',
            'intensity' => 1.0,
        ],
        'resolution' => [
            'camera'    => 'Camera holds wide on {actor} as momentum carries through frame',
            'subject'   => '{actor} completes pass through frame — energy dissipates into environment',
            'intensity' => 0.6,
        ],
    ];

    /** Athletic / sports subject — always high energy regardless of emotion. */
    private const TPL_ATHLETIC = [
        'hook' => [
            'camera'    => 'Snap zoom locks on athlete\'s eyes — pure focus and locked-in intent',
            'subject'   => '{actor} surveys the situation with total concentration — no hesitation',
            'intensity' => 1.0,
        ],
        'escalation' => [
            'camera'    => 'Aggressive handheld push-in — camera closes as action begins with urgency',
            'subject'   => '{actor} weight shifts and body coils — kinetic energy loading for release',
            'intensity' => 0.95,
        ],
        'reveal' => [
            'camera'    => 'Camera holds at peak action moment — zenith of the movement captured',
            'subject'   => '{actor} at maximum extension — peak performance frozen at decisive instant',
            'intensity' => 1.0,
        ],
        'payoff' => [
            'camera'    => 'Wide reveal — full environmental scale and crowd reaction enter frame',
            'subject'   => '{actor}\'s action plays out against environmental scale — full impact visible',
            'intensity' => 0.9,
        ],
        'resolution' => [
            'camera'    => 'Camera settles on wide — environment reacts and crowd energy dissipates',
            'subject'   => '{actor} completes motion — scene reacts to what just happened',
            'intensity' => 0.5,
        ],
    ];

    /** Landscape / nature subject. */
    private const TPL_NATURE = [
        'hook' => [
            'camera'    => 'Camera emerges from mist or cloud cover — immediate altitude revelation',
            'subject'   => '{actor} appears below — scale shock is instant and overwhelming',
            'intensity' => 1.0,
        ],
        'escalation' => [
            'camera'    => 'Sweeping low-altitude pass — camera skims terrain at close range',
            'subject'   => '{actor} texture and geological features revealed in intimate close detail',
            'intensity' => 0.85,
        ],
        'reveal' => [
            'camera'    => 'Camera hovers at signature focal point — iconic feature framed with precision',
            'subject'   => '{actor} most defining element fills frame — peak natural beauty',
            'intensity' => 0.75,
        ],
        'payoff' => [
            'camera'    => 'Pull back to reveal cosmic scale — {actor} placed in its true context',
            'subject'   => '{actor} shrinks as surrounding landscape overwhelms with pure scale',
            'intensity' => 1.0,
        ],
        'resolution' => [
            'camera'    => 'Camera holds wide and still — landscape breathes into final frame',
            'subject'   => '{actor} rests in full context — natural and timeless',
            'intensity' => 0.4,
        ],
    ];

    /** Product / craft subject. */
    private const TPL_PRODUCT = [
        'hook' => [
            'camera'    => 'Camera drops to surface level — extreme macro reveals material texture',
            'subject'   => '{actor} fine detail fills frame — precision and craftsmanship impossible to ignore',
            'intensity' => 0.9,
        ],
        'escalation' => [
            'camera'    => 'Slow 360-degree orbit — light traces across surfaces revealing form',
            'subject'   => '{actor} form revealed from all angles — every facet and edge visible',
            'intensity' => 0.8,
        ],
        'reveal' => [
            'camera'    => 'Beauty lighting interaction — light source engages with {actor} at peak angle',
            'subject'   => '{actor} at its most photogenic — light, form, and detail perfectly aligned',
            'intensity' => 0.85,
        ],
        'payoff' => [
            'camera'    => 'Pull back to full context — {actor} in its environment or use setting',
            'subject'   => '{actor} purpose and craftsmanship understood — scale and placement complete the frame',
            'intensity' => 0.9,
        ],
        'resolution' => [
            'camera'    => 'Final beauty shot — camera holds on finished {actor}',
            'subject'   => '{actor} at rest — final composition complete',
            'intensity' => 0.5,
        ],
    ];

    /** Generic fallback when no category matches. */
    private const TPL_GENERIC = [
        'hook' => [
            'camera'    => 'Camera opens on immediate visual impact — no slow establish',
            'subject'   => '{actor} commands attention from the first frame',
            'intensity' => 0.9,
        ],
        'escalation' => [
            'camera'    => 'Camera builds toward subject — energy and proximity increase steadily',
            'subject'   => '{actor} action begins — motion and intent become fully visible',
            'intensity' => 0.85,
        ],
        'reveal' => [
            'camera'    => 'Peak clarity — subject fully revealed at optimal framing',
            'subject'   => '{actor} at maximum expression — key moment captured precisely',
            'intensity' => 0.9,
        ],
        'payoff' => [
            'camera'    => 'Resolution frame — memorable final composition locks in',
            'subject'   => '{actor} completes the arc — scene settles into strong closing image',
            'intensity' => 0.95,
        ],
        'resolution' => [
            'camera'    => 'Camera holds — scene breathes into final frame',
            'subject'   => '{actor} at rest — scene complete',
            'intensity' => 0.4,
        ],
    ];

    // ─────────────────────────────────────────────────────────────────────────

    /**
     * @param  array $dsl          Full shot DSL (emo, cam, dur, sub, camera_goal, …)
     * @param  array $actionResult ActionPlanner::plan() result (action_type, …)
     * @return array               {beats[], arc, duration_sec, category}
     */
    public function plan(array $dsl, array $actionResult = []): array
    {
        $duration   = (float) ($dsl['dur'] ?? 5.0);
        $emoCode    = strtolower($dsl['emo'] ?? 'craft');
        $actionType = $actionResult['action_type'] ?? 'generic_action';
        $searchText = strtolower(
            ($dsl['camera_goal'] ?? '') . ' ' . ($dsl['sub']['actor'] ?? '')
        );

        $actor     = $dsl['sub']['actor'] ?? 'subject';
        $category  = $this->detectCategory($actionType, $searchText);
        $timings   = $duration > 5.0 ? self::TIMINGS_10S : self::TIMINGS_5S;
        $templates = $this->resolveTemplates($category, $emoCode);

        $beats = [];
        foreach ($timings as $timing) {
            $tpl = $templates[$timing['beat']] ?? self::TPL_GENERIC[$timing['beat']];
            $beats[] = [
                'beat'      => $timing['beat'],
                'start'     => $timing['start'],
                'end'       => $timing['end'],
                'camera'    => str_replace('{actor}', $actor, $tpl['camera']),
                'subject'   => str_replace('{actor}', $actor, $tpl['subject']),
                'intensity' => $tpl['intensity'],
            ];
        }

        return [
            'beats'        => $beats,
            'arc'          => count($beats) === 4
                ? 'hook_escalation_reveal_payoff'
                : 'hook_escalation_reveal_payoff_resolution',
            'duration_sec' => $duration,
            'category'     => $category,
        ];
    }

    // ── Private helpers ───────────────────────────────────────────────────────

    private function detectCategory(string $actionType, string $text): string
    {
        // ActionPlanner output is authoritative — check first
        if (in_array($actionType, ['vehicle_flight', 'vehicle_race'], true)) {
            return 'aerial_vehicle';
        }
        if (str_starts_with($actionType, 'fb_') || str_starts_with($actionType, 'bball_')) {
            return 'athletic_action';
        }
        if ($actionType === 'craft_precision') {
            return 'product_craft';
        }

        // Keyword fallback on free text
        foreach (self::AERIAL_KEYWORDS as $kw) {
            if (str_contains($text, $kw)) {
                return 'aerial_vehicle';
            }
        }
        foreach (self::ATHLETIC_KEYWORDS as $kw) {
            if (str_contains($text, $kw)) {
                return 'athletic_action';
            }
        }
        foreach (self::NATURE_KEYWORDS as $kw) {
            if (str_contains($text, $kw)) {
                return 'landscape_nature';
            }
        }
        foreach (self::PRODUCT_KEYWORDS as $kw) {
            if (str_contains($text, $kw)) {
                return 'product_craft';
            }
        }

        return 'generic';
    }

    private function resolveTemplates(string $category, string $emoCode): array
    {
        $isAwe = in_array($emoCode, ['awe', 'epic', 'reveal', 'calm', 'joy'], true);

        return match ($category) {
            'aerial_vehicle'  => $isAwe ? self::TPL_AERIAL_AWE : self::TPL_AERIAL_POWER,
            'athletic_action' => self::TPL_ATHLETIC,
            'landscape_nature'=> self::TPL_NATURE,
            'product_craft'   => self::TPL_PRODUCT,
            default           => self::TPL_GENERIC,
        };
    }
}

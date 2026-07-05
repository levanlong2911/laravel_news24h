<?php

namespace App\Services\AI\ScenePlanner;

/**
 * CuriosityPlanner — engineers information gaps that drive viewer retention.
 *
 * The brain cannot resist an open question: "what IS this?" and "what happens next?"
 * force continued attention until answered. This is the Hollywood "information gap"
 * principle. Without it, a 5-second clip is a description. With it, it is a story.
 *
 * Information arc:
 *   Hook (concealed)     → viewer sees motion and scale — but NOT identity
 *   Escalation (partial) → subject form emerges — pattern recognition activates
 *   Reveal (revealed)    → subject declared — CinematicBeatPlanner text used as-is
 *   Payoff (full)        → consequence and context — CinematicBeatPlanner text used as-is
 *
 * For concealed/partial beats, subject_override REPLACES the CinematicBeatPlanner
 * subject text. This avoids contradiction (CinematicBeat says "Yacht appears"
 * while curiosity says "identity withheld"). ScenePlanner::injectCuriosityLayer()
 * applies these overrides to the beat timeline before PhaseNode creation.
 */
final class CuriosityPlanner
{
    private const QUESTIONS = [
        'aerial_vehicle'   => 'What is moving through this environment — and why does it command this scale?',
        'athletic_action'  => 'What is this athlete about to do — and can they actually pull it off?',
        'landscape_nature' => 'Where is this — and how vast does it actually become?',
        'product_craft'    => 'What is this object — and what makes it deserve this much attention?',
        'generic'          => 'What is happening here — and what does it mean?',
    ];

    /**
     * Per-category subject override text for concealed and partial beats.
     * Null at 'revealed'/'full' = keep CinematicBeatPlanner subject text unchanged.
     */
    private const SUBJECT_OVERRIDES = [
        'aerial_vehicle' => [
            'concealed' => 'Object motion registers below — identity withheld — only velocity and scale visible through the obscuring layer',
            'partial'   => 'Vessel silhouette emerging through atmosphere — hull form and scale becoming legible',
            'revealed'  => null,
            'full'      => null,
        ],
        'athletic_action' => [
            'concealed' => 'Athlete eyes locked — body language and intensity before any action context is given',
            'partial'   => 'Athletic form becoming readable — power and intention loading in the pre-action phase',
            'revealed'  => null,
            'full'      => null,
        ],
        'landscape_nature' => [
            'concealed' => 'Scale without reference — geological form without name — environment withholds its identity',
            'partial'   => 'Natural structure becoming identifiable — scale and form resolving through the descending altitude',
            'revealed'  => null,
            'full'      => null,
        ],
        'product_craft' => [
            'concealed' => 'Material texture fills frame — object form and function withheld — only surface exists',
            'partial'   => 'Object form emerging at detail level — precision and craftsmanship readable before function is declared',
            'revealed'  => null,
            'full'      => null,
        ],
        'generic' => [
            'concealed' => 'Subject presence registers before declaration — scale and motion without context',
            'partial'   => 'Subject pattern emerging — identity approaching but not yet confirmed',
            'revealed'  => null,
            'full'      => null,
        ],
    ];

    private const STATES_4 = [
        'hook'       => 'concealed',
        'escalation' => 'partial',
        'reveal'     => 'revealed',
        'payoff'     => 'full',
    ];

    private const STATES_5 = [
        'hook'       => 'concealed',
        'escalation' => 'partial',
        'reveal'     => 'revealed',
        'payoff'     => 'full',
        'resolution' => 'full',
    ];

    /**
     * @param  string $category Subject category from CinematicBeatPlan
     * @param  array  $beats    CinematicBeatPlan::beats (each entry has 'beat' key)
     * @return array            {primary_question, pattern, category, beat_states{}}
     */
    public function plan(string $category, array $beats): array
    {
        $is5Beat   = count($beats) === 5;
        $stateMap  = $is5Beat ? self::STATES_5 : self::STATES_4;
        $overrides = self::SUBJECT_OVERRIDES[$category] ?? self::SUBJECT_OVERRIDES['generic'];

        $beatStates = [];
        foreach ($beats as $beat) {
            $beatName = $beat['beat'] ?? '';
            $state    = $stateMap[$beatName] ?? 'full';

            $beatStates[$beatName] = [
                'state'           => $state,
                'subject_override' => $overrides[$state] ?? null,
            ];
        }

        return [
            'primary_question' => self::QUESTIONS[$category] ?? self::QUESTIONS['generic'],
            'pattern'          => 'concealed_to_full',
            'category'         => $category,
            'beat_states'      => $beatStates,
        ];
    }
}

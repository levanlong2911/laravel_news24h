<?php

namespace App\Services\AI\SceneShotPlanner;

use App\Services\AI\PromptCompiler\Libraries\ShotGrammarLibrary;

/**
 * Pure function: ShotPurpose + context → DSL array.
 * No AI cost. No side effects. No provider names (Rule 1).
 *
 * Output is a raw array compatible with ShotDTO::fromArray().
 */
final class DSLBuilder
{
    // ShotPurpose → compact DSL defaults
    // cam:   WIDE|MEDIUM|CLOSE|MACRO|ORBITAL|TRACKING|AERIAL|POV
    // lens:  24|35|50|85|135|200
    // light: W1|W2|G1|N1|N2|D1|S1|S2|C1|C2
    // move:  STATIC|P1|P2|D1|D2|O1|O2|H1|T1|T2
    private const PURPOSE_DSL = [
        ShotGrammarLibrary::HOOK       => ['cam' => 'TRACKING', 'lens' => '24',  'light' => 'D1', 'move' => 'T1'],
        ShotGrammarLibrary::ESTABLISH  => ['cam' => 'WIDE',     'lens' => '35',  'light' => 'W1', 'move' => 'D1'],
        ShotGrammarLibrary::PROCESS    => ['cam' => 'MEDIUM',   'lens' => '50',  'light' => 'W1', 'move' => 'P2'],
        ShotGrammarLibrary::DETAIL     => ['cam' => 'CLOSE',    'lens' => '85',  'light' => 'W1', 'move' => 'D1'],
        ShotGrammarLibrary::MACRO      => ['cam' => 'MACRO',    'lens' => '135', 'light' => 'W1', 'move' => 'STATIC'],
        ShotGrammarLibrary::TRANSITION => ['cam' => 'MEDIUM',   'lens' => '50',  'light' => 'D1', 'move' => 'T1'],
        ShotGrammarLibrary::EMOTION    => ['cam' => 'CLOSE',    'lens' => '85',  'light' => 'C1', 'move' => 'O1'],
        ShotGrammarLibrary::PAYOFF     => ['cam' => 'WIDE',     'lens' => '35',  'light' => 'W1', 'move' => 'D2'],
        ShotGrammarLibrary::CTA        => ['cam' => 'MEDIUM',   'lens' => '50',  'light' => 'W1', 'move' => 'STATIC'],
    ];

    // visual_priority → realism (AI render quality hint, picked up by ProviderResolver)
    private const PRIORITY_REALISM = [
        'HIGH'   => 'photoreal',
        'MEDIUM' => 'high',
        'LOW'    => 'medium',
    ];

    // Keyword → emo code (first match wins, case-insensitive substring)
    private const EMOTION_MAP = [
        'anticipat' => 'HOOK',
        'curiosit'  => 'HOOK',
        'hook'      => 'HOOK',
        'mystery'   => 'TENSE',
        'tension'   => 'TENSE',
        'tense'     => 'TENSE',
        'craft'     => 'CRAFT',
        'awe'       => 'AWE',
        'power'     => 'POWER',
        'calm'      => 'CALM',
        'drama'     => 'DRAMA',
        'joy'       => 'JOY',
        'fear'      => 'FEAR',
        'epic'      => 'EPIC',
        'reveal'    => 'REVEAL',
        'suspense'  => 'TENSE',
        'wonder'    => 'AWE',
    ];

    private const EMOTION_FALLBACK = 'CRAFT';

    // Human role keywords for has_human detection from subject string
    private const HUMAN_KEYWORDS = [
        'mechanic', 'engineer', 'rider', 'technician', 'craftsman',
        'welder', 'designer', 'inspector', 'person', 'human', 'worker', 'hand',
        'man', 'woman', 'operator', 'photographer',
    ];

    /**
     * Build a DSL array for one shot.
     *
     * @return array  Compatible with ShotDTO::fromArray() (without shot_order — caller assigns it)
     */
    public static function build(
        string $purpose,
        string $beatEmotion,
        string $visualPriority,
        string $motionLevel,
        float  $dur,
        string $visualIntent = '',
        string $subject      = '',
        string $action       = '',
    ): array {
        $base    = self::PURPOSE_DSL[$purpose] ?? self::PURPOSE_DSL[ShotGrammarLibrary::ESTABLISH];
        $realism = self::PRIORITY_REALISM[strtoupper($visualPriority)] ?? 'high';
        $emo     = self::mapEmotion($beatEmotion);
        $hasHuman = self::detectHuman($subject);

        return [
            'cam'          => $base['cam'],
            'lens'         => $base['lens'],
            'light'        => $base['light'],
            'move'         => $base['move'],
            'emo'          => $emo,
            'dur'          => max(0.5, round($dur, 2)),
            'motion_level' => $motionLevel,
            'realism'      => $realism,
            'has_human'    => $hasHuman,
            'camera_goal'  => $visualIntent,  // visual_intent reuses camera_goal field
            'sub'          => [
                'actor'  => $subject,
                'action' => $action,
                'obj'    => '',
            ],
        ];
    }

    private static function mapEmotion(string $emotion): string
    {
        $lower = strtolower($emotion);
        foreach (self::EMOTION_MAP as $keyword => $code) {
            if (str_contains($lower, $keyword)) {
                return $code;
            }
        }
        return self::EMOTION_FALLBACK;
    }

    private static function detectHuman(string $subject): bool
    {
        $lower = strtolower($subject);
        foreach (self::HUMAN_KEYWORDS as $keyword) {
            if (str_contains($lower, $keyword)) {
                return true;
            }
        }
        return false;
    }
}

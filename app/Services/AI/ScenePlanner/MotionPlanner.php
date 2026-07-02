<?php

namespace App\Services\AI\ScenePlanner;

/**
 * Rule-based: shot DSL + action result → camera choreography timeline.
 *
 * Sprint 3 upgrade: uses camera_beats[] from ActionPlanner instead of guessing
 * a fixed 60% peak. Each beat has a precise time fraction (0.0–1.0), weight,
 * and move code — the renderer no longer has to infer WHERE the camera moves.
 *
 * camera_beats time → phase mapping:
 *   Beat at time=0.62 maps to whichever phase contains 62% of the clip duration.
 *   Multiple beats in the same phase → highest weight wins.
 *   ESTABLISH beat (time=0.0) always maps to phase 0.
 *   FOLLOW beat → "camera follows [context]" description.
 *   Other move codes → MOVE_PEAKS lookup table.
 */
final class MotionPlanner
{
    /** Camera code → establishing description (always placed in phase 0) */
    private const CAM_ESTABLISH = [
        'AERIAL'   => 'Drone descends from high altitude, subject locked in frame',
        'TRACKING' => 'Camera begins tracking alongside the moving subject',
        'ORBITAL'  => 'Camera opens on a wide orbital sweep',
        'WIDE'     => 'Wide establishing frame — full scene visible',
        'MEDIUM'   => 'Camera holds medium distance, subject centered',
        'CLOSE'    => 'Camera moves into close framing on subject',
        'MACRO'    => 'Camera opens on extreme texture and fine detail',
        'POV'      => 'POV perspective — subject sightline established',
    ];

    /**
     * Move code → camera descriptions at beats, by motion_level.
     * peak    = camera action at the beat moment
     * resolve = camera settling after the beat (used when beat is near-final)
     */
    private const MOVE_PEAKS = [
        'high' => [
            'P1'     => ['peak' => 'aggressively closes distance, subject fills frame',      'resolve' => 'push-in completes, held tight on subject'],
            'P2'     => ['peak' => 'fast pull-back dramatically opens the frame',            'resolve' => 'wide reveal holds — full scale visible'],
            'D1'     => ['peak' => 'fast lateral sweep cuts right with energy',              'resolve' => 'frame settles after the sweep'],
            'D2'     => ['peak' => 'fast lateral sweep cuts left with energy',               'resolve' => 'frame settles after the sweep'],
            'O1'     => ['peak' => 'rips into fast clockwise arc around subject',            'resolve' => 'orbital arc resolves to stable frame'],
            'O2'     => ['peak' => 'rips into fast counterclockwise arc',                   'resolve' => 'orbital arc resolves'],
            'H1'     => ['peak' => 'urgent handheld shake matches peak action',             'resolve' => 'handheld energy subsides'],
            'T1'     => ['peak' => 'snaps upward fast, revealing overhead scale',           'resolve' => 'tilt apex holds'],
            'T2'     => ['peak' => 'drives hard downward onto subject',                     'resolve' => 'descent completes, tight on subject'],
            'STATIC' => ['peak' => 'locked frame holds maximum tension',                    'resolve' => 'held frame releases — scene breathes'],
        ],
        'medium' => [
            'P1'     => ['peak' => 'steadily pushes in, closing on the action',             'resolve' => 'push-in completes'],
            'P2'     => ['peak' => 'steadily pulls back to reveal context',                 'resolve' => 'wide frame holds'],
            'D1'     => ['peak' => 'lateral dolly right tracks the action',                 'resolve' => 'dolly settles'],
            'D2'     => ['peak' => 'lateral dolly left tracks the action',                  'resolve' => 'dolly settles'],
            'O1'     => ['peak' => 'sweeps clockwise in controlled arc',                    'resolve' => 'arc stabilizes'],
            'O2'     => ['peak' => 'sweeps counterclockwise in controlled arc',             'resolve' => 'arc stabilizes'],
            'H1'     => ['peak' => 'organic handheld motion tracks subject',                'resolve' => 'handheld settles with the scene'],
            'T1'     => ['peak' => 'tilts upward to reveal above',                          'resolve' => 'holds at apex'],
            'T2'     => ['peak' => 'tilts down to descend on subject',                      'resolve' => 'descent holds'],
            'STATIC' => ['peak' => 'locked frame holds the moment',                         'resolve' => 'static frame resolves'],
        ],
        'low' => [
            'P1'     => ['peak' => 'gently pushes in toward subject',                       'resolve' => 'gentle push completes'],
            'P2'     => ['peak' => 'slowly pulls back',                                     'resolve' => 'holds wide and still'],
            'D1'     => ['peak' => 'slow lateral drift right',                              'resolve' => 'drift settles'],
            'D2'     => ['peak' => 'slow lateral drift left',                               'resolve' => 'drift settles'],
            'O1'     => ['peak' => 'slow clockwise sweep',                                  'resolve' => 'arc completes gently'],
            'O2'     => ['peak' => 'slow counterclockwise sweep',                           'resolve' => 'arc completes gently'],
            'H1'     => ['peak' => 'subtle handheld breath movement',                       'resolve' => 'stills naturally'],
            'T1'     => ['peak' => 'gently tilts upward',                                   'resolve' => 'holds at apex'],
            'T2'     => ['peak' => 'gently tilts downward',                                 'resolve' => 'descent holds'],
            'STATIC' => ['peak' => 'locked frame holds still',                              'resolve' => 'locked frame holds through resolution'],
        ],
    ];

    /**
     * Overlay camera descriptions onto action phases using timed camera_beats.
     *
     * @param  array $dsl          Shot DSL
     * @param  array $actionResult Full result from ActionPlanner::plan()
     * @return array{start: float, end: float, subject: string, camera?: string}[]
     */
    public function plan(array $dsl, array $actionResult): array
    {
        $camCode     = $dsl['cam']          ?? 'MEDIUM';
        $motionLevel = $dsl['motion_level'] ?? 'medium';

        $actionPhases = $actionResult['timeline']     ?? [];
        $cameraBeats  = $actionResult['camera_beats'] ?? [];

        if (empty($actionPhases)) {
            return [];
        }

        $totalDur   = (float) (end($actionPhases)['end'] ?? 5.0);
        $levelMoves = self::MOVE_PEAKS[$motionLevel] ?? self::MOVE_PEAKS['medium'];

        // Map each beat to the phase index it falls within
        $phaseAssignments = $this->assignBeatsToPhases($cameraBeats, $actionPhases, $totalDur);

        $result = [];
        foreach ($actionPhases as $i => $phase) {
            $entry = $phase;

            if (isset($phaseAssignments[$i])) {
                $beat    = $phaseAssignments[$i];
                $moveCode = $beat['move'];

                if ($moveCode === 'ESTABLISH') {
                    $entry['camera'] = self::CAM_ESTABLISH[$camCode] ?? 'Camera establishes the shot';
                } elseif ($moveCode === 'FOLLOW') {
                    $context = $beat['context'] ?? 'subject';
                    $entry['camera'] = "Camera follows {$context}";
                } elseif (isset($levelMoves[$moveCode])) {
                    // Use 'resolve' description for beats that are >= 85% through the clip
                    $isNearEnd = $beat['time'] >= 0.85;
                    $entry['camera'] = $isNearEnd
                        ? $levelMoves[$moveCode]['resolve']
                        : $levelMoves[$moveCode]['peak'];
                }
            }

            $result[] = $entry;
        }

        return $result;
    }

    /**
     * Assign each camera beat to the action phase whose time range contains it.
     * When multiple beats fall in the same phase, the highest-weight beat wins.
     *
     * @return array<int, array> phaseIndex → winning beat
     */
    private function assignBeatsToPhases(array $cameraBeats, array $actionPhases, float $totalDur): array
    {
        $assignments = [];

        foreach ($cameraBeats as $beat) {
            $beatAbsolute = $beat['time'] * $totalDur;
            $weight       = $beat['weight'] ?? 0.5;

            foreach ($actionPhases as $i => $phase) {
                $phaseStart = $phase['start'];
                $phaseEnd   = $phase['end'];

                if ($beatAbsolute >= $phaseStart && $beatAbsolute <= $phaseEnd) {
                    if (!isset($assignments[$i]) || $weight > ($assignments[$i]['weight'] ?? 0)) {
                        $assignments[$i] = $beat + ['weight' => $weight];
                    }
                    break;
                }
            }
        }

        return $assignments;
    }
}

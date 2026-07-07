<?php

namespace App\Services\AI\Video;

/**
 * Maps extracted article scene data → Kling DSL codes for ScenePlanner::enrich().
 *
 * This is the only place that translates natural-language scene attributes
 * (time_of_day, weather, mood, camera) into the DSL codes that the
 * KlingRenderer pipeline understands.
 */
final class KlingSceneMapper
{
    /**
     * Maps article action_type to Kling action vocab.
     * Must match the keys in KlingRenderer::VISUAL_OUTCOME.
     */
    private const ACTION_TO_KLING = [
        'throw'     => 'throw',
        'pass'      => 'throw',
        'dunk'      => 'dunk',
        'kick'      => 'kick',
        'shoot'     => 'kick',
        'run'       => 'default',
        'sprint'    => 'default',
        'celebrate' => 'default',
        'tackle'    => 'default',
        'score'     => 'default',
        'block'     => 'default',
        'jump'      => 'default',
        'dive'      => 'default',
        'catch'     => 'default',
    ];

    /** Maps mood → emo DSL code */
    private const MOOD_TO_EMO = [
        'POWER' => 'POWER',
        'JOY'   => 'JOY',
        'EPIC'  => 'POWER',
        'TENSE' => 'TENSE',
        'AWE'   => 'AWE',
        'DRAMA' => 'DRAMA',
        'CALM'  => 'CALM',
    ];

    /** Maps camera type → cam DSL code */
    private const CAMERA_TO_CAM = [
        'CLOSE'    => 'CLOSE',
        'MEDIUM'   => 'MEDIUM',
        'WIDE'     => 'WIDE',
        'AERIAL'   => 'AERIAL',
        'TRACKING' => 'TRACKING',
    ];

    /** Maps camera type → 35mm lens equivalent */
    private const CAMERA_TO_LENS = [
        'CLOSE'    => '85',
        'MEDIUM'   => '50',
        'WIDE'     => '24',
        'AERIAL'   => '24',
        'TRACKING' => '50',
    ];

    /**
     * Maps "time_of_day:weather" → light DSL code.
     * N1/N2 = night variants; S1/S2 = stadium; W1/W2 = winter/rain; G1 = golden hour
     */
    private const LIGHT_MAP = [
        'night:clear'   => 'N2',
        'night:rain'    => 'N1',
        'night:snow'    => 'W1',
        'night:cloudy'  => 'N2',
        'day:clear'     => 'G1',
        'day:rain'      => 'N1',
        'day:snow'      => 'W2',
        'day:cloudy'    => 'S2',
        'indoor:clear'  => 'S1',
        'indoor:rain'   => 'S1',
        'indoor:snow'   => 'S1',
        'indoor:cloudy' => 'S1',
    ];

    /** Maps action_type → motion_level for ScenePlanner */
    private const ACTION_TO_MOTION = [
        'throw'     => 'high',
        'pass'      => 'high',
        'dunk'      => 'high',
        'kick'      => 'high',
        'shoot'     => 'high',
        'sprint'    => 'high',
        'run'       => 'medium',
        'tackle'    => 'high',
        'score'     => 'medium',
        'jump'      => 'high',
        'celebrate' => 'medium',
        'block'     => 'high',
        'dive'      => 'high',
        'catch'     => 'medium',
    ];

    /**
     * Convert one extracted article scene to a Kling DSL array.
     * Returns a DSL ready for ScenePlanner::enrich().
     */
    public function toDsl(array $scene, int $shotOrder = 1): array
    {
        $action  = strtolower($scene['action_type'] ?? 'run');
        $camera  = strtoupper($scene['camera']      ?? 'MEDIUM');
        $tod     = strtolower($scene['time_of_day'] ?? 'day');
        $weather = strtolower($scene['weather']     ?? 'clear');
        $mood    = strtoupper($scene['mood']        ?? 'POWER');
        $subject = trim($scene['subject']           ?? 'the athlete');
        $setting = trim($scene['setting']           ?? '');

        $lightKey = "{$tod}:{$weather}";

        return [
            'scene_title'  => $setting,
            'cam'          => self::CAMERA_TO_CAM[$camera]    ?? 'MEDIUM',
            'move'         => 'P1',
            'lens'         => self::CAMERA_TO_LENS[$camera]   ?? '85',
            'light'        => self::LIGHT_MAP[$lightKey]      ?? 'S2',
            'emo'          => self::MOOD_TO_EMO[$mood]        ?? 'POWER',
            'motion_level' => self::ACTION_TO_MOTION[$action] ?? 'medium',
            'dur'          => (float) ($scene['duration_seconds'] ?? 5),
            'shot_order'   => $shotOrder,
            'sub'          => [
                'actor'  => $subject,
                'action' => self::ACTION_TO_KLING[$action] ?? 'default',
                'obj'    => '',
            ],
        ];
    }
}

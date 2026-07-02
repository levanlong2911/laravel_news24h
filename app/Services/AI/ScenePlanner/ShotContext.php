<?php

namespace App\Services\AI\ScenePlanner;

use App\Services\AI\SceneGraph\Enums\CameraMove;
use App\Services\AI\SceneGraph\Enums\CameraType;
use App\Services\AI\SceneGraph\Enums\Emotion;
use App\Services\AI\SceneGraph\Enums\MotionLevel;

/**
 * Typed wrapper of the basic shot DSL metadata fields.
 *
 * Replaces `array $dsl` in ScenePlanningResult. Builder and Resolvers read
 * ONLY from ShotContext + typed plan objects — never from a raw DSL array.
 *
 * Shot-identity fields (shotId, sceneId, shotOrder) are derived here so the
 * rest of the pipeline never has to re-parse DSL strings.
 */
final class ShotContext
{
    public function __construct(
        public readonly string      $shotId,
        public readonly string      $sceneId,
        public readonly int         $shotOrder,
        public readonly float       $dur,
        /** DSL light code: W1, W2, G1, N1, N2, D1, S1, S2, C1, C2 */
        public readonly string      $lightCode,
        public readonly Emotion     $emotion,
        public readonly CameraType  $camType,
        public readonly CameraMove  $cameraMove,
        public readonly MotionLevel $motionLevel,
        /** Raw numeric lens code: 16, 24, 35, 50, 85, 135, 200 */
        public readonly string      $lensCode,
        public readonly string      $sceneTitle,
    ) {}

    public static function fromDsl(array $dsl): self
    {
        $sceneId   = $dsl['scene_id']   ?? 'unknown';
        $shotOrder = (int) ($dsl['shot_order'] ?? 1);

        return new self(
            shotId:      "{$sceneId}-sh{$shotOrder}",
            sceneId:     $sceneId,
            shotOrder:   $shotOrder,
            dur:         (float) ($dsl['dur']          ?? 5.0),
            lightCode:   $dsl['light']                 ?? '',
            emotion:     Emotion::fromDsl($dsl['emo']  ?? 'CRAFT'),
            camType:     CameraType::fromDsl($dsl['cam']   ?? 'MEDIUM'),
            cameraMove:  CameraMove::fromDsl($dsl['move']  ?? 'STATIC'),
            motionLevel: MotionLevel::fromString($dsl['motion_level'] ?? 'medium'),
            lensCode:    (string) ($dsl['lens']        ?? self::defaultLensCode($dsl)),
            sceneTitle:  $dsl['scene_title']           ?? '',
        );
    }

    /** @internal Called only during fromDsl() to resolve missing lens from cam type */
    private static function defaultLensCode(array $dsl): string
    {
        return CameraType::fromDsl($dsl['cam'] ?? 'MEDIUM')->defaultLensCode();
    }
}

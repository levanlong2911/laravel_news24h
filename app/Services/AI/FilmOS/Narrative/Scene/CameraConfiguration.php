<?php

declare(strict_types=1);

namespace App\Services\AI\FilmOS\Narrative\Scene;

/**
 * Semantic camera setup for a single shot.
 *
 * This is the aggregate root of the camera domain.
 * All fields map directly to prompt language — no render-engine coordinates.
 *
 * Example prompt output (PromptCompiler responsibility):
 *   CLOSE_UP + EYE_LEVEL + TRACKING + TELEPHOTO → "close-up 85mm tracking shot at eye level"
 *   ESTABLISHING + BIRDS_EYE + STATIC + WIDE     → "24mm wide aerial establishing shot"
 *
 * focusNodeId: the SceneNode the camera is primarily directed at (nullable).
 *
 * D6 extension point: depthOfField, focusMode, cameraHeight, stabilization
 * can be added here when the PromptCompiler requires them — no interface change needed.
 */
final class CameraConfiguration
{
    public function __construct(
        public readonly ShotType       $shotType,
        public readonly CameraAngle    $angle,
        public readonly CameraMovement $movement,
        public readonly LensType       $lens,
        public readonly ?string        $focusNodeId = null,
    ) {}
}

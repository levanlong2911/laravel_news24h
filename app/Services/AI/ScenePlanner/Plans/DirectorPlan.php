<?php

namespace App\Services\AI\ScenePlanner\Plans;

use App\Services\AI\SceneGraph\Enums\Pacing;

/**
 * Typed result from DirectorPlanner::plan().
 *
 * Contains editorial and camera-setup decisions:
 * pacing, framing intent, height, priority, stabilization, focus.
 *
 * motionBlur is stored as a string label ('natural', 'minimal', 'high') here
 * because DirectorPlanner emits labels. DirectorResolver normalizes to float.
 */
final class DirectorPlan
{
    public function __construct(
        public readonly Pacing $pacing,
        public readonly string $framing,
        public readonly string $cameraHeight,
        public readonly string $shotPriority,
        public readonly string $acceleration,
        public readonly string $stabilization,
        /** DirectorPlanner motion_blur label: 'natural', 'minimal', 'high' */
        public readonly string $motionBlurLabel,
        public readonly string $composition,
        public readonly bool   $rackFocus,
        /** Formatted lens string with mm suffix (e.g. "50mm") from DirectorPlanner::CAM_LENS */
        public readonly string $lensMm,
    ) {}

    public static function fromArray(array $data): self
    {
        return new self(
            pacing:          Pacing::fromString($data['pacing']          ?? 'medium'),
            framing:         $data['framing']        ?? 'standard',
            cameraHeight:    $data['camera_height']  ?? 'eye-level',
            shotPriority:    $data['shot_priority']  ?? 'subject',
            acceleration:    $data['acceleration']   ?? 'linear',
            stabilization:   $data['stabilization']  ?? 'steady',
            motionBlurLabel: (string) ($data['motion_blur'] ?? 'natural'),
            composition:     $data['composition']    ?? '',
            rackFocus:       (bool) ($data['rack_focus']   ?? false),
            lensMm:          $data['lens']           ?? '50mm',
        );
    }

    public function toArray(): array
    {
        return [
            'pacing'        => $this->pacing->value,
            'framing'       => $this->framing,
            'camera_height' => $this->cameraHeight,
            'shot_priority' => $this->shotPriority,
            'acceleration'  => $this->acceleration,
            'stabilization' => $this->stabilization,
            'motion_blur'   => $this->motionBlurLabel,
            'composition'   => $this->composition,
            'rack_focus'    => $this->rackFocus,
            'lens'          => $this->lensMm,
        ];
    }
}

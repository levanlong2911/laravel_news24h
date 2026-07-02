<?php

namespace App\Services\AI\SceneGraph\Nodes;

use App\Services\AI\SceneGraph\Enums\Emotion;

/**
 * A single point on the emotion curve across the shot's timeline.
 *
 * time: 0.0 (shot start) → 1.0 (shot end)
 * intensity: 0.0 (calm/flat) → 1.0 (peak emotional moment)
 *
 * Sprint 5 (Emotional Engine) generates this from action phases + emotion code.
 * Full curve fitting (Bezier, peak detection) is Sprint 6.
 */
final class EmotionPoint
{
    public function __construct(
        /** Normalized position in clip: 0.0–1.0 */
        public readonly float   $time,
        /** Emotion at this point */
        public readonly Emotion $emotion,
        /** Intensity at this point: 0.0–1.0 */
        public readonly float   $intensity,
    ) {}

    public static function from(array $data): self
    {
        return new self(
            time:      min(1.0, max(0.0, (float) ($data['time']      ?? 0.0))),
            emotion:   Emotion::fromDsl($data['emotion'] ?? 'CRAFT'),
            intensity: min(1.0, max(0.0, (float) ($data['intensity'] ?? 0.5))),
        );
    }

    public function toArray(): array
    {
        return [
            'time'      => $this->time,
            'emotion'   => $this->emotion->label(),
            'intensity' => $this->intensity,
        ];
    }
}

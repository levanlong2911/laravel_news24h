<?php

namespace App\Services\AI\ScenePlanner\Plans;

/**
 * Typed result from EyeGuidancePlanner::plan().
 *
 * Per-beat eye anchor chain — the deliberate sequence of WHERE the viewer
 * looks across the cinematic arc. This is the "Hollywood secret": the
 * director controls the eye before controlling the camera.
 *
 * Chain example for athletic_action:
 *   hook → eyes (emotion before action)
 *   escalation → hands (power loading)
 *   reveal → release_point (decisive contact)
 *   payoff → environmental_scale (world context)
 */
final class EyeGuidancePlan
{
    public function __construct(
        /** [{beat, eye_anchor, instruction}] */
        public readonly array $beats,
    ) {}

    public static function fromArray(array $data): self
    {
        return new self(beats: $data['beats'] ?? []);
    }

    public function toArray(): array
    {
        return ['beats' => $this->beats];
    }

    public function anchorFor(string $beatName): array
    {
        foreach ($this->beats as $b) {
            if ($b['beat'] === $beatName) {
                return $b;
            }
        }
        return [];
    }

    public function isEmpty(): bool { return $this->beats === []; }
}

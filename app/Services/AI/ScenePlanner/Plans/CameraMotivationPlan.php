<?php

namespace App\Services\AI\ScenePlanner\Plans;

/**
 * Typed result from CameraMotivationPlanner::plan().
 *
 * Per-beat camera motivation — the WHY behind each camera move.
 *
 * "Camera pushes in" is a description.
 * "Camera pushes in to isolate the athlete's resolve" is a director's intention.
 *
 * Kling renders the INTENTION, not the description. Motivation phrases are embedded
 * into the BeatFusionEngine camera sentence as purpose clauses, telling the model
 * not just what the camera does but what it is trying to achieve.
 */
final class CameraMotivationPlan
{
    public function __construct(
        /** [{beat, motivation}] */
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

    public function motivationFor(string $beatName): string
    {
        foreach ($this->beats as $b) {
            if ($b['beat'] === $beatName) {
                return $b['motivation'] ?? '';
            }
        }
        return '';
    }

    public function isEmpty(): bool { return $this->beats === []; }
}

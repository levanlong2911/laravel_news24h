<?php

namespace App\Services\AI\ScenePlanner\Plans;

/**
 * Typed result from EmotionArcPlanner::plan().
 *
 * The emotional journey across the cinematic arc — one emotional state per beat.
 * Every other layer (camera, light, depth, rhythm) should serve the emotional arc,
 * not the reverse.
 *
 * States progress from setup through build to climax to resolution:
 *   aerial:   wonder → recognition → declaration → awe
 *   athletic: tension → anticipation → release → awe
 *   nature:   mystery → recognition → wonder → awe
 *   product:  intrigue → recognition → satisfaction → desire
 */
final class EmotionArcPlan
{
    public function __construct(
        /** [{beat, state, signature}] */
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

    public function stateFor(string $beatName): string
    {
        foreach ($this->beats as $b) {
            if ($b['beat'] === $beatName) {
                return $b['state'] ?? '';
            }
        }
        return '';
    }

    public function signatureFor(string $beatName): string
    {
        foreach ($this->beats as $b) {
            if ($b['beat'] === $beatName) {
                return $b['signature'] ?? '';
            }
        }
        return '';
    }

    public function isEmpty(): bool { return $this->beats === []; }
}

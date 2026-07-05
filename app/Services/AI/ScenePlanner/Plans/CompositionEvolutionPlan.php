<?php

namespace App\Services\AI\ScenePlanner\Plans;

/**
 * Typed result from CompositionEvolutionPlanner::plan().
 *
 * Per-beat depth field evolution: foreground/midground/background layers change
 * across the cinematic arc, creating the sense of "camera moving into a world"
 * rather than just flying through space.
 */
final class CompositionEvolutionPlan
{
    public function __construct(
        /** [{beat, foreground, midground, background}] */
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

    public function compositionFor(string $beatName): array
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

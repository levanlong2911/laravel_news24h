<?php

namespace App\Services\AI\ScenePlanner\Plans;

/**
 * Typed result from PhysicsPlanner::plan().
 *
 * Four semantic layers of secondary motion:
 *   atmosphere   — weather and air effects
 *   interaction  — physical contact between subject and environment
 *   background   — crowd and background element behaviour
 *   microMotion  — subtle body effects from weather
 *   material     — material-specific physics (Sprint 7 Physics Engine — stub for now)
 */
final class PhysicsPlan
{
    public function __construct(
        /** @var string[] */
        public readonly array $atmosphere,
        /** @var string[] */
        public readonly array $interaction,
        /** @var string[] */
        public readonly array $background,
        /** @var string[] */
        public readonly array $microMotion,
        /** @var string[] — material interaction phrases (helmet metal, cloth stretch, grass wet) */
        public readonly array $material,
    ) {}

    public static function fromArray(array $data): self
    {
        return new self(
            atmosphere:  $data['atmosphere']   ?? [],
            interaction: $data['interaction']  ?? [],
            background:  $data['background']   ?? [],
            microMotion: $data['micro_motion'] ?? [],
            material:    $data['material']     ?? [],
        );
    }

    public function toArray(): array
    {
        return [
            'atmosphere'  => $this->atmosphere,
            'interaction' => $this->interaction,
            'background'  => $this->background,
            'micro_motion'=> $this->microMotion,
            'material'    => $this->material,
        ];
    }

    public function isEmpty(): bool
    {
        return empty($this->atmosphere)
            && empty($this->interaction)
            && empty($this->background)
            && empty($this->microMotion);
    }
}

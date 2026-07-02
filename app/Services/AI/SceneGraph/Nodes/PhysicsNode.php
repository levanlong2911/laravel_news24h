<?php

namespace App\Services\AI\SceneGraph\Nodes;

/**
 * Resolved secondary motion physics for a single shot.
 *
 * Four semantic layers, each describing a different class of physical effect:
 *
 *   atmosphere[]   — weather/air effects (snow, rain streaks, heat shimmer)
 *   interaction[]  — physical contact between subject and environment
 *   background[]   — crowd and background element behaviour (driven by emotion)
 *   microMotion[]  — subtle body effects from weather (breath vapor, soaked fabric)
 *   material[]     — material-specific surface effects stub (Sprint 7)
 *
 * Builder merges PhysicsPlanner output + trigger additions here before
 * handing to the Renderer. Renderer never calls PhysicsPlanner directly.
 */
final class PhysicsNode
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
        /**
         * Material surface physics: fabric ripple, leather sheen, metal glint.
         * Sprint 7 stub — populated by MaterialPhysicsPlanner.
         *
         * @var string[]
         */
        public readonly array $material,
    ) {}

    public static function from(array $data): self
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

    /** True when all layers are empty (indoor shot with no physics effects). */
    public function isEmpty(): bool
    {
        return empty($this->atmosphere)
            && empty($this->interaction)
            && empty($this->background)
            && empty($this->microMotion)
            && empty($this->material);
    }
}

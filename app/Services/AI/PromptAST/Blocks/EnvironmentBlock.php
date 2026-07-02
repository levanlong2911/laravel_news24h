<?php

namespace App\Services\AI\PromptAST\Blocks;

/**
 * Physical environment + physics layers block.
 *
 * Merges EnvironmentNode (global scene conditions) and PhysicsNode (secondary
 * motion layers) into one presentation-ready block. PromptNormalizer dedupes
 * physics phrases before the Serializer renders them.
 *
 * The separation of concerns:
 *   environment.*  — conditions that define the scene (weather, palette, time)
 *   physics.*      — things that physically move in that environment
 */
final class EnvironmentBlock
{
    public function __construct(
        // ── EnvironmentNode fields ──────────────────────────────────────────
        /** Human-readable weather: snow, clear sunny, golden hour, rainy */
        public readonly string $weather,
        public readonly string $weatherDesc,
        /** Time of day: golden hour, afternoon, night, daytime, twilight */
        public readonly string $time,
        /** Dominant palette: warm amber, cool neon, high contrast */
        public readonly string $palette,
        public readonly string $fieldCondition,
        public readonly string $crowdDensity,

        // ── PhysicsNode layers ──────────────────────────────────────────────
        /** @var string[] Weather/air effects */
        public readonly array $atmosphere,
        /** @var string[] Physical contact between subject and environment */
        public readonly array $interaction,
        /** @var string[] Crowd and background element behaviour */
        public readonly array $background,
        /** @var string[] Subtle body effects from weather */
        public readonly array $microMotion,
        /** @var string[] Material surface physics (Sprint 7 stub) */
        public readonly array $material,
    ) {}

    public function hasPhysics(): bool
    {
        return !empty($this->atmosphere)
            || !empty($this->interaction)
            || !empty($this->background)
            || !empty($this->microMotion);
    }
}

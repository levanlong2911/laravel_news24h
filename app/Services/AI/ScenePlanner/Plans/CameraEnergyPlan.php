<?php

namespace App\Services\AI\ScenePlanner\Plans;

/**
 * Typed result from CameraEnergyPlanner::plan().
 *
 * Holds velocity-enhanced beats and the overall velocity curve.
 * energyBeats replaces the raw beats from CinematicBeatPlan — each beat's
 * camera field now includes a kinetic phrase prefix.
 */
final class CameraEnergyPlan
{
    /**
     * @param array  $energyBeats   [{beat, start, end, camera (enhanced), subject, intensity, velocity_pct, energy_phrase}]
     * @param string $profile       Profile key used (aerial_awe, athletic, product, …)
     * @param array  $velocityCurve [int, …] velocity_pct per beat in order
     */
    public function __construct(
        public readonly array  $energyBeats,
        public readonly string $profile,
        public readonly array  $velocityCurve,
    ) {}

    public static function fromArray(array $data): self
    {
        return new self(
            energyBeats:   $data['energy_beats']   ?? [],
            profile:       $data['profile']        ?? 'generic',
            velocityCurve: $data['velocity_curve'] ?? [],
        );
    }

    public function toArray(): array
    {
        return [
            'energy_beats'   => $this->energyBeats,
            'profile'        => $this->profile,
            'velocity_curve' => $this->velocityCurve,
        ];
    }

    /**
     * Returns energy-enhanced beats as timeline segments for KlingRenderer.
     * velocity_token MUST be passed through so KlingRenderer can prepend
     * the model-specific motion phrase in buildTimelineBlock().
     */
    public function toTimeline(): array
    {
        return array_map(fn(array $b) => [
            'start'          => $b['start'],
            'end'            => $b['end'],
            'camera'         => $b['camera'],
            'subject'        => $b['subject'],
            'velocity_token' => $b['velocity_token'] ?? '',
            'beat'           => $b['beat'] ?? '',
        ], $this->energyBeats);
    }

    public function isEmpty(): bool
    {
        return $this->energyBeats === [];
    }
}

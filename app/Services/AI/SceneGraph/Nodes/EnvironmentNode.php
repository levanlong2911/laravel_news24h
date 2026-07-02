<?php

namespace App\Services\AI\SceneGraph\Nodes;

/**
 * Resolved scene environment conditions for a single shot.
 *
 * Produced by SceneGraphBuilder from ContinuityPlanner's environment block.
 * These conditions must remain consistent across all shots in a scene:
 * the Builder enforces this by locking them from shot 1 (via ContinuityNode).
 */
final class EnvironmentNode
{
    public function __construct(
        /** Human-readable weather name: snow, clear sunny, golden hour, rainy, … */
        public readonly string $weather,
        /** Full atmospheric phrase from PhysicsPlanner atmosphere[0] */
        public readonly string $weatherDesc,
        /** Time of day: golden hour, afternoon, night, daytime, twilight, … */
        public readonly string $time,
        /** Dominant colour palette: warm amber, cool neon, high contrast, … */
        public readonly string $palette,
        /** Playing surface condition: frozen, wet, dry, indoor, normal */
        public readonly string $fieldCondition,
        /** Crowd density: packed, seated */
        public readonly string $crowdDensity,
    ) {}

    public static function from(array $data): self
    {
        return new self(
            weather:        $data['weather']         ?? 'clear',
            weatherDesc:    $data['weather_desc']    ?? '',
            time:           $data['time']            ?? '',
            palette:        $data['palette']         ?? '',
            fieldCondition: $data['field_condition'] ?? 'normal',
            crowdDensity:   $data['crowd_density']   ?? 'seated',
        );
    }

    public function toArray(): array
    {
        return [
            'weather'         => $this->weather,
            'weather_desc'    => $this->weatherDesc,
            'time'            => $this->time,
            'palette'         => $this->palette,
            'field_condition' => $this->fieldCondition,
            'crowd_density'   => $this->crowdDensity,
        ];
    }
}

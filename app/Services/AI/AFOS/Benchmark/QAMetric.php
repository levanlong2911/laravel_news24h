<?php

namespace App\Services\AI\AFOS\Benchmark;

/**
 * QAMetric — a single machine-readable QA check for Vision QA pipeline.
 *
 * YAML format (all fields inline):
 *   - {id: water_surface_coverage, type: ratio, min: 0.5}
 *   - {id: reflection_visible, type: boolean, expected: true}
 *   - {id: lighting_quality, type: enum, expected: golden_hour}
 *
 * Types:
 *   boolean — expected: true|false
 *   enum    — expected: string enum value (lighting_quality, camera_height, etc.)
 *   ratio   — min/max: float 0.0–1.0 fraction of frame
 *   score   — min/max: float 0.0–1.0 quality metric (focus_sharpness, etc.)
 *
 * Vision QA Engine evaluates each metric independently via its id.
 * No switch-case needed — the engine has a registry keyed by metric id.
 */
final class QAMetric
{
    public function __construct(
        public readonly string  $id,
        public readonly string  $type,
        public readonly mixed   $expected = null,
        public readonly ?float  $min = null,
        public readonly ?float  $max = null,
    ) {}

    public static function fromArray(array $data): self
    {
        return new self(
            id:       $data['id'],
            type:     $data['type'],
            expected: $data['expected'] ?? null,
            min:      isset($data['min']) ? (float) $data['min'] : null,
            max:      isset($data['max']) ? (float) $data['max'] : null,
        );
    }

    public function toArray(): array
    {
        return array_filter([
            'id'       => $this->id,
            'type'     => $this->type,
            'expected' => $this->expected,
            'min'      => $this->min,
            'max'      => $this->max,
        ], fn($v) => $v !== null);
    }

    /** Human-readable description for CLI display. */
    public function describe(): string
    {
        return match ($this->type) {
            'boolean' => "{$this->id}: " . ($this->expected ? 'must be present' : 'must be absent'),
            'enum'    => "{$this->id}: must be {$this->expected}",
            'ratio'   => "{$this->id}: " . ($this->min !== null && $this->max !== null
                ? "{$this->min}–{$this->max}"
                : ($this->min !== null ? "≥{$this->min}" : "≤{$this->max}")),
            'score'   => "{$this->id}: " . ($this->min !== null ? "≥{$this->min}" : "≤{$this->max}"),
            default   => "{$this->id}: {$this->type}",
        };
    }
}

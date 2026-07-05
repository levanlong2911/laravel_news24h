<?php

namespace App\Services\AI\AFOS\Passes;

/**
 * PassParameterSchema — typed descriptor for a single tunable pass parameter.
 *
 * Exposes the search space to Experience Engine:
 *   - valid range (min / max)
 *   - type for optimizer sampling ('float' | 'int' | 'bool')
 *   - default so a fresh pass is valid without configuration
 *   - description so audit logs are human-readable
 *
 * Passes expose their schema via parameterSchema(): PassParameterSchema[].
 * Experience Engine reads this before proposing a parameter update — it cannot
 * propose a value outside [min, max] or of the wrong type.
 *
 * Phase A: schema is logged only.
 * Phase B: Experience Engine searches within schema bounds.
 */
final class PassParameterSchema
{
    public function __construct(
        public readonly string $name,
        public readonly string $type,        // 'float' | 'int' | 'bool'
        public readonly float  $min,
        public readonly float  $max,
        public readonly float  $default,
        public readonly string $description,
    ) {}

    public function toArray(): array
    {
        return [
            'name'        => $this->name,
            'type'        => $this->type,
            'min'         => $this->min,
            'max'         => $this->max,
            'default'     => $this->default,
            'description' => $this->description,
        ];
    }
}

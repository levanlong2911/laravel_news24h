<?php

namespace App\Services\AI\AFOS\Ir\Temporal\Validation;

final class LayerConflictError extends ValidationError
{
    public function __construct(
        public readonly string $eventIdA,
        public readonly string $eventIdB,
        public readonly string $layer,
        public readonly float  $overlapStart,
        public readonly float  $overlapEnd,
    ) {}

    public function message(): string
    {
        $start = number_format($this->overlapStart, 2);
        $end   = number_format($this->overlapEnd,   2);
        return "Layer conflict in '{$this->layer}': '{$this->eventIdA}' and '{$this->eventIdB}'"
            . " overlap [{$start}–{$end}s]";
    }

    /**
     * Layer overlaps are warnings — some layers intentionally allow overlap
     * (camera, environment). Round 9 will add LayerPolicy to configure this.
     */
    public function severity(): ValidationSeverity
    {
        return ValidationSeverity::Warning;
    }
}

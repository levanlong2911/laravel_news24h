<?php

namespace App\Video\Concept;

final class DesignDecision
{
    public function __construct(
        public readonly string $aspect,
        public readonly Provenance $provenance,
        public readonly string $decision,
    ) {}

    /** @return array{aspect: string, provenance: string, decision: string} */
    public function toArray(): array
    {
        return [
            'aspect' => $this->aspect,
            'provenance' => $this->provenance->value,
            'decision' => $this->decision,
        ];
    }
}

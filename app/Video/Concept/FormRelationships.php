<?php

namespace App\Video\Concept;

final class FormRelationships
{
    public function __construct(
        public readonly string $governingLine,
        public readonly string $massingRhythm,
        public readonly string $featureIntegration,
    ) {}

    /** @return array{governing_line: string, massing_rhythm: string, feature_integration: string} */
    public function toArray(): array
    {
        return [
            'governing_line' => $this->governingLine,
            'massing_rhythm' => $this->massingRhythm,
            'feature_integration' => $this->featureIntegration,
        ];
    }
}

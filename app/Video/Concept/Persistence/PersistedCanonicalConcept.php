<?php

declare(strict_types=1);

namespace App\Video\Concept\Persistence;

use App\Video\Concept\Freeze\FrozenCanonicalConcept;
use App\Video\Concept\Persistence\Models\CanonicalConceptRevision;

final class PersistedCanonicalConcept
{
    public function __construct(
        public readonly CanonicalConceptRevision $revision,
        public readonly FrozenCanonicalConcept $frozen,
        public readonly bool $reused,
    ) {}
}

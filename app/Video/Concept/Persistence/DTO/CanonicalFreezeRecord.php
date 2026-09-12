<?php

declare(strict_types=1);

namespace App\Video\Concept\Persistence\DTO;

use App\Video\Concept\Freeze\FrozenCanonicalConcept;

final class CanonicalFreezeRecord
{
    public function __construct(
        public readonly FrozenCanonicalConcept $frozen,
        public readonly bool $reused,
    ) {}
}

<?php

declare(strict_types=1);

namespace App\Services\AI\FilmOS\Intent;

use App\Services\AI\FilmOS\Planning\VisualStrategy;

final class ExecutionContext
{
    public function __construct(
        public readonly array         $mustShow,
        public readonly array         $mustAvoid,
        public readonly NarrativeBeat $beat,
        public readonly array         $beatFactIds,
        public readonly VisualStrategy $visualStrategy,
        public readonly array         $styleRule,       // ['lens' => 85, 'stability' => 'LOCKED', ...]
        public readonly array         $softConstraints,
        public readonly float         $sourceConfidence,
    ) {}
}

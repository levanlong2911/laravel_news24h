<?php

declare(strict_types=1);

namespace App\Services\AI\FilmOS\Intent;

use App\Services\AI\FilmOS\Kernel\ShotPriority;

final class EvaluationContext
{
    public function __construct(
        public readonly ShotPriority $priority,
        public readonly float        $acceptanceThreshold,
        public readonly bool         $requiresFactVeto,
        public readonly array        $requiredFactIds,
    ) {}
}

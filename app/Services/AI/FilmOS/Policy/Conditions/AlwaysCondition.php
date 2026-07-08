<?php

declare(strict_types=1);

namespace App\Services\AI\FilmOS\Policy\Conditions;

use App\Services\AI\FilmOS\Policy\PolicyCondition;
use App\Services\AI\FilmOS\Policy\PolicyContext;

/** Always true — use as a fallback / default policy condition. */
final class AlwaysCondition implements PolicyCondition
{
    public function evaluate(PolicyContext $context): bool
    {
        return true;
    }

    public function describe(): string
    {
        return 'always';
    }
}

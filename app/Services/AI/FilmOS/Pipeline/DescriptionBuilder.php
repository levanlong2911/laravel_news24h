<?php

declare(strict_types=1);

namespace App\Services\AI\FilmOS\Pipeline;

use App\Services\AI\FilmOS\Intent\DirectorIntent;

/**
 * Builds the human-readable shot description from a DirectorIntent.
 *
 * Lives here so that replacing mustShow[] with WorldModel in C.8 touches only
 * this class, not DirectorIntentToPlanningIR.
 *
 * Delete in C.8 when planning pipeline produces PlanningIR natively.
 */
final class DescriptionBuilder
{
    public function build(DirectorIntent $intent): string
    {
        return implode(', ', $intent->execution->mustShow);
    }
}

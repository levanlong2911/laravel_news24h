<?php

declare(strict_types=1);

namespace App\Services\AI\FilmOS\Narrative\Production;

/**
 * One typed force working against the objective.
 * "pocket collapsing" (PHYSICAL) and "3 seconds left" (TIME) are different
 * kinds of pressure and will earn different staging responses.
 *
 * Immutable.
 */
final class Conflict
{
    public function __construct(
        public readonly string       $description,
        public readonly ConflictType $type,
    ) {}
}

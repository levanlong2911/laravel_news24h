<?php

declare(strict_types=1);

namespace App\Services\AI\FilmOS\Narrative\Production;

/**
 * The forces working against the objective — one objective, many conflicts,
 * each TYPED (see ConflictType) so learning can respond per kind of pressure.
 *
 * Immutable.
 */
final class ConflictPlan
{
    /** @param Conflict[] $conflicts */
    public function __construct(
        public readonly array $conflicts = [],
    ) {}

    /** @return Conflict[] only the conflicts of one kind */
    public function ofType(ConflictType $type): array
    {
        return array_values(array_filter(
            $this->conflicts,
            static fn(Conflict $c) => $c->type === $type,
        ));
    }
}

<?php

declare(strict_types=1);

namespace App\Services\AI\FilmOS\Narrative\Production;

/**
 * A staging rule the whole piece must respect, structured as
 * TARGET + RULE + MODE so adapters compose vendor language from semantics:
 *
 *   target: "football",  rule: "visible",                 mode: ALWAYS
 *   target: "crowd",     rule: "blocking the quarterback", mode: NEVER
 *   target: "receiver",  rule: "shown before the throw",   mode: NEVER
 *
 * Semantic INTENT, not prompt wording — adapters translate NEVER into each
 * vendor's negative-prompt syntax and ALWAYS into positive reinforcement.
 *
 * Immutable.
 */
final class VisualConstraint
{
    public function __construct(
        public readonly string         $target,
        public readonly string         $rule,
        public readonly ConstraintMode $mode,
    ) {}
}

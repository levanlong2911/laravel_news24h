<?php

declare(strict_types=1);

namespace App\Services\AI\FilmOS\Prompting\Plan;

/**
 * One decided piece of content: what it is, how much it matters, where it goes.
 *
 * $payload is the TYPED value object for the slot (PlanSlot documents the
 * contract). It is never rendered language — if a string of English ever ends up
 * here, the planner has started doing the renderer's job and the boundary is gone.
 * The one string payload, ACTION, is authored scenario data passed through, not
 * wording the planner invented.
 *
 * Immutable.
 */
final class PlanItem
{
    public function __construct(
        public readonly PlanSlot       $slot,
        public readonly PlanImportance $importance,
        /** Sequence within its section. Dramaturgy, not triage — see PlanImportance. */
        public readonly int            $order,
        public readonly mixed          $payload,
    ) {}
}

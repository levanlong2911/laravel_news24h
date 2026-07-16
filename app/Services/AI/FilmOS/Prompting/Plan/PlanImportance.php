<?php

declare(strict_types=1);

namespace App\Services\AI\FilmOS\Prompting\Plan;

/**
 * How much a plan item matters — the ONLY thing a renderer needs in order to
 * decide what to drop when it runs out of room.
 *
 * Deliberately separate from PlanItem::$order: one number cannot both sequence
 * content and express what is expendable. Ordering is dramaturgy; importance is
 * triage. Conflating them makes every later change a renumbering exercise.
 *
 * The planner assigns this WITHOUT knowing any vendor's budget — it only says
 * what would hurt least to lose.
 */
enum PlanImportance: string
{
    /** Losing this breaks the shot: the look, the subject, what happens, the payoff. */
    case CRITICAL  = 'critical';

    /** Real cinematic value, but the shot still reads without it: camera, emotion, staging. */
    case IMPORTANT = 'important';

    /** Enrichment. First to go: motifs, background, supporting subjects, extra facts. */
    case OPTIONAL  = 'optional';

    /** Triage order — CRITICAL is emitted first and dropped last. */
    public function rank(): int
    {
        return match ($this) {
            self::CRITICAL  => 3,
            self::IMPORTANT => 2,
            self::OPTIONAL  => 1,
        };
    }
}

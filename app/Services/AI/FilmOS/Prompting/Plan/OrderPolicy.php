<?php

declare(strict_types=1);

namespace App\Services\AI\FilmOS\Prompting\Plan;

/**
 * The sequence content appears in — dramaturgy, not triage.
 *
 * Kept apart from ImportancePolicy on purpose. Order answers "what comes first";
 * importance answers "what would we lose". One integer doing both jobs turns
 * every later change into a renumbering exercise, and quietly couples the shape
 * of the prompt to what is expendable in it.
 *
 * Order also decides fairness inside a tier: the reducer walks a tier in this
 * order across the WHOLE prompt, so when the budget runs out it drops the same
 * slot from every beat rather than starving whichever beat came last.
 *
 * Stateless — a table.
 */
final class OrderPolicy
{
    /** @var array<string, int> keyed by PlanSlot value */
    private const TABLE = [
        // Global: the look, then who, then where, then guidance.
        'visual_style'       => 10,
        'subject_primary'    => 20,
        'subject_secondary'  => 21,
        'subject_background' => 22,
        'anatomy'            => 25,
        'environment'        => 30,
        'motif_primary'      => 40,
        'motif_secondary'    => 41,
        'conflict'           => 49,
        'key_visual'         => 50,

        // Per beat: frame it, populate it, aim it, then play it.
        'camera'             => 10,
        'in_frame'           => 20,
        'action'             => 40,
        'emotion'            => 50,
        'performance_cue'    => 60,
        'motion'             => 70,
        'ending_frame'       => 80,

        // Ending and constraints.
        'hero_moment'        => 10,
        'constraint_always'  => 10,
        'constraint_never'   => 20,
    ];

    public function for(PlanSlot $slot): int
    {
        // Unknown content sorts last rather than silently jumping the queue.
        return self::TABLE[$slot->value] ?? 999;
    }
}

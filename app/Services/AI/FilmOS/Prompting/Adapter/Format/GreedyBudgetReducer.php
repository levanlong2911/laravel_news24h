<?php

declare(strict_types=1);

namespace App\Services\AI\FilmOS\Prompting\Adapter\Format;

use App\Services\AI\FilmOS\Prompting\Plan\PlanImportance;

/**
 * Keeps everything CRITICAL, then keeps taking while there is room.
 *
 * Two rules, both inherited from the plan:
 *
 *  - CRITICAL is never dropped, even past the budget. A prompt missing what
 *    actually happens is not a shorter prompt, it is a broken one; going over is
 *    the lesser failure, and the planner is what keeps CRITICAL small.
 *
 *  - Inside a tier, ORDER decides across the whole prompt — not the order
 *    fragments happen to arrive. Otherwise the first beat spends the budget and
 *    the last starves: the payoff loses its focus while an earlier beat keeps a
 *    motion word. Ordering by slot drops the same least-important thing from
 *    every beat at once, which is what makes the loss fair rather than arbitrary.
 *
 * Survivors come back in their original order — pruning is this stage's job,
 * sequencing is not.
 */
final class GreedyBudgetReducer implements BudgetReducer
{
    public function reduce(array $fragments, Budget $budget): array
    {
        $kept  = [];
        $words = 0;

        foreach ([PlanImportance::CRITICAL, PlanImportance::IMPORTANT, PlanImportance::OPTIONAL] as $tier) {
            $tierFragments = array_values(array_filter(
                $fragments,
                static fn(FormattedFragment $f): bool => $f->importance === $tier,
            ));
            usort(
                $tierFragments,
                static fn(FormattedFragment $a, FormattedFragment $b): int => $a->order <=> $b->order,
            );

            foreach ($tierFragments as $fragment) {
                $cost = $fragment->words();
                if ($tier !== PlanImportance::CRITICAL && $words + $cost > $budget->maxWords) {
                    continue;
                }
                $kept[spl_object_id($fragment)] = true;
                $words += $cost;
            }
        }

        return array_values(array_filter(
            $fragments,
            static fn(FormattedFragment $f): bool => isset($kept[spl_object_id($f)]),
        ));
    }
}

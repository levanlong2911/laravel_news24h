<?php

declare(strict_types=1);

namespace App\Services\AI\FilmOS\Prompting\Adapter\Format;

/**
 * Decides how much of an already-worded prompt survives.
 *
 * A pipeline stage, not a polymorphism hook: it exists because "measure the real
 * words, then prune" is a distinct step, the same way a compiler has an optimizer
 * even when it ships exactly one. It must run AFTER formatting — a word cost only
 * exists once something has been worded — and BEFORE assembly, so blocks are
 * built from what actually survived.
 *
 * It knows nothing semantic: it reads importance and order, both of which the
 * planner already decided, and never re-judges them.
 */
interface BudgetReducer
{
    /**
     * @param FormattedFragment[] $fragments
     * @return FormattedFragment[] survivors, in their original order
     */
    public function reduce(array $fragments, Budget $budget): array;
}

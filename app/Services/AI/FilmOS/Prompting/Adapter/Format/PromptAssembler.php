<?php

declare(strict_types=1);

namespace App\Services\AI\FilmOS\Prompting\Adapter\Format;

/**
 * Turns surviving fragments into the final prompt text.
 *
 * The last stage, and the dumbest: it groups fragments under their block heading
 * and joins them. It never drops, never reorders, never words anything — those
 * were the planner's, the reducer's and the formatter's jobs, and doing any of
 * them again here is how a renderer starts growing logic back.
 *
 * Blocks appear in first-appearance order, so sequence comes from how the plan
 * was walked rather than from a second ordering rule that could disagree with it.
 */
final class PromptAssembler
{
    /** @param FormattedFragment[] $fragments already reduced, in order */
    public function assemble(array $fragments): string
    {
        $blocks = [];
        foreach ($fragments as $fragment) {
            if (trim($fragment->text) === '') {
                continue;
            }
            $blocks[$fragment->block][] = $fragment->text;
        }

        $rendered = [];
        foreach ($blocks as $heading => $lines) {
            // '' is the opener — the medium itself, which carries no heading.
            $rendered[] = $heading === ''
                ? implode("\n", $lines)
                : $heading . "\n" . implode("\n", $lines);
        }

        return implode("\n\n", $rendered);
    }
}

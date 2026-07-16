<?php

declare(strict_types=1);

namespace App\Services\AI\FilmOS\Prompting\Adapter\Format;

use App\Services\AI\FilmOS\Prompting\Plan\PlanImportance;

/**
 * One line of vendor-worded prompt, still carrying the plan's verdict on it.
 *
 * This is the currency between the three vendor stages: a formatter makes them,
 * a reducer keeps or drops them, an assembler groups them. It exists because a
 * word cost only exists AFTER wording — the planner knows a CameraConfiguration,
 * but only Kling knows it costs four words as "Close-up, 85mm, handheld."
 *
 * $block is the vendor's own section heading ("SUBJECTS", "HOOK"), so boilerplate
 * needs no special case: it is simply a CRITICAL fragment like any other, and
 * therefore counted against the budget but never dropped.
 *
 * Immutable.
 */
final class FormattedFragment
{
    public function __construct(
        /** Vendor section heading; '' places the line above every block. */
        public readonly string         $block,
        public readonly PlanImportance $importance,
        /** Triage order within an importance tier — inherited from the plan. */
        public readonly int            $order,
        public readonly string         $text,
    ) {}

    public function words(): int
    {
        return str_word_count($this->text);
    }
}

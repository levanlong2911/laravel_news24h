<?php

declare(strict_types=1);

namespace App\Services\AI\FilmOS\Prompting\Adapter\Format;

use App\Services\AI\FilmOS\Prompting\Plan\PlanSlot;

/**
 * Resolves the formatter for a slot, so a renderer never grows a match() over
 * every kind of content that exists. Adding knowledge becomes: new PlanSlot,
 * new formatter, one register() — and no existing formatter is touched.
 *
 * A slot with no formatter is silence, not a crash: a vendor that cannot say
 * something simply does not say it, and the prompt is still valid.
 */
final class FormatterRegistry
{
    /** @var array<string, SlotFormatter> keyed by PlanSlot value */
    private array $formatters = [];

    /** @param SlotFormatter[] $formatters */
    public function __construct(array $formatters = [])
    {
        foreach ($formatters as $formatter) {
            $this->register($formatter);
        }
    }

    public function register(SlotFormatter $formatter): void
    {
        foreach ($formatter->slots() as $slot) {
            $this->formatters[$slot->value] = $formatter;
        }
    }

    public function for(PlanSlot $slot): ?SlotFormatter
    {
        return $this->formatters[$slot->value] ?? null;
    }
}

<?php

declare(strict_types=1);

namespace App\Services\AI\FilmOS\Prompting\Adapter\Format\Kling;

use App\Services\AI\FilmOS\Prompting\Adapter\Format\SlotFormatter;
use App\Services\AI\FilmOS\Prompting\Plan\PlanSlot;

/**
 * The director's layer: the payoff frame, the recurring images, and the
 * enrichment that only speaks when there is room for it.
 */
final class KlingProductionFormatter implements SlotFormatter
{
    use JoinsPhrases;

    public function slots(): array
    {
        return [
            PlanSlot::HERO_MOMENT,
            PlanSlot::MOTIF_PRIMARY,
            PlanSlot::MOTIF_SECONDARY,
            PlanSlot::KEY_VISUAL,
            PlanSlot::CONFLICT,
        ];
    }

    public function format(PlanSlot $slot, mixed $payload): string
    {
        return match ($slot) {
            // The hero moment is a held frame, so it is asked for as one.
            PlanSlot::HERO_MOMENT     => "Freeze the frame, everything goes still.\n"
                                         . $this->sentence($payload->description),
            PlanSlot::MOTIF_PRIMARY   => 'Primary motif: ' . $this->labels($payload) . '.',
            PlanSlot::MOTIF_SECONDARY => 'Secondary: ' . $this->labels($payload) . '.',
            PlanSlot::KEY_VISUAL      => $this->sentence($payload->hint),
            PlanSlot::CONFLICT        => $this->sentence($payload->description),
            default                   => '',
        };
    }

    private function labels(array $motifs): string
    {
        return $this->join(array_map(static fn($m): string => $m->label, $motifs));
    }
}

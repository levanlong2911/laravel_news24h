<?php

declare(strict_types=1);

namespace App\Services\AI\FilmOS\Benchmark\Selection;

use App\Services\AI\FilmOS\Selection\Origin;
use App\Services\AI\FilmOS\Selection\ShotTruth;

/**
 * The numbers, with nothing decided about them.
 *
 * Coverage is measured against SELECTABLE facts, never all facts: a fact with no
 * visual hint should go unused, and counting it would report a starvation that is
 * actually correct behaviour.
 */
final class SelectionReport
{
    /**
     * @param array<string, int> $usageByFact  factId => how many beats used it
     * @param FocusComparison[] $focus
     * @param array<string, int> $originCounts
     * @param array<string, ShotTruth> $beats
     */
    public function __construct(
        public readonly string $modelId,
        public readonly int $totalFacts,
        public readonly int $selectable,
        public readonly array $usageByFact,
        public readonly array $focus,
        public readonly array $originCounts,
        public readonly array $beats,
    ) {}

    public function used(): int
    {
        return count(array_filter($this->usageByFact, static fn (int $n): bool => $n > 0));
    }

    /** used / selectable — never used / total. */
    public function coverage(): float
    {
        return $this->selectable === 0 ? 0.0 : $this->used() / $this->selectable;
    }

    public function focusMatches(): int
    {
        return count(array_filter($this->focus, static fn (FocusComparison $c): bool => $c->matches()));
    }

    /** @return string[] facts spoken in every single beat — the repetition Coverage would fix. */
    public function repeatedEverywhere(): array
    {
        $beats = count($this->beats);
        if ($beats === 0) {
            return [];
        }
        return array_keys(array_filter($this->usageByFact, static fn (int $n): bool => $n === $beats));
    }

    /** @return string[] selectable facts the film never showed. */
    public function starved(): array
    {
        return array_keys(array_filter($this->usageByFact, static fn (int $n): bool => $n === 0));
    }

    public function originShare(Origin $origin): float
    {
        $total = array_sum($this->originCounts);
        return $total === 0 ? 0.0 : ($this->originCounts[$origin->value] ?? 0) / $total;
    }
}

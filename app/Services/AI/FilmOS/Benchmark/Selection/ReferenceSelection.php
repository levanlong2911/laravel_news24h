<?php

declare(strict_types=1);

namespace App\Services\AI\FilmOS\Benchmark\Selection;

/**
 * What the scenario's author chose to look at, per beat.
 *
 * Called REFERENCE, never "the answer". It is one benchmark author's choice, and
 * this whole ADR exists on the premise that policies get verified — including the
 * ones made of a person. A disagreement between policy and reference is data: the
 * policy may be wrong, or the author may be. Calling it "the answer" would decide
 * that question before reading it.
 *
 * Nothing may hand this to a SelectionPolicy. It exists to score a prediction that
 * was made without it.
 */
final class ReferenceSelection
{
    /** @param array<string, string> $focusByBeat beat => entity id */
    private function __construct(public readonly array $focusByBeat) {}

    public static function from(ScenarioSelectionSource $source): self
    {
        $nodeToEntity = $source->nodeToEntity();
        $focus        = [];

        foreach ($source->shots() as $beat => $shot) {
            $node = $shot['camera']['focus_node'] ?? null;
            if ($node === null) {
                continue;
            }
            $focus[(string) $beat] = $nodeToEntity[(string) $node] ?? (string) $node;
        }

        return new self($focus);
    }

    public function focusFor(string $beat): ?string
    {
        return $this->focusByBeat[$beat] ?? null;
    }
}

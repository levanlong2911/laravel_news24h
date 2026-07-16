<?php

declare(strict_types=1);

namespace App\Services\AI\FilmOS\Prompting\IR;

use App\Services\AI\FilmOS\Narrative\Production\Conflict;
use App\Services\AI\FilmOS\Narrative\Production\HeroMoment;

/**
 * Prompt IR for a whole production — per-shot prompts plus production-level
 * staging knowledge that applies across shots.
 *
 * Production-level fields (motifs, constraints, hero moment, conflicts) are
 * SEMANTIC copies from ProductionView: adapters repeat PRIMARY motifs harder,
 * translate NEVER constraints into each vendor's negative-prompt syntax,
 * and know which shot is the hero moment. The IR itself never phrases anything.
 *
 * Keyed by ordinal (shot identity, D1 invariant): gaps are legal — shots
 * excluded by the compiler's blocking gate simply have no entry.
 *
 * Immutable.
 */
final class StructuredPrompt
{
    /**
     * @param array<int, ShotPrompt>                                         $shots       keyed by shot ordinal
     * @param SubjectDescriptor[]                                            $subjects    primary-first, then first-appearance order
     * @param \App\Services\AI\FilmOS\Narrative\Production\VisualMotif[]     $motifs
     * @param \App\Services\AI\FilmOS\Narrative\Production\VisualConstraint[] $constraints
     * @param Conflict[]                                                     $conflicts   typed forces working against the objective
     * @param KeyVisual[]                                                    $keyVisuals  article-derived visual details, ranked by relevance
     */
    public function __construct(
        private readonly array       $shots = [],
        private readonly array       $subjects = [],
        private readonly array       $motifs = [],
        private readonly array       $constraints = [],
        private readonly ?HeroMoment $heroMoment = null,
        private readonly array        $conflicts = [],
        private readonly array        $keyVisuals = [],
        private readonly ?VisualStyle $visualStyle = null,
    ) {}

    /** @return SubjectDescriptor[] deduped by world-object id; primary first, then first appearance */
    public function subjects(): array
    {
        return $this->subjects;
    }

    /** @return array<int, ShotPrompt> keyed by shot ordinal */
    public function shots(): array
    {
        return $this->shots;
    }

    public function shotAt(int $ordinal): ?ShotPrompt
    {
        return $this->shots[$ordinal] ?? null;
    }

    public function isEmpty(): bool
    {
        return $this->shots === [];
    }

    /** @return \App\Services\AI\FilmOS\Narrative\Production\VisualMotif[] */
    public function motifs(): array
    {
        return $this->motifs;
    }

    /** @return \App\Services\AI\FilmOS\Narrative\Production\VisualConstraint[] */
    public function constraints(): array
    {
        return $this->constraints;
    }

    public function heroMoment(): ?HeroMoment
    {
        return $this->heroMoment;
    }

    /** @return Conflict[] typed forces working against the objective (semantic, unranked) */
    public function conflicts(): array
    {
        return $this->conflicts;
    }

    /** @return KeyVisual[] article-derived visual details, already ranked most-relevant first */
    public function keyVisuals(): array
    {
        return $this->keyVisuals;
    }

    /** The authored look for this piece; null = let the adapter use its default. */
    public function visualStyle(): ?VisualStyle
    {
        return $this->visualStyle;
    }
}

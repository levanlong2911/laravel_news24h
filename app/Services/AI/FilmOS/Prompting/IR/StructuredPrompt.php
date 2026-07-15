<?php

declare(strict_types=1);

namespace App\Services\AI\FilmOS\Prompting\IR;

use App\Services\AI\FilmOS\Narrative\Production\DirectorIntent;
use App\Services\AI\FilmOS\Narrative\Production\HeroMoment;

/**
 * Prompt IR for a whole production — per-shot prompts plus production-level
 * staging knowledge that applies across shots.
 *
 * Production-level fields (intent, motifs, constraints, hero moment) are
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
     * @param \App\Services\AI\FilmOS\Narrative\Production\VisualMotif[]     $motifs
     * @param \App\Services\AI\FilmOS\Narrative\Production\VisualConstraint[] $constraints
     */
    public function __construct(
        private readonly array           $shots = [],
        private readonly ?DirectorIntent $directorIntent = null,
        private readonly array           $motifs = [],
        private readonly array           $constraints = [],
        private readonly ?HeroMoment     $heroMoment = null,
    ) {}

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

    public function directorIntent(): ?DirectorIntent
    {
        return $this->directorIntent;
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
}

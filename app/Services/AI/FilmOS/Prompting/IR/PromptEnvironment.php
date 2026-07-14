<?php

declare(strict_types=1);

namespace App\Services\AI\FilmOS\Prompting\IR;

/**
 * Environment intent for a shot — the compiler's DOMAIN-DECOUPLING boundary.
 *
 * The compiler flattens World knowledge (WorldFact) into plain detail strings
 * here, so renderer adapters never import or understand the World domain.
 *
 *   WorldView → NarrativePromptCompiler → PromptEnvironment → Adapter
 *
 * INVARIANT (frozen 2026-07-13): "Prompt IR is semantic, never stylistic."
 * 'weather' => 'cold' is semantic organization — compiler territory.
 * "cold breath vapor visible on each exhale" is stylistic rendering —
 * adapter territory, always. Keys stay in the map because they ARE the
 * semantics: a bare 'cold' loses what the detail refers to.
 *
 * Immutable.
 */
final class PromptEnvironment
{
    /** @param array<string, string> $details factKey => value, e.g. ['weather' => 'cold', 'crowd' => 'roaring'] */
    public function __construct(
        public readonly array $details = [],
    ) {}
}

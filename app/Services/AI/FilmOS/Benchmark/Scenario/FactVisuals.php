<?php

declare(strict_types=1);

namespace App\Services\AI\FilmOS\Benchmark\Scenario;

use App\Services\AI\FilmOS\Prompting\IR\KeyVisual;
use App\Services\AI\FilmOS\Prompting\IR\VisualRelevance;

/**
 * Maps a scenario's raw facts[] (article-extracted) into the neutral KeyVisual[]
 * the prompt compiler consumes — the wire that carries article visual truth
 * (facts[].visual_hint + visual_relevance) into the prompt.
 *
 * Only facts that carry a visual_hint become key visuals; a fact with no visual
 * manifestation (pure narrative) contributes nothing to the frame. Kept out of
 * ScenarioDocument so the document stays a raw, validated data holder.
 */
final class FactVisuals
{
    /**
     * @param array<int, mixed> $facts raw fact arrays from ScenarioDocument::$facts
     * @return KeyVisual[]
     */
    public static function fromFacts(array $facts): array
    {
        $visuals = [];
        foreach ($facts as $fact) {
            if (!is_array($fact)) {
                continue;
            }
            $hint = $fact['visual_hint'] ?? null;
            if (!is_string($hint) || $hint === '') {
                continue;   // no visual manifestation → not a key visual
            }
            $relevance = VisualRelevance::tryFrom((string) ($fact['visual_relevance'] ?? ''))
                ?? VisualRelevance::MEDIUM;
            $visuals[] = new KeyVisual($hint, $relevance);
        }
        return $visuals;
    }
}

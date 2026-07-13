<?php

declare(strict_types=1);

namespace App\Services\AI\FilmOS\Snapshot;

use App\Services\AI\FilmOS\Intent\DirectorIntent;

/**
 * Produces a canonical hash of the intent layer (PromptHash).
 *
 * Hashes WHAT the director decided — not how the prompt string was rendered.
 * Fields hashed from ExecutionContext:
 *   shotId, beat, beatFactIds (sorted), mustShow (sorted), mustAvoid (sorted),
 *   visualStrategy, styleRule (ksorted), softConstraints (ksorted)
 *
 * Excluded (non-deterministic or runtime-only):
 *   sourceConfidence — float derived from LLM, not stable across replays
 *
 * Stable across prompt template refactors. Changes only when directorial
 * intent itself changes (different shot, different facts, different constraints).
 *
 * Sort flags: SORT_STRING used for all list sorts — locale-independent, explicit.
 * HashSerializer is injected so encoding flags match across all hash builders.
 */
final class PromptHashBuilder
{
    public function __construct(
        private readonly HashSerializer $serializer = new JsonHashSerializer(),
    ) {}

    /**
     * @param  array<string, DirectorIntent> $intents  subGoalId → DirectorIntent
     */
    public function build(array $intents): string
    {
        ksort($intents);
        $canonical = [];

        foreach ($intents as $id => $intent) {
            $mustShow       = $intent->execution->mustShow;
            $mustAvoid      = $intent->execution->mustAvoid;
            $beatFactIds    = $intent->execution->beatFactIds;
            $styleRule      = $intent->execution->styleRule;
            $softConstraints = $intent->execution->softConstraints;

            sort($mustShow,    SORT_STRING);
            sort($mustAvoid,   SORT_STRING);
            sort($beatFactIds, SORT_STRING);
            $styleRule       = CanonicalArray::deepSort($styleRule);
            $softConstraints = CanonicalArray::deepSort($softConstraints);

            $canonical[$id] = [
                'shotId'          => $intent->shotId,
                'beat'            => $intent->execution->beat->value,
                'beatFactIds'     => $beatFactIds,
                'mustShow'        => $mustShow,
                'mustAvoid'       => $mustAvoid,
                'visualStrategy'  => $intent->execution->visualStrategy->value,
                'styleRule'       => $styleRule,
                'softConstraints' => $softConstraints,
            ];
        }

        return $this->serializer->sha256($canonical);
    }
}

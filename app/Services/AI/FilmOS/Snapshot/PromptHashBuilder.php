<?php

declare(strict_types=1);

namespace App\Services\AI\FilmOS\Snapshot;

use App\Services\AI\FilmOS\Intent\DirectorIntent;

/**
 * Produces a canonical hash of the intent layer (PromptHash).
 *
 * Hashes WHAT the director decided — not how the prompt string was rendered.
 * Fields hashed from ExecutionContext:
 *   shotId, beat, mustShow (sorted), mustAvoid (sorted), visualStrategy, styleRule (ksorted)
 *
 * Stable across prompt template refactors. Changes only when directorial
 * intent itself changes (different shot, different visual strategy, etc.).
 */
final class PromptHashBuilder
{
    /**
     * @param  array<string, DirectorIntent> $intents  subGoalId → DirectorIntent
     */
    public function build(array $intents): string
    {
        ksort($intents);
        $canonical = [];

        foreach ($intents as $id => $intent) {
            $mustShow  = $intent->execution->mustShow;
            $mustAvoid = $intent->execution->mustAvoid;
            $styleRule = $intent->execution->styleRule;
            sort($mustShow);
            sort($mustAvoid);
            ksort($styleRule);

            $canonical[$id] = [
                'shotId'         => $intent->shotId,
                'beat'           => $intent->execution->beat->value,
                'mustShow'       => $mustShow,
                'mustAvoid'      => $mustAvoid,
                'visualStrategy' => $intent->execution->visualStrategy->value,
                'styleRule'      => $styleRule,
            ];
        }

        return hash('sha256', json_encode($canonical, JSON_THROW_ON_ERROR));
    }
}

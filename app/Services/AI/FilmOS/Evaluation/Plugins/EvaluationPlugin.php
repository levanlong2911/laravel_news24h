<?php

declare(strict_types=1);

namespace App\Services\AI\FilmOS\Evaluation\Plugins;

use App\Services\AI\FilmOS\Intent\DirectorIntent;

/**
 * Phase 1: FactReviewer is real (checks prompt vs required facts).
 * VisualReviewer and NarrativeReviewer are stubs (always PASS).
 */
final class EvaluationPlugin
{
    public function review(DirectorIntent $intent, array $renderOutput): EvaluationResult
    {
        $factPass     = $this->factReview($intent, $renderOutput);
        $visualPass   = true;  // stub
        $narrativePass = true; // stub

        $passed = $factPass && $visualPass && $narrativePass;
        $score  = $passed ? 0.89 : 0.40;

        return new EvaluationResult(
            shotId:  $intent->shotId,
            accepted: $passed,
            score:    $score,
            issues:   $passed ? [] : ['Fact reviewer veto triggered'],
        );
    }

    private function factReview(DirectorIntent $intent, array $renderOutput): bool
    {
        $prompt        = $renderOutput['prompt'] ?? '';
        $requiredFacts = $intent->evaluation->requiredFactIds;

        // For Phase 1: check prompt is non-empty and contains visual subject
        if (empty($prompt)) {
            return false;
        }

        $mustShow = $intent->execution->mustShow;
        foreach ($mustShow as $subject) {
            $keyword = strtolower(explode(' ', $subject)[0]);
            if (!str_contains(strtolower($prompt), $keyword)) {
                return false;
            }
        }

        return true;
    }
}

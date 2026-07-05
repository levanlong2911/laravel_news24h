<?php

namespace App\Services\AI\AFOS\Backends;

use App\Services\AI\AFOS\Ir\PromptIR;

/**
 * KlingBackend — pure serializer: PromptIR → Kling-compatible string prompt.
 *
 * AFOS Principle 7: Backend reads PromptIR only. It never touches CameraIR,
 * CompositionIR, Intent, or DirectorProfile. All clause logic lives in
 * KlingPromptPlanningPass, not here.
 *
 * serialize() joins the six pre-built clauses with a single space, filtering
 * any empty clauses. That is the only logic in this class.
 *
 * Swapping to a different output format (Veo JSON, Runway text) means writing
 * a new Backend with a different serialize() — the clauses in PromptIR are
 * backend-agnostic and do not change.
 */
final class KlingBackend
{
    public function serialize(PromptIR $prompt): string
    {
        return implode(' ', array_filter(array_map('trim', [
            $prompt->subjectClause,
            $prompt->atmosphereClause,
            $prompt->cameraClause,
            $prompt->compositionClause,
            $prompt->emotionalClose,
            $prompt->technicalSpec,
        ])));
    }
}

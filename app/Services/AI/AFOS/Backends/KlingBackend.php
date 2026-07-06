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
 * Two output modes:
 *   Temporal (when actionSection or cameraSection is set):
 *     Produces the structured SCENE / CAMERA / ACTION / STYLE format.
 *     Time-coded beats and camera arcs replace the generic clauses.
 *
 *   Legacy (when no TemporalPlan was assembled):
 *     Joins the six pre-built clauses with a single space (backward-compatible).
 */
final class KlingBackend implements BackendInterface
{
    public function id(): string { return 'kling'; }

    public function serialize(PromptIR $prompt): string
    {
        if ($prompt->actionSection !== null || $prompt->cameraSection !== null) {
            return $this->serializeTemporal($prompt);
        }

        return $this->serializeLegacy($prompt);
    }

    private function serializeTemporal(PromptIR $prompt): string
    {
        $sections = [];

        // SCENE — atmosphere + subject context
        $scene = implode(' ', array_filter(array_map('trim', [
            $prompt->atmosphereClause,
            $prompt->subjectClause,
        ])));
        if ($scene !== '') {
            $sections[] = "SCENE\n{$scene}";
        }

        // CAMERA — time-coded arc if present, else choreography clause
        $camera = $prompt->cameraSection ?? $prompt->cameraClause;
        if ($camera !== null && $camera !== '') {
            $sections[] = "CAMERA\n{$camera}";
        }

        // ACTION — time-coded beats
        if ($prompt->actionSection !== null && $prompt->actionSection !== '') {
            $sections[] = "ACTION\n{$prompt->actionSection}";
        }

        // STYLE — composition + technical
        $style = implode(' ', array_filter(array_map('trim', [
            $prompt->compositionClause,
            $prompt->technicalSpec,
        ])));
        if ($style !== '') {
            $sections[] = "STYLE\n{$style}";
        }

        return implode("\n\n", $sections);
    }

    private function serializeLegacy(PromptIR $prompt): string
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

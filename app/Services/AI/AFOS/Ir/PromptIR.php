<?php

namespace App\Services\AI\AFOS\Ir;

/**
 * PromptIR — the fifth and final IR tier before Backend serialization.
 *
 * Pipeline position:
 *   CameraIR + CompositionIR + Intent
 *         ↓
 *   KlingPromptPlanningPass   (backend-specific)
 *         ↓
 *   PromptIR
 *         ↓
 *   KlingBackend.serialize()  (pure string concatenation — no logic)
 *
 * PromptIR separates "what to say" (planned by the pass) from "how to format it"
 * (serialized by the backend). Swapping backends (Kling → Veo → Runway) only
 * requires a new Backend.serialize() — the prompt clauses are backend-agnostic.
 *
 * Each clause is a complete English sentence or phrase. The backend decides
 * how to join them (space-separated for Kling; JSON fields for Veo, etc.).
 *
 * AFOS Principle 7: this is still an IR — KlingBackend reads PromptIR, never
 * reaches back to CameraIR or CompositionIR.
 */
final class PromptIR
{
    public function __construct(
        public readonly string $shotId,
        /** "The [entity] [framing verb], [depth layers]." */
        public readonly string $subjectClause,
        /** Lighting + mood + negative-space note. */
        public readonly string $atmosphereClause,
        /** Camera choreography: movement, height, lens, DOF. */
        public readonly string $cameraClause,
        /** Composition rule + eye-flow direction. */
        public readonly string $compositionClause,
        /** Emotional resonance close. */
        public readonly string $emotionalClose,
        /** Technical quality tag + lens spec + tempo. */
        public readonly string $technicalSpec,
    ) {}

    public function toArray(): array
    {
        return [
            'shotId'            => $this->shotId,
            'subjectClause'     => $this->subjectClause,
            'atmosphereClause'  => $this->atmosphereClause,
            'cameraClause'      => $this->cameraClause,
            'compositionClause' => $this->compositionClause,
            'emotionalClose'    => $this->emotionalClose,
            'technicalSpec'     => $this->technicalSpec,
        ];
    }

    public static function fromArray(array $data): self
    {
        return new self(
            shotId:            $data['shotId']            ?? '',
            subjectClause:     $data['subjectClause']     ?? '',
            atmosphereClause:  $data['atmosphereClause']  ?? '',
            cameraClause:      $data['cameraClause']      ?? '',
            compositionClause: $data['compositionClause'] ?? '',
            emotionalClose:    $data['emotionalClose']    ?? '',
            technicalSpec:     $data['technicalSpec']     ?? '',
        );
    }
}

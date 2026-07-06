<?php

namespace App\Services\AI\AFOS\Backends;

use App\Services\AI\AFOS\Ir\PromptIR;

/**
 * BackendInterface — contract for all AFOS backend serializers.
 *
 * LLVM analogue: TargetMachine.
 * Each backend knows how to serialize a PromptIR into its own wire format.
 * Zero knowledge of PipelineState, stages, or compiler lifecycle.
 *
 * Registered implementations:
 *   KlingBackend  — Kling video generation API (SCENE/CAMERA/ACTION/STYLE format)
 *   VeoBackend    — (future)
 *   SoraBackend   — (future)
 *   RunwayBackend — (future)
 */
interface BackendInterface
{
    /** Unique backend identifier — used as the key in BackendRegistry. */
    public function id(): string;

    /**
     * Serialize a PromptIR into the backend's wire format.
     * Pure function: same PromptIR → same string, no side effects.
     */
    public function serialize(PromptIR $prompt): string;
}

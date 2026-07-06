<?php

namespace App\Services\AI\AFOS\Ir;

/**
 * BackendInput — the typed input contract for the EMIT phase.
 *
 * Represents all data the backend serializer needs, and nothing more.
 * BackendStage maps PipelineState → BackendInput before delegating to emit().
 * emit() is then pure: zero knowledge of PipelineState or compiler lifecycle.
 *
 * LLVM analogue: a fully-lowered Module handed to a TargetMachine.
 *
 * Future fields (temperature, tokenBudget, locale, providerConfig) extend here
 * without touching BackendStage::run() or any upstream stage.
 */
final class BackendInput implements StageInput
{
    public function __construct(
        /** The fully-built prompt IR produced by Tier3Stage. */
        public readonly PromptIR $prompt,

        /** Backend identifier — determines which serializer is used. */
        public readonly string   $backendId,
    ) {}
}

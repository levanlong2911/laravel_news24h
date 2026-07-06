<?php

namespace App\Services\AI\AFOS\Backends;

use App\Services\AI\AFOS\Ir\BackendInput;

/**
 * BackendEmitter — dispatches a BackendInput to the correct backend serializer.
 *
 * LLVM analogue: TargetMachine::emit().
 * This is the single point that routes PromptIR → wire format via the registry.
 * BackendStage knows about BackendEmitter; it knows nothing about KlingBackend.
 *
 * Extension point: add a new backend by registering it in the BackendRegistry.
 * Zero changes to BackendEmitter, BackendStage, or the pipeline definition.
 *
 *   Pipeline:  BackendStage → BackendEmitter → BackendRegistry → KlingBackend
 *
 * Round 13 extension:
 *   $emitter = new BackendEmitter(BackendRegistry::withDefaults()->register(new VeoBackend()));
 */
final class BackendEmitter
{
    public function __construct(
        private readonly BackendRegistry $registry,
    ) {}

    /**
     * Emit the backend wire format for a given BackendInput.
     *
     * Looks up the backend by $input->backendId in the registry,
     * then calls serialize(). Throws if the backend is not registered.
     */
    public function emit(BackendInput $input): string
    {
        return $this->registry
            ->backend($input->backendId)
            ->serialize($input->prompt);
    }

    /** Default emitter with the standard backend registry (Kling). */
    public static function withDefaults(): self
    {
        return new self(BackendRegistry::withDefaults());
    }
}

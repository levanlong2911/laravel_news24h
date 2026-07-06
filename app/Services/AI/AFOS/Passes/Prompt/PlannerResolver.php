<?php

namespace App\Services\AI\AFOS\Passes\Prompt;

use App\Services\AI\AFOS\Ir\PromptIR;
use App\Services\AI\AFOS\Ir\PromptPlanningInput;

/**
 * PlannerResolver — dispatches a PromptPlanningInput to the correct planner.
 *
 * Symmetric with BackendEmitter (EMIT phase) — same dispatch-by-id pattern.
 * Tier3Stage knows BackendId (from PipelineState) but not which planner implements it.
 * PlannerResolver bridges: backendId → PlannerRegistry → PromptPlannerInterface → plan().
 *
 *   Pipeline:  Tier3Stage → PlannerResolver → PlannerRegistry → KlingPromptPlanningPass
 *
 * Extension: add a new planner by registering it in PlannerRegistry.
 * Zero changes to PlannerResolver, Tier3Stage, or the pipeline definition.
 *
 * Round 14 extension:
 *   $resolver = new PlannerResolver(PlannerRegistry::withDefaults()->register(new VeoPromptPlanner()));
 */
final class PlannerResolver
{
    public function __construct(
        private readonly PlannerRegistry $registry,
    ) {}

    /**
     * Plan a PromptIR for the given backendId.
     *
     * Looks up the planner for $backendId in the registry,
     * then calls plan(). Throws if no planner is registered.
     */
    public function plan(PromptPlanningInput $input, string $backendId): PromptIR
    {
        return $this->registry->planner($backendId)->plan($input);
    }

    /**
     * Return the human-readable name of the planner that would handle $backendId.
     * Used by Tier3Stage to record the planner name in trace output.
     */
    public function plannerName(string $backendId): string
    {
        return $this->registry->planner($backendId)->name();
    }

    /** Default resolver with the standard planner registry (Kling). */
    public static function withDefaults(): self
    {
        return new self(PlannerRegistry::withDefaults());
    }
}

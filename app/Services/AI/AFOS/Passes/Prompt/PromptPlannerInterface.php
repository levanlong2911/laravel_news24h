<?php

namespace App\Services\AI\AFOS\Passes\Prompt;

use App\Services\AI\AFOS\Ir\PromptIR;
use App\Services\AI\AFOS\Ir\PromptPlanningInput;

/**
 * PromptPlannerInterface — contract for backend-specific prompt planners.
 *
 * LLVM analogue: TargetMachine for the LOWER phase.
 * Each implementation knows how to turn typed IR (CameraIR + CompositionIR + Intent)
 * into a PromptIR structured for its specific backend's vocabulary and syntax.
 *
 * The planner does NOT know its own backendId at call time — routing is handled by
 * PlannerRegistry. The planner only knows: "given these inputs, produce this output."
 *
 * Symmetric with BackendInterface (EMIT phase):
 *   BackendInterface::serialize(PromptIR)           → wire format string
 *   PromptPlannerInterface::plan(PromptPlanningInput) → PromptIR
 *
 * Registered implementations:
 *   KlingPromptPlanningPass — Kling video generation vocab + clause structure
 *   VeoPromptPlanner        — (future)
 *   RunwayPromptPlanner     — (future)
 */
interface PromptPlannerInterface
{
    /** Backend ID this planner targets — used as the key in PlannerRegistry. */
    public function backendId(): string;

    /** Human-readable name for trace records and diagnostics. */
    public function name(): string;

    /**
     * Transform planning inputs into a structured PromptIR.
     * Pure function: same PromptPlanningInput → same PromptIR, no side effects.
     */
    public function plan(PromptPlanningInput $input): PromptIR;
}

<?php

namespace App\Services\AI\AFOS\Ir;

/**
 * StageInput — marker interface for all typed stage input DTOs.
 *
 * Establishes the hierarchy before the count of DTOs grows:
 *
 *   StageInput
 *   ├── BackendInput        (EMIT phase: PromptIR + backendId)
 *   ├── PromptPlanningInput (LOWER phase: CameraIR + CompositionIR + Intent + Temporal)
 *   ├── MotionInput         (BUILD phase: future)
 *   └── …
 *
 * Implementing this interface has no runtime cost — it is purely a type signal
 * that enables tools, linters, and future generic infrastructure (StageAdapter<TInput>)
 * to operate on stage inputs uniformly.
 */
interface StageInput {}

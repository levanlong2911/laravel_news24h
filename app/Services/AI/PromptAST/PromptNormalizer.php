<?php

namespace App\Services\AI\PromptAST;

use App\Services\AI\PromptAST\Blocks\EnvironmentBlock;

/**
 * Compiler optimization pass on PromptAST.
 *
 * Analogous to LLVM optimization passes — takes a PromptAST, applies
 * normalization rules, returns a semantically equivalent but cleaner PromptAST.
 *
 * Current passes:
 *   1. Physics phrase deduplication (removes exact duplicates per layer)
 *   2. Continuity null-out for first shot with empty identity
 *
 * All passes are pure: input PromptAST is not mutated; a new instance is returned.
 * Add new passes by adding private methods and calling them in normalize().
 */
final class PromptNormalizer
{
    public function normalize(PromptAST $ast): PromptAST
    {
        $ast = $this->dedupePhysics($ast);
        $ast = $this->suppressEmptyContinuity($ast);

        return $ast;
    }

    // ── Passes ──────────────────────────────────────────────────────────────

    /**
     * Pass 1: deduplicate physics phrases within each layer.
     *
     * PhysicsPlanner and trigger additions can produce identical phrases
     * (e.g., "rain streaks" added by both AtmospherePlanner and weather trigger).
     * Remove exact duplicates while preserving insertion order.
     */
    private function dedupePhysics(PromptAST $ast): PromptAST
    {
        $env = $ast->environment;
        $normalized = new EnvironmentBlock(
            weather:        $env->weather,
            weatherDesc:    $env->weatherDesc,
            time:           $env->time,
            palette:        $env->palette,
            fieldCondition: $env->fieldCondition,
            crowdDensity:   $env->crowdDensity,
            atmosphere:     $this->dedupe($env->atmosphere),
            interaction:    $this->dedupe($env->interaction),
            background:     $this->dedupe($env->background),
            microMotion:    $this->dedupe($env->microMotion),
            material:       $this->dedupe($env->material),
        );

        return $ast->withEnvironment($normalized);
    }

    /**
     * Pass 2: suppress ContinuityBlock when it carries no meaningful data.
     *
     * A first-shot ContinuityBlock with an empty identity and no previousState
     * would produce an empty CONTINUITY section — suppress it.
     */
    private function suppressEmptyContinuity(PromptAST $ast): PromptAST
    {
        if ($ast->continuity === null) {
            return $ast;
        }

        $cont = $ast->continuity;
        if ($cont->identity->isEmpty() && $cont->previousState === null) {
            return $ast->withContinuity(null);
        }

        return $ast;
    }

    // ── Helpers ─────────────────────────────────────────────────────────────

    /** Remove exact-duplicate phrases; preserve insertion order. */
    private function dedupe(array $phrases): array
    {
        return array_values(array_unique($phrases));
    }
}

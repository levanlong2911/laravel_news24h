<?php

namespace App\Services\AI\AFOS\Passes\Pipeline;

/**
 * CompilerPhase — the lifecycle stage a CompilerStage belongs to.
 *
 * Mirrors the pass pipeline model of LLVM/MLIR:
 *   BUILD    → stages that construct the IR graph (MotionBeatStage, CameraArcStage)
 *   FREEZE   → the barrier that seals the graph immutable (FreezeStage only)
 *   OPTIMIZE → passes that rewrite the frozen graph without changing semantics
 *   LOWER    → translation between IR levels (Tier1: ShotGoal→Composition, Tier3: Graph→PromptIR)
 *   EMIT     → final serialization / backend codegen (BackendStage)
 *   VALIDATE → read-only constraint checkers (ShotValidationStage, CameraValidationStage)
 *
 * The scheduler uses Phase to enforce ordering invariants:
 *   All BUILD stages must complete before FREEZE.
 *   No OPTIMIZE or EMIT stage may run before FREEZE.
 *   VALIDATE stages are read-only and may run at any point after their reads are available.
 */
enum CompilerPhase: string
{
    /** Constructs the mutable IR graph — writes tracks and edges to TemporalGraph. */
    case BUILD    = 'build';

    /**
     * Seals the mutable graph into a FrozenTemporalGraph.
     * Exactly one stage per pipeline run should carry this phase.
     * The scheduler must never reorder it relative to BUILD or OPTIMIZE stages.
     */
    case FREEZE   = 'freeze';

    /** Rewrites the frozen graph to reduce cost or improve quality. */
    case OPTIMIZE = 'optimize';

    /** Translates between IR levels — one IR type becomes a different IR type. */
    case LOWER    = 'lower';

    /** Final serialization: structured IR → backend string / binary. */
    case EMIT     = 'emit';

    /** Read-only validation — no IR produced, only diagnostics emitted. */
    case VALIDATE = 'validate';

    // ── Lifecycle helpers ─────────────────────────────────────────────────────

    private function order(): int
    {
        return match($this) {
            self::BUILD    => 0,
            self::FREEZE   => 1,
            self::OPTIMIZE => 2,
            self::LOWER    => 3,
            self::EMIT     => 4,
            self::VALIDATE => 5, // read-only; can depend on any earlier phase output
        };
    }

    /** True when this phase precedes $other in the compiler lifecycle. */
    public function isBefore(self $other): bool
    {
        return $this->order() < $other->order();
    }

    /** True when this phase follows $other in the compiler lifecycle. */
    public function isAfter(self $other): bool
    {
        return $this->order() > $other->order();
    }

    /**
     * True when a stage in this phase may legally consume output from $producer's phase.
     *
     * Rule: a stage may only read from phases whose boundary has already been crossed.
     * BUILD cannot read LOWER output (the graph hasn't been lowered yet).
     * VALIDATE (read-only, highest order) may depend on output from any phase.
     */
    public function canDependOn(self $producer): bool
    {
        return $producer->order() <= $this->order();
    }

    /**
     * Advance to $next, enforcing the legal transition table.
     *
     * Legal transitions:
     *   BUILD    → FREEZE
     *   FREEZE   → OPTIMIZE | LOWER
     *   OPTIMIZE → LOWER
     *   LOWER    → EMIT
     *   EMIT     → (terminal)
     *   VALIDATE → (read-only, no transitions)
     *
     * Throws \LogicException on any skip or backwards move.
     */
    public function transitionTo(self $next): self
    {
        $legal = match($this) {
            self::BUILD    => [self::FREEZE],
            self::FREEZE   => [self::OPTIMIZE, self::LOWER],
            self::OPTIMIZE => [self::LOWER],
            self::LOWER    => [self::EMIT],
            self::EMIT     => [],
            self::VALIDATE => [],
        };

        if (!in_array($next, $legal, strict: true)) {
            throw new \LogicException(sprintf(
                'Illegal phase transition: %s → %s. Legal next phases: [%s].',
                $this->value,
                $next->value,
                $legal === [] ? 'none' : implode(', ', array_map(fn(self $p) => $p->value, $legal)),
            ));
        }

        return $next;
    }
}

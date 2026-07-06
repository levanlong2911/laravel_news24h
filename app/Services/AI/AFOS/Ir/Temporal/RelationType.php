<?php

namespace App\Services\AI\AFOS\Ir\Temporal;

enum RelationType: string
{
    // ── Temporal constraints — affect scheduling and DAG validation ───────────
    /** B cannot start before A ends; strict scheduling dependency. */
    case Hard       = 'hard';
    /** B starts when/after A ends; soft temporal sequencing. */
    case Follows    = 'follows';
    /** B cuts A short; B.start < A.end is intentional, not a violation. */
    case Interrupts = 'interrupts';
    /** B starts before A ends; intentional co-occurrence. */
    case Overlaps   = 'overlaps';

    // ── Semantic constraints — inform serializer and explainability ───────────
    /** A provides foundation or stability for B; no timing constraint. */
    case Supports   = 'supports';
    /** B is a symmetrical/mirrored counterpart of A. */
    case Mirrors    = 'mirrors';
    /** A transitions smoothly into B without a hard boundary. */
    case BlendsInto = 'blends_into';

    /**
     * Temporal constraints — affect scheduling and DAG validation.
     * Validator checks ordering invariants only for these relation types.
     */
    public function isTemporalConstraint(): bool
    {
        return match ($this) {
            self::Hard, self::Follows, self::Interrupts, self::Overlaps => true,
            default => false,
        };
    }

    /**
     * Structural relations — the Optimizer must never rewrite these.
     * Hard defines scheduling order; Supports and Mirrors define semantic structure.
     * Temporal relations (Follows, BlendsInto, etc.) may be rewritten.
     */
    public function isStructural(): bool
    {
        return match ($this) {
            self::Hard, self::Supports, self::Mirrors => true,
            default => false,
        };
    }
}

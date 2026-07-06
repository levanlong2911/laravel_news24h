<?php

namespace App\Services\AI\AFOS\Ir\Temporal\Motion;

/**
 * MotionVerbRegistry — maps motion verbs to a canonical form and equivalence classes.
 *
 * Interface stub for Round 9. Each backend implements its own registry so the
 * serializer can translate verbs to backend-specific vocabulary without AFOS
 * being locked to any single prompt wording.
 *
 * Example:
 *   KlingVerbRegistry:  canonicalForm('twist') → 'rotate'  → Kling prompt: "rotating"
 *   VeoVerbRegistry:    canonicalForm('twist') → 'rotate'  → Veo prompt: "turns with rotation"
 *   NullMotionVerbRegistry: identity pass-through (no translation)
 *
 * The Optimizer (Round 9) uses isSubstitutable() to decide whether a low-confidence
 * beat can be safely replaced with an equivalent motion.
 */
interface MotionVerbRegistry
{
    /**
     * Returns the canonical (normalized) form of a verb.
     * Example: 'twist' → 'rotate', 'turn' → 'rotate', 'rotate' → 'rotate'
     */
    public function canonicalForm(string $verb): string;

    /**
     * Returns all verbs considered semantically equivalent to the given verb,
     * including the verb itself.
     * Example: equivalents('rotate') → ['rotate', 'twist', 'turn']
     *
     * @return string[]
     */
    public function equivalents(string $verb): array;

    /**
     * Returns true if $from can be safely replaced with $to without changing
     * the semantic intent of the motion (i.e. they are in the same equivalence class).
     */
    public function isSubstitutable(string $from, string $to): bool;

    /**
     * Returns a continuous similarity score between two verbs (0.0 = unrelated, 1.0 = identical).
     *
     * Non-binary counterpart to isSubstitutable(). Used by the Optimizer to rank
     * candidate replacements when a beat has low confidence — prefer the verb
     * with highest similarity to the original rather than a binary yes/no.
     *
     * Example:
     *   similarity('twist', 'rotate') → 0.9   (near-synonyms)
     *   similarity('stride', 'emerge') → 0.1  (unrelated)
     *   similarity('stride', 'stride') → 1.0  (identical)
     */
    public function similarity(string $from, string $to): float;
}

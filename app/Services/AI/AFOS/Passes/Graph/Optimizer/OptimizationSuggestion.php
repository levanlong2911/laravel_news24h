<?php

namespace App\Services\AI\AFOS\Passes\Graph\Optimizer;

use App\Services\AI\AFOS\Ir\Temporal\NodeRef;

/**
 * OptimizationSuggestion — pure immutable data describing a proposed graph change.
 *
 * Suggestions are data, not commands. They describe WHAT to change; SuggestionExecutor
 * decides HOW. This separation allows suggestions to be:
 *   - Serialized to JSON / persisted to DB
 *   - Sent over REST API to a benchmark dashboard
 *   - Stored in snapshot files for reproducibility
 *   - Applied or rejected independently by the executor
 *
 * Analogy: LLVM OptimizationRemark records what a pass noticed without rewriting IR.
 * The PassManager (SuggestionExecutor here) applies the actual transformation.
 *
 * Concrete implementations:
 *   ShiftEventSuggestion    — move an event by N seconds
 *   EdgeRewriteSuggestion   — change RelationType on an edge (e.g. Follows → BlendsInto)
 *   DeleteBeatSuggestion    — remove a low-confidence beat
 *   MergeBeatSuggestion     — fuse two adjacent beats on same actor/channel
 *   ReplaceVerbSuggestion   — substitute motion verb via MotionVerbRegistry
 */
interface OptimizationSuggestion
{
    /**
     * Stable string type tag — used by SuggestionExecutor's handler registry.
     * Must be unique per concrete class. Convention: snake_case.
     *
     * Examples: 'shift_event', 'edge_rewrite', 'delete_beat', 'merge_beat', 'replace_verb'
     */
    public function suggestionType(): string;

    /**
     * All graph nodes affected by this suggestion.
     * Used by the benchmark viewer to highlight relevant events.
     *
     * @return NodeRef[]
     */
    public function affectedNodes(): array;

    /**
     * Human-readable rationale for why this change is suggested.
     * Shown in benchmark UI and debug tooling.
     */
    public function rationale(): string;

    /**
     * Optimizer confidence in this suggestion (0.0 = speculative, 1.0 = certain).
     * SuggestionExecutor may filter by confidence before applying.
     */
    public function confidence(): float;
}

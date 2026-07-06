<?php

namespace App\Services\AI\AFOS\Passes\Graph\Optimizer;

use App\Services\AI\AFOS\Ir\Temporal\TemporalGraph;

/**
 * SuggestionExecutor — applies OptimizationSuggestion[] to a TemporalGraph.
 *
 * Dispatches each suggestion to the registered SuggestionHandler by type string.
 * No switch-case, no instanceof — adding a new suggestion type only requires
 * registering a handler. OCP fully satisfied.
 *
 * Design:
 *   - Immutable: register() returns a new executor with the handler added.
 *   - Stateless: applyAll() accumulates a new graph from left to right; the
 *     original graph is never mutated.
 *   - Filterable: applyAtOrAbove(float $minConfidence) skips low-confidence suggestions.
 *
 * Usage:
 *   $executor = SuggestionExecutor::defaults();
 *   $newGraph  = $executor->applyAll($suggestions, $graph);
 *
 * The GraphPass that owns optimization (Round 9) uses this to apply a batch
 * of suggestions from the GraphOptimizerPass into a transformed graph.
 */
final class SuggestionExecutor
{
    /** @param array<string, SuggestionHandler> $handlers suggestionType → handler */
    private function __construct(private readonly array $handlers = []) {}

    public static function empty(): self
    {
        return new self();
    }

    /** Pre-wired executor with all standard handlers. Populated in Phase 1 as handlers are created. */
    public static function defaults(): self
    {
        return new self();
    }

    // ── Registration ──────────────────────────────────────────────────────────

    /**
     * Register a handler for a suggestion type. Returns immutable clone.
     * Registering for an existing type replaces the previous handler.
     */
    public function register(SuggestionHandler $handler): self
    {
        $clone                              = clone $this;
        $clone->handlers[$handler->handles()] = $handler;
        return $clone;
    }

    // ── Execution ─────────────────────────────────────────────────────────────

    /**
     * Apply a single suggestion. Returns a new TemporalGraph.
     *
     * @throws \RuntimeException If no handler is registered for the suggestion type.
     */
    public function execute(OptimizationSuggestion $suggestion, TemporalGraph $graph): TemporalGraph
    {
        $type    = $suggestion->suggestionType();
        $handler = $this->handlers[$type]
            ?? throw new \RuntimeException(
                "SuggestionExecutor: no handler registered for suggestion type '{$type}'. "
                . "Register one via SuggestionExecutor::register()."
            );

        return $handler->handle($suggestion, $graph);
    }

    /**
     * Apply all suggestions in order. Each suggestion sees the result of the previous.
     * Suggestions without a handler throw — wrap in applyAtOrAbove() to skip unknowns.
     *
     * @param OptimizationSuggestion[] $suggestions
     */
    public function applyAll(array $suggestions, TemporalGraph $graph): TemporalGraph
    {
        foreach ($suggestions as $suggestion) {
            $graph = $this->execute($suggestion, $graph);
        }
        return $graph;
    }

    /**
     * Apply suggestions whose confidence meets the threshold, skip the rest.
     * Suggestions for unregistered types are silently skipped (not thrown).
     *
     * @param OptimizationSuggestion[] $suggestions
     */
    public function applyAtOrAbove(float $minConfidence, array $suggestions, TemporalGraph $graph): TemporalGraph
    {
        foreach ($suggestions as $suggestion) {
            if ($suggestion->confidence() < $minConfidence) {
                continue;
            }
            $type = $suggestion->suggestionType();
            if (!isset($this->handlers[$type])) {
                continue;
            }
            $graph = $this->handlers[$type]->handle($suggestion, $graph);
        }
        return $graph;
    }

    public function hasHandler(string $suggestionType): bool
    {
        return isset($this->handlers[$suggestionType]);
    }

    /** @return string[] All registered suggestion types. */
    public function registeredTypes(): array
    {
        return array_keys($this->handlers);
    }
}

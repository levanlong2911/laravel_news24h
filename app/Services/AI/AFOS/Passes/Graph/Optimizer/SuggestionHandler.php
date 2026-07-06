<?php

namespace App\Services\AI\AFOS\Passes\Graph\Optimizer;

use App\Services\AI\AFOS\Ir\Temporal\TemporalGraph;

/**
 * SuggestionHandler — applies one type of OptimizationSuggestion to a TemporalGraph.
 *
 * Each concrete suggestion type has a companion handler. SuggestionExecutor
 * maintains a registry: suggestionType → SuggestionHandler. No switch-case needed.
 *
 * Pattern: Registry Dispatch (not Visitor, not Command).
 *   SuggestionExecutor → looks up handler by suggestionType() → handler.handle()
 *
 * Adding a new suggestion type requires:
 *   1. New OptimizationSuggestion implementation (pure data)
 *   2. New SuggestionHandler implementation (behavior)
 *   3. Register handler in SuggestionExecutor
 *   No changes elsewhere — OCP satisfied.
 */
interface SuggestionHandler
{
    public function handle(OptimizationSuggestion $suggestion, TemporalGraph $graph): TemporalGraph;

    /** The suggestion type string this handler is registered for. */
    public function handles(): string;
}

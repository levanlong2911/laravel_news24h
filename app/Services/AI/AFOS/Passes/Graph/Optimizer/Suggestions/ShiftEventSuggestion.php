<?php

namespace App\Services\AI\AFOS\Passes\Graph\Optimizer\Suggestions;

use App\Services\AI\AFOS\Ir\Temporal\NodeRef;
use App\Services\AI\AFOS\Passes\Graph\Optimizer\OptimizationSuggestion;

/**
 * ShiftEventSuggestion — move an event forward or backward in time by $deltaSeconds.
 *
 * Emitted when the Optimizer detects a temporal overlap (Hard constraint violated,
 * or a Follows chain where B starts before A ends with a small gap).
 *
 * $deltaSeconds > 0 → shift later; < 0 → shift earlier.
 *
 * SuggestionExecutor hands this to ShiftEventHandler, which returns a new graph
 * with the event's startSec + endSec adjusted by $deltaSeconds.
 */
final class ShiftEventSuggestion implements OptimizationSuggestion
{
    public const TYPE = 'shift_event';

    public function __construct(
        public readonly NodeRef $node,
        public readonly float   $deltaSeconds,
        public readonly string  $rationale,
        public readonly float   $confidence = 1.0,
    ) {}

    public function suggestionType(): string { return self::TYPE; }

    /** @return NodeRef[] */
    public function affectedNodes(): array { return [$this->node]; }

    public function rationale(): string { return $this->rationale; }

    public function confidence(): float { return $this->confidence; }
}

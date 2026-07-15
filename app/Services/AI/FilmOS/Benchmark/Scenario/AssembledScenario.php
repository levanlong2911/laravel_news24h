<?php

declare(strict_types=1);

namespace App\Services\AI\FilmOS\Benchmark\Scenario;

use App\Services\AI\FilmOS\Narrative\Timeline\NarrativeState;
use App\Services\AI\FilmOS\Narrative\Timeline\SemanticTimeline;

/**
 * The result of assembling a scenario: both the timeline and the state it
 * projects to. QA needs the timeline (some rules read raw events, e.g.
 * duplicate introductions); consumers that only need the projection read
 * ->state.
 *
 * Immutable.
 */
final class AssembledScenario
{
    public function __construct(
        public readonly SemanticTimeline $timeline,
        public readonly NarrativeState   $state,
    ) {}
}

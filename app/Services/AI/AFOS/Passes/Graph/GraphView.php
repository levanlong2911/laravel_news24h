<?php

namespace App\Services\AI\AFOS\Passes\Graph;

use App\Services\AI\AFOS\Ir\Temporal\EdgeStore;
use App\Services\AI\AFOS\Ir\Temporal\TimelineEvent;

/**
 * GraphView — a filtered projection of a TemporalGraph for a specific consumer.
 *
 * Serializers should not depend on the full TemporalGraph. A Kling serializer
 * only needs Motion + Camera tracks; a Physics backend needs Physics + Motion.
 * GraphView provides a lightweight, track-filtered view without copying data.
 *
 * Usage (Round 9 implementation):
 *   $view = $graph->view(['motion', 'camera']);
 *   foreach ($view->events() as $event) { ... }
 *
 * Phase 2 (P2) will add fluent construction:
 *   $graph->view()->tracks('motion', 'camera')->between(0, 10)->build()
 *
 * GraphView is immutable and read-only — it holds references into the graph.
 */
interface GraphView
{
    /** @return TimelineEvent[] All visible events across selected tracks, ordered by startSec. */
    public function events(): array;

    /** @return EdgeStore Edges within and between selected tracks. */
    public function edges(): EdgeStore;

    /** @return string[] Track IDs visible in this view. */
    public function trackIds(): array;

    public function isEmpty(): bool;
}

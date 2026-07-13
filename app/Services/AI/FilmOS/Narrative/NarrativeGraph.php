<?php

declare(strict_types=1);

namespace App\Services\AI\FilmOS\Narrative;

use App\Services\AI\FilmOS\Graph\Graph;

/**
 * Ordered cinematic structure derived from a MeaningGraph.
 * Contains one NarrativeNode per active beat (hook→escalation→reveal→payoff).
 *
 * @extends Graph<NarrativeNode, never>
 */
final class NarrativeGraph extends Graph
{
    private const BEAT_ORDER = ['hook', 'escalation', 'reveal', 'payoff'];

    public function isEmpty(): bool
    {
        return $this->nodeCount() === 0;
    }

    /**
     * Returns NarrativeNodes in cinematic order.
     *
     * @return NarrativeNode[]
     */
    public function orderedBeats(): array
    {
        $byBeat = [];
        foreach ($this->nodes() as $node) {
            $byBeat[$node->beat] = $node;
        }

        $ordered = [];
        foreach (self::BEAT_ORDER as $beat) {
            if (isset($byBeat[$beat])) {
                $ordered[] = $byBeat[$beat];
            }
        }

        return $ordered;
    }
}

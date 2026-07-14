<?php

declare(strict_types=1);

namespace App\Services\AI\FilmOS\Narrative;

use App\Services\AI\FilmOS\Graph\Graph;
use App\Services\AI\FilmOS\Narrative\Story\StoryBeat;

/**
 * Ordered cinematic structure derived from a MeaningGraph.
 * Contains one NarrativeNode per active beat (hook→escalation→reveal→payoff).
 *
 * @extends Graph<NarrativeNode, never>
 */
final class NarrativeGraph extends Graph
{
    public function isEmpty(): bool
    {
        return $this->nodeCount() === 0;
    }

    /**
     * Returns NarrativeNodes in cinematic order (StoryBeat case declaration order).
     *
     * @return NarrativeNode[]
     */
    public function orderedBeats(): array
    {
        $byBeat = [];
        foreach ($this->nodes() as $node) {
            $byBeat[$node->beat->value] = $node;
        }

        $ordered = [];
        foreach (StoryBeat::cases() as $beat) {
            if (isset($byBeat[$beat->value])) {
                $ordered[] = $byBeat[$beat->value];
            }
        }

        return $ordered;
    }
}

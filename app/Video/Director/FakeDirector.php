<?php

namespace App\Video\Director;

use App\Video\Producer\ProducerOutput;
use App\Video\World\VerifiedWorldGraph;

/**
 * Output co dinh. Cho unit test va CI. 100% deterministic, khong mang, khong tien.
 */
final class FakeDirector implements DirectorInterface
{
    public function __construct(
        private readonly ActionSelection $selection,
    ) {}

    public function select(array $candidates, VerifiedWorldGraph $world, ?ProducerOutput $producer, int $sceneOrdinal = 1, int $totalScenes = 1, array $priorScenes = []): ActionSelection
    {
        if (($candidates['action_candidates'] ?? []) !== []) {
            return $this->selection;
        }

        return new ActionSelection(
            $this->selection->heroEntity,
            null,
            [],
            $this->selection->emotion,
            $this->selection->reveal,
            $this->selection->compositionNote,
            $this->selection->newInformation,
            $this->selection->featureCandidateIndices !== [] ? $this->selection->featureCandidateIndices : [0],
        );
    }
}

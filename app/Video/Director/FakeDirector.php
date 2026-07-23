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
    ) {
    }

    public function select(array $candidates, VerifiedWorldGraph $world, ?ProducerOutput $producer): ActionSelection
    {
        return $this->selection;
    }
}

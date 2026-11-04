<?php

namespace App\Services\Video;

use App\Models\VideoPlanningStage;

interface QueuedStageService
{
    /** @return array{0: ?VideoPlanningStage, 1: string} what the queued run became when a worker took it */
    public function execute(string $stageId, string $token): array;
}

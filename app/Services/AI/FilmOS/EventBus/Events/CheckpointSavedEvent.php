<?php

declare(strict_types=1);

namespace App\Services\AI\FilmOS\EventBus\Events;

use App\Services\AI\FilmOS\EventBus\AbstractFilmOSEvent;

final class CheckpointSavedEvent extends AbstractFilmOSEvent
{
    public function __construct(
        public readonly string $executionId,
        public readonly int    $checkpointSizeBytes,
        public readonly int    $completedNodeCount,
    ) {
        parent::__construct();
    }

    public function eventName(): string
    {
        return 'execution.checkpoint.saved';
    }

    public function payload(): array
    {
        return [
            'executionId'         => $this->executionId,
            'checkpointSizeBytes' => $this->checkpointSizeBytes,
            'completedNodeCount'  => $this->completedNodeCount,
        ];
    }
}

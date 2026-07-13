<?php

declare(strict_types=1);

namespace App\Services\AI\FilmOS\EventBus\Events;

use App\Services\AI\FilmOS\EventBus\AbstractFilmOSEvent;

final class ExecutionStartedEvent extends AbstractFilmOSEvent
{
    public function __construct(
        public readonly string $executionId,
        public readonly int    $nodeCount,
        public readonly bool   $resumedFromCheckpoint,
    ) {
        parent::__construct();
    }

    public function eventName(): string
    {
        return 'execution.started';
    }

    public function payload(): array
    {
        return [
            'executionId'           => $this->executionId,
            'nodeCount'             => $this->nodeCount,
            'resumedFromCheckpoint' => $this->resumedFromCheckpoint,
        ];
    }

    public function canonicalData(): array
    {
        return [
            'nodeCount'             => $this->nodeCount,
            'resumedFromCheckpoint' => $this->resumedFromCheckpoint,
            // excluded: executionId (run-instance identifier)
        ];
    }
}
